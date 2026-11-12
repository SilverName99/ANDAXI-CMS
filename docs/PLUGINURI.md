# Pluginurile și licența

Un site primește **nucleul** (pagini, meniu, galerie, SEO, emailuri, formular de
contact, administratori) și **pluginurile** din contractul lui. Ce pluginuri are
un site hotărăște doar CRM-ul, printr-o licență semnată; clientul nu poate porni
nimic și nu vede nimic despre pluginurile pe care nu le are.

## Unde stă fiecare lucru

| Fișier | Ce e |
|---|---|
| `config/plugins.php` | **Catalogul**: pentru fiecare plugin numele, descrierea, de ce are nevoie, rutele, paginile, intrările din meniu, cronurile, tabelele. Plus ordinea meniului adminului, rutele împărțite între pluginuri, pachetele de abonament și licențele de dezvoltare ale celor patru site-uri existente (`licente_dev`, doar pentru teste). |
| `src/Support/Licenta.php` | Citirea și verificarea licenței (`storage/licenta/licenta.json`) și memoria „ultima bună”. |
| `src/Support/LicentaSincronizare.php`, `scripts/licenta-sincronizare.php` | Preluarea automată a licenței de la CRM (cronul la 5 minute și „Verifică acum” din Admin → Abonamentul tău); mai jos, „Preluarea automată”. |
| `src/Support/Plugins.php` | Întrebările pentru tot codul: `activ`, `permiteInceput`, `vizibil`, `stare`; regula dependențelor; proprietarul unei rute; meniul. |
| `src/Http/rute.php` | Toate rutele site-ului (mutate din `public/index.php`). |
| `src/Http/GardaPluginuri.php` | Garda routerului: o rută a unui plugin care nu e pe site nu ajunge la controller. |
| `tools/dev/licenta.php` | Chei și licențe de test (dezvoltare). |
| `tools/dev/verifica-rute.php` | Verifică că fiecare rută are proprietar, că catalogul corespunde codului și că blocurile pluginurilor din șabloane închid HTML-ul pe care îl deschid. |
| `tools/dev/instantaneu-rute.php` | Instantaneul rutelor: ce face garda cu fiecare rută, meniul și cronurile, pe fiecare licență de test, față de fișierele din `tools/dev/instantanee/` (în git); plus regulile fixe care nu depind de catalog. |
| `tools/dev/test-pluginuri.php` | Testele: licența, garda, fiecare pachet parcurs pagină cu pagină (și cu browserul, inclusiv textul vizibil), „se oprește”, comparația cu codul de bază, la cerere fiecare plugin oprit pe rând. |
| `tools/dev/compara-cu-baza.sh` | Cu licența „toate” (sau, cu `--licenta=bioscem`, cu licența de dezvoltare a Bioscem, fără unealta internă), compară HTML-ul paginilor-cheie cu cel al codului Bioscem însuși (ultimul commit revizuit din `docs/de-portat.md`); diferențele voite sunt declarate explicit, cu motivul lor, și se scot înainte. Istoric: `--baza=f5ae5ea`, codul dinaintea pluginurilor. |
| `scripts/diagnostic.php --licenta` | Verificarea dinaintea unui deploy: licența se verifică cu cheia și site-ul din `.env`? Iese cu 1 dacă nu. Arată și dacă preluarea automată e configurată și ultima ei rulare. |
| `tools/dev/test-licenta-sincronizare.php` | Testele preluării automate, cu un CRM simulat (poarta G-lic). |
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

**Datele cu păstrare legală ale unui plugin oprit** (WP-26, decizia OD-51 = b): un
plugin oprit nu arată nimic în admin, dar rândurile lui rămân. Când clientul le cere
(comenzile pentru contabilitate, acordurile GDPR semnate, donațiile), ANDAXI rulează
pe server `php scripts/exporta-date-legale.php --ce=comenzi|acorduri_gdpr|donatii
[--din=AAAA-LL-ZZ]` (oricare ar fi starea pluginului; citește tabelele direct). Scriptul
scrie un CSV în `storage/export/` (drepturi 0600) și afișează doar numărul de rânduri
și un link `/_andaxi/export/<token>`, bun o singură dată, 24 de ore; fișierul se șterge
după descărcare, iar unul expirat la următoarea rulare, la următoarea cerere spre
`/_andaxi/export/…`, la prima cerere a zilei, la `migreaza.php --aplica` (deploy) și din Dashboard. Pe disc stă doar hash-ul tokenului; fără un token valabil adresa
dă 404. În admin nu apare nimic (App\Support\ExportDateLegale).

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

### Preluarea automată

Licența nu mai trebuie generată, descărcată și urcată de mână: site-ul o ia singur de la CRM. Omul bifează pluginul în
CRM (Site-uri → site-ul → Salvează); CRM-ul semnează pe loc licența următoare (versiunea +1) și o ține ca licența
curentă a site-ului; site-ul o preia în cel mult 5 minute (sau imediat, cu „Verifică acum”).

- **Cine cere:** cronul `scripts/licenta-sincronizare.php` la 5 minute (prin `bin/platforma`, [DEPLOY.md](DEPLOY.md),
  „Cronurile”) și butonul **Verifică acum** din Admin → Abonamentul tău (doar administratorul general, cu jetonul
  formularului; `POST /admin/abonament/verifica`). Amândouă cheamă `App\Support\LicentaSincronizare::ruleaza()`; un
  lacăt (`storage/licenta/.sincronizare.lock`) face ca doar unul să lucreze odată.
- **Ce cere:** `GET LICENTA_URL` (`https://crm.andaxi.ro/api/site/licenta`) cu `Authorization: Bearer LICENTA_CHEIE`
  (cheia site-ului din CRM), ambele din `.env`-ul site-ului. CRM-ul răspunde cu fișierul semnat, neschimbat.
- **Ce acceptă** (toate, altfel nimic nu se schimbă): semnătura Ed25519 se verifică cu `LICENTA_CHEIE_PUBLICA`
  (aceeași verificare `Licenta::verifica` ca pentru un fișier urcat de mână) și licența e pentru acest site
  (`LICENTA_SITE` = `site`-ul licenței de acum); `versiune` ≥ cea de acum (licența folosită sau, dacă acum nu se
  verifică niciuna, ultima aplicată din `stare-aplicata.json`), iar aceeași versiune doar cu exact același conținut:
  nicio întoarcere la o listă veche, nicio reluare. Licența nu are dată de expirare (nici urcată de mână): e bună
  până vine una mai nouă.
- **Doar HTTPS**, cu certificatul verificat, fără să urmeze redirecționări, cel mult 256 KB; `http://` doar spre
  `127.0.0.1`/`localhost` (testele). O adresă cu `user:parola@` e refuzată.
- **Cum scrie:** `Licenta::instaleaza($json)`, cu drepturile 0660 ale unei licențe puse de mână: fișier temporar în același dosar + `rename`, deci nimeni nu
  citește o licență pe jumătate (testul o verifică cu un cititor în buclă). Apoi stările se aplică pe loc
  (`Plugins`: `stare-aplicata.json`, jurnalul pluginurilor, golirea memoriei paginilor), exact ce ar face prima cerere
  după un fișier urcat de mână.
- **La eșec** (CRM-ul căzut, HTTP 401/404/429/500, un răspuns respins, disc fără drept de scriere): licența de acum
  rămâne, site-ul merge mai departe. Motivul ajunge în `storage/licenta/sincronizare.json` (0660; îl arată
  Abonamentul tău și `diagnostic.php --licenta`) și în `storage/logs/licenta-sincronizare.log` (o schimbare, sau
  același eșec cel mult o dată pe oră). Cheia nu se scrie nicăieri.
- **Ieșirea cronului:** 0 licență nouă sau la zi, 1 un răspuns respins sau CRM-ul care nu răspunde, 2 preluarea
  neconfigurată.

**La fel ca un fișier urcat de mână.** Licența preluată trece prin aceleași reguli (1–4 de mai sus) și același cod.
Un plugin pornit astfel:

- **Pașii de activare** (`config/activari.php`: pagina `/acorduri-gdpr`, pragul `erp_trimite_din` al ERP-ului) rulează
  ca azi, la prima cerere web de după (`Activari::laCerere`) sau la următorul `erp-sync.php`, o singură dată pe bază
  și doar pe o bază la zi cu migrările (garda). Cronul preluării nu atinge baza.
- **Migrările:** schema nu depinde de pluginuri (fiecare site are toate tabelele, oricare ar fi licența), deci un plugin
  pornit nu cere nicio migrare. Doar un plugin care vine cu o **versiune nouă de cod** (o migrare nouă) cere
  actualizarea obișnuită (`deploy/actualizeaza.sh`, care rulează `migreaza.php --aplica`); până atunci garda ține
  pașii de activare în așteptare, ca la un fișier urcat de mână.
- **Oprirea** unui plugin din CRM e tot o licență nouă (lista fără el, versiunea +1); datele lui nu se șterg. Pentru un
  plugin cu bani rămâne regula: întâi „se oprește”.

Cheia site-ului din CRM nu e ancora de încredere: o cheie furată lasă pe cineva doar să citească licența acelui site
(care nici nu e secretă). Semnătura e cea care contează; fără cheia privată a CRM-ului nu se poate face o licență pe
care site-ul s-o accepte.

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
  dacă e pe site magazinul sau oricare plată, Încercări de înregistrare și comandă (încercările de
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
asta se aplică, ca înainte, rolurile adminilor. Din WP-30 (faza D) grupurile sunt
cele opt ale adminului refăcut: Acasă, Comenzi, Produse, Clienți, Marketing,
Conținut, Rapoarte, Setări ([ARHITECTURA-ADMIN.md](ARHITECTURA-ADMIN.md)); un grup
cu o singură intrare vizibilă apare ca link, cu numele intrării. Ce trimite și ce
citește fiecare pagină de admin (inclusiv linkurile meniului) îl păzește contractul
adminului (`tools/dev/contract-admin.php`, poarta G-admin), pe patru roluri și trei
licențe; instantaneele rutelor (G-rute) arată meniul fiecărei licențe.

Dashboard-ul arată cardurile și listele doar pentru pluginurile vizibile
(Produse = catalog; Comenzi și „Comenzi recente” = magazin; Utilizatori și
„Utilizatori recenți” = conturi de clienți).

## Punctele de legătură și conținutul pentru membri (WP-24)

**Punctele de legătură** (`App\Support\Hooks`, din vertij VT-X2, port-vertij §4.2): nucleul cheamă un punct,
un plugin se agață de el dintr-un singur loc, `config/hooks.php` (din 9 noiembrie 2026 și `membri`, `donatii`, portate
din vertij: mai jos). O agățare contează doar cât pluginul ei e vizibil (sau, cu `'stare' => 'activ'`, doar pornit); fără
agățări, nucleul se poartă exact ca înainte. Punctele și locul lor: `inregistrare.formular` (formularul de
înregistrare din contul clientului; și `{{auth_form}}`, WP-25), `inregistrare.valideaza` / `inregistrare.cont_existent` /
`inregistrare.dupa_creare` (`accountRegister`: înainte de creare, adresa care are deja cont, după creare — o
redirecționare a pluginului înseamnă fără logare automată), `cont.taburi` și `cont.antet` (pagina contului, WP-25),
`continut.membru_activ` și `continut.nota` (poarta de mai jos), `editor.pagina.campuri` / `editor.articol.campuri`
(câmpurile din editorul paginii și al articolului), `utilizator.sters` (din Admin → Utilizatori și din „Șterge contul”,
înaintea ștergerii, în aceeași tranzacție), `layout.final_body` (înainte de `</body>` pe paginile publice),
`plugin.lucruri_in_curs` (pentru lucrurile în curs, WP-27), `layout.inceput_body` (imediat după `<body>` pe paginile publice:
bannerul pluginului `campanii`). În dezvoltare (`APP_ENV=local`), `CMS_DEV_HOOKS` poate da un
fișier cu agățări de probă (`tools/dev/test-porturi-vt-gr.php`).

**Conținutul pentru membri** (`App\Support\AccesMembri`, migrarea `nucleu/0034`): o pagină sau un articol cu
`requires_active_account = 1`. Cu pluginul `membri` oprit sau lipsă, rândul nu există pentru nimeni: 404 (API: JSON
404), lipsește din `/blog`, din API-ul listei, din `{{latest_posts}}`, `{{category_*}}`, articolele similare, numărătorile
categoriilor, iar linkurile spre el pleacă din pagini (ca ale unui plugin oprit, `Plugins::adresaVizibila`). Cu `membri`
pornit sau „se oprește”: un membru activ (agățarea `continut.membru_activ`) îl vede; oricine altcineva primește nota
„acces restricționat” (403, `noindex, nofollow`, fără cache; a pluginului prin `continut.nota`, altfel a nucleului), iar în
liste rămâne doar titlul și rezumatul scris (`is_locked`, fără text). Harta site-ului și `{{next_event_*}}` nu-l iau
niciodată. Editoarele scriu fanionul doar când formularul trimite `requires_active_account_present=1` (câmpul îl
adaugă pluginul); altfel valoarea salvată rămâne. Pe o bază nemigrată coloana lipsește și nimic nu e restricționat.

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
  titlul paginii și tab-ul „Pagini magazin” / „Pagini site”. Și un grup din
  `meniu_admin` le poate avea (WP-26, GR-14c). Din WP-30 niciun grup nu mai are nevoie:
  „Produse” e un grup al lui oricum, iar pe un catalog fără magazin „Cereri de ofertă” e
  singura intrare din „Comenzi”, deci apare ca link cu numele ei (OD-25).
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

- **Conține**: meniul Produse → Produse, Categorii produse, Câmpuri și șabloane (tab-urile Câmpuri suplimentare și
  Șabloane produse, WP-35); paginile `/magazin`, `/categorie/…`, `/marca/…`,
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
    iar linkul de ciornă merge oricum. Din noiembrie 2026 se schimbă din admin: Setări magazin → Magazin → „Produsele
    inactive” → bifa „Ascunde de pe site produsele inactive” (acțiunea `save_catalog_inactive`, golește cache-ul paginilor). Linkul îl dă Admin → Produse (editorul și eticheta „ciornă” din listă); un produs
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
  - câmpurile suplimentare (Admin → Câmpuri și șabloane → Câmpuri suplimentare): tipul „Imagine” (adresa unei imagini din galerie) și bifa
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

- **Conține**: meniul Comenzi, Coș flotant, Automatizări, Emailuri de comandă și
  ordinul de plată din Setări plăți; coșul, checkout-ul, `/checkout/succes/…`,
  coșul flotant, numărul din coș, prețurile și butoanele „Adaugă în coș”;
  emailurile de comandă (comandă nouă, schimbările de status, datele de plată
  OP); cronul `scripts/abandoned-cart-emails.php`; numerotarea comenzilor și
  cantitățile din Setări magazin; dashboard: „Comenzi” și „Comenzi recente”;
  codurile `{{cart_form}}`, `{{checkout_form}}`, `{{checkout_success_order_info}}`,
  `{{cart_count}}`; bulina cu reducerea (procent sau lei, ca pe cardul din listă) lângă prețul de pe pagina produsului, și
  lângă prețul unui șablon de produs, pusă de scriptul paginii (din Bioscem `1ca7148`; doar cu setarea
  `produs_bulina_reducere`, implicit `0`, fără câmp în admin: fără ea pagina și scriptul ei sunt cele de dinainte; Bioscem
  înghețat `1`); din livrarea 2 SEO (WP-R): fluxul `/feed/google-merchant.xml` (are
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
  `orders.fan_*`, `orders.completed_awb_email_*` (emailul cu AWB-ul la finalizare),
  `orders.pickup_*` (ridicarea personală, `livrare_fan/0052`).
- **Ridicarea personală de la sediu** (din Bioscem `807058e`, `fe122fd`; `App\Support\RidicarePersonala`): în Setări
  livrare, bifa „Oferă ridicare personală de la sediu” (`shipping_pickup_enabled`, implicit `0`), adresa sediului
  (`shipping_pickup_address`, implicit goală: fără ea opțiunea nu apare) și programul (`shipping_pickup_program`). Cât e
  oprită, checkout-ul, stilurile și scriptul lui ies exact ca înainte, iar o bifă `ridicare_personala` trimisă de mână e
  refuzată; excepție, un site cu adresa sediului scrisă (`RidicarePersonala::configurata`, Bioscem prin înghețare): ca în
  codul Bioscem (`c47f270`), stilurile, atributele și scriptul ridicării sunt în checkout și cu bifa oprită, iar pagina de
  mulțumire are spațiile blocului ei, doar caseta lipsește. Pornită: în checkout bifa cu adresa și programul; transportul 0 (fără prag, fără km suplimentari, fără tarif
  FAN; și în cotare, la plasare, în suma trimisă la BT, EuPlătesc, Stripe și în recalculările din admin), doar datele de
  facturare (FANbox și adresa de livrare separată dispar), rambursul ca „Plată la ridicare (numerar sau card)”; comanda
  îngheață `pickup_in_person` și adresa. Niciun AWB pentru ea, pe niciun drum (butonul, emiterea în masă, aprobarea din
  ERP, reîncercările, emiterea automată, previzualizarea), oricare ar fi starea pluginului; fără „Comandă expediată”. În
  Comenzi: „Ridicare personală — fără AWB”, butonul 🏬 „Gata de ridicare” (`POST /admin/orders/{id}/gata-de-ridicare`,
  emailul `pickup_ready` din Emailuri de comandă, cu `{{ridicare_personala}}`, `{{pickup_address}}`, `{{pickup_program}}`;
  contează doar dacă a ajuns la adresa clientului; retrimiterea cere confirmare) și destinația adresă / FANbox / ridicare
  înainte de facturare. ERP: `ridicarePersonala`, `ridicareAdresa` (doar pe comenzile cu ridicare), livrarea = sediul
  (județul expeditorului FAN; localitatea din ultima bucată a adresei de ridicare, „…, București”, ca în codul Bioscem — altfel a expeditorului FAN; la fel `pickup_city` pentru BT), `transport` 0. Șablonul de email și codurile lui apar doar cu pluginul pe site.
  La aprobarea din ERP (Bioscem `81766bc`) emailul „Gata de ridicare” pleacă singur, o singură dată (comanda se rezervă cu
  `pickup_ready_notified_at` IS NULL; dacă emailul nu ajunge la client, rezervarea se ridică și butonul rămâne), cu bifa
  „Trimite automat emailul…” din Setări livrare (`shipping_pickup_auto_ready`, implicit `1`; bifa apare și se salvează doar cu
  pluginul `erp` vizibil); mesajul aprobării spune dacă a plecat. Adresa trimisă la BT în `orderBundle` (`postAddress`, livrare
  și facturare) e tăiată la ultimul cuvânt întreg din 50 de caractere (Bioscem `c47f270`: producția BT refuză mai lung, cod 8).
- **Are nevoie de**: magazin.
- **Se oprește**: comenzile noi nu mai primesc FANbox, tarif live și ridicarea de la sediu (rămân regulile
  de transport generale); comenzile existente își fac AWB-ul, urmărirea și
  emailul cu AWB, iar cele cu ridicare își primesc anunțul „Gata de ridicare”; cronurile rulează.
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
  plăți (BT: tab-ul „Banca Transilvania”, testele, plata de test și butoanele
  din comandă: capturare, anulare, rambursare); adresa de confirmare
  (`/webhook/stripe`, `/webhook/euplatesc`, `/webhook/bt-ipay`,
  `/checkout/bt/retur`) — mereu deschisă; BT: cronul `bt-ipay-sync` („vizibil”).
  Tabele: `orders.stripe_*`, `orders.euplatesc_*`, `bt_ipay_transactions`,
  `bt_ipay_log`. Plata de test BT (din Bioscem `c1f458b`, `72d7a83`, `5afe196`): un formular cu datele clientului plătitor
  (nume, email, telefon, oraș, adresă; trimise în `orderBundle`, ca la o comandă reală; ținute în sesiune pentru testul
  următor) și suma, 1–99 lei (implicit 1); textul spre bancă „Plata de test <magazin>”; lista testelor arată `mdOrder` (și
  pe cel al părții cu puncte STAR), iar la o plată refuzată codul brut al băncii (`actionCode`, descrierea, statusul).
- **Are nevoie de**: EuPlătesc și BT de magazin; Stripe de nimic (îl folosesc și
  donațiile, și membrii).
- **Se oprește**: procesatorul nu mai apare în checkout și nu se mai trimit linkuri
  de plată noi prin el; plățile începute se confirmă, capturile și rambursările
  din comandă merg, linkurile deja trimise se pot plăti; setările lui se văd. BT:
  plata de test nu mai pornește (butonul e inactiv, cu explicație, iar
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
- **Webhook-ul Stripe** (`App\Support\StripeEvenimente`, WP-25, VT-X1; migrarea `plata_stripe/0035`): după semnătură,
  fiecare eveniment se notează în `stripe_events` (unul retrimis primește 200 „duplicate” și nu se mai procesează; două
  livrări în aceeași clipă: doar cea care îl ia în lucru, atomic, îl procesează, cealaltă primește 409 și Stripe o
  retrimite; unul luat de peste 5 minute se reia, o eroare a handler-ului îl eliberează — `in_lucru_la`, revizuirea WP-25),
  primește un proprietar (membri → donatii → link_plata → magazin, după metadata și tabelul donațiilor) și trece prin
  handler-ul lui, **în orice stare a pluginului** (banii se înregistrează mereu; emailurile către clienți ale pluginurilor
  noi doar cu pluginul vizibil; cu proprietarul oprit, un rând pe zi în `pluginuri.log`). Handler-ele magazinului și ale
  linkului de plată sunt ramurile de dinainte, neschimbate. Fiecare plugin își declară evenimentele (`stripe_evenimente`
  în catalog); Setări plăți le arată, cu ultimul eveniment primit pe tipuri și butonul „Verifică webhook-ul”
  (`POST /admin/settings/payments/verifica-webhook`, cu jetonul CSRF: endpoint-ul contului pentru adresa site-ului, pornit
  sau nu, versiunea lui API, evenimentele care îi lipsesc). Fără magazin, secțiunea Stripe nu mai vorbește de checkout și
  bifa „plata prin Stripe în checkout” nu se arată (nici nu se schimbă la salvare).
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
- **Link de plată pentru o comandă cu plata eșuată** (9 nov 2026, cerut de Bioscem): o comandă cu cardul (orice procesator al
  site-ului) a cărei plată a eșuat, a expirat, a fost anulată (suma BT eliberată) sau n-a fost terminată („Plată în așteptare” de
  peste `bt_ipay_expire_minutes`, implicit 60 de minute), neanulată, nefinalizată și fără nimic încasat, primește din fereastra
  comenzii (și din butonul 💳 al rândului din listă) „Trimite link de plată” pentru tot totalul: `POST /admin/orders/{id}/payment-link`
  cu `tip=integrala` și jetonul anti-CSRF (AdminCsrf, ca acțiunile BT); `GET` pe aceeași adresă dă fereastrei lista linkurilor
  comenzii (trimis, plătit, anulat, expirat) și dacă se poate trimite acum. Același procesator ca la diferențe, același tabel și
  felul scris la creare în referință, `{nr}-PI{n}` (`PaymentLink::esteIntegral`; nicio coloană nouă). Plătit, linkul
  face comanda plătită ca o plată reușită din checkout (`PaymentLink::confirmaPlataDetaliat`, `efecteDupaConfirmare`: starea,
  emailul „comandă nouă”, ERP-ul). La BT linkul pornește o plată obișnuită a comenzii (`order`, „{nr}-R{n}”, cu `link_id`;
  `BtIpayPayments::pornestePlataIntegrala`), deci autorizarea, încasarea la factura din ERP sau la 96 de ore și eliberarea sunt
  ale comenzii. Gărzile împotriva plății duble: nu se trimite cât o plată BT a comenzii e autorizată sau în curs (sau plata unui
  link al ei); plata inițială confirmată totuși (callback, cron, retur, „Plătit prin link extern”) anulează linkurile de plată
  întreagă rămase; un asemenea link nu mai pornește pe o comandă plătită; o plată BT de prisos doar autorizată se eliberează
  singură; banii veniți totuși de două ori lasă pe comandă nota „Plătită de două ori: …” (`PaymentLink::NOTA_DUBLA`, în
  `payment_error`), cu plata de rambursat și cea socotită, fiecare cu procesatorul și id-ul ei; plata BT socotită a unei asemenea
  comenzi nu se eliberează / rambursează din fereastră fără `confirm_dubla=1` (btActiune îl trimite după o fereastră de confirmare care
  numește plata socotită). Pentru toate linkurile: orice încasare anulează
  linkurile neplătite mai mari decât restul (`anuleazaDepasite`), o diferență mai mare decât restul nu mai pornește, același link
  plătit a doua oară (alt ep_id / altă sesiune) lasă nota; confirmările EuPlătesc și Stripe sunt atomice (`UPDATE … WHERE
  payment_status <> 'paid'`), iar o scriere nereușită răspunde cu eroare (procesatorul reîncearcă). Stripe: sesiunea linkului se
  refolosește cât e deschisă și expiră după 35 de minute, a checkout-ului după `bt_ipay_expire_minutes`; înainte de un link de
  plată întreagă, sesiunea checkout-ului comenzii se expiră (`/v1/checkout/sessions/{id}/expire`). Trimiterea linkului pentru
  diferență cere și ea jetonul AdminCsrf. Testele: `tools/dev/test-link-plata.php` (și în testul de fum), cu o bancă BT falsă
  (`tools/dev/lib/bt-fals.php`).
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
  În editorul produsului, lângă un stoc venit din ERP, butonul „↻ Ia stocul acum din ERP” (din Bioscem `6476108`;
  `POST /admin/products/{id}/stoc-erp`, al pluginului, cu jetonul formularului, JSON): golește memoria (și eșecul ținut
  minte), cache-ul paginilor și întreabă ERP-ul pe loc de acel produs.
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

- **Conține**: meniul Cupoane (tab-urile Cupoane și Coduri unice, WP-35; cu produsele schimbate în masă, WP-21 C12, din
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

- **Conține**: meniul Fidelitate (din WP-35 cu tab-uri; istoricul unui client e în fișa lui, la Clienți); punctele din coș, checkout și contul
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
  lateral „Oferte”, câmpurile BBD din formularul de produs, caseta „Sertar lateral
  „Oferte”” din Setări magazin (tab-ul Magazin) și pagina **Oferte BBD** (meniul Produse,
  `GET /admin/products/oferte-bbd`, `OferteBbdController`, WP-35 IA-05: toate ofertele, doar
  citire). Coloane: `products.bbd_*`, `order_items.bbd_key`.
- **Are nevoie de**: magazin.
- **Se oprește**: ofertele nu mai apar și nu se mai aplică în coș; comenzile cu
  oferte își păstrează prețul.
- **Oprit**: la fel, plus câmpurile din admin (rămân salvate pe produs) și pagina Oferte BBD
  („Modulul nu este inclus”).

### Produse promoționale (`produse_promo`)

- **Conține**: meniul Produse promoționale; mostrele și cadourile din fereastra
  comenzii și raportul pe client. Tabele: `promotional_products`,
  `order_promo_items` (WP-21, C13, din Biovitality `657c0cf`, `2ebc0cb`, `9cca65b`: „dat la” și
  „dat de”, `given_at`/`given_by` din `produse_promo/0031`, păstrate peste re-salvările comenzii;
  exportul Excel `GET /admin/promo-products/export`, cu `App\Support\XlsxWriter` din nucleu: foile
  „Produse” și „Cui a fost dat”, pe intervalul filtrat; fereastra „Cui a fost dat” derulează). Butonul 🎁 din lista de
  comenzi (`GET /admin/orders/{id}/client-promo`) deschide fereastra clientului pe două zone (Biovitality `f78ecb7`): în
  stânga promoționalele primite, în dreapta ultimele 100 de comenzi ale lui, cu produse, prețuri, transport, total și ce
  promoționale s-au dat pe fiecare („Fără promoționale” altfel); totalul de sus nu numără comenzile anulate, rambursate,
  returnate sau eșuate. Pe telefon, zonele stau una sub alta.
- **Are nevoie de**: magazin.
- **Se oprește**: ca pornit (se folosește doar în admin, pe comenzile existente).
- **Oprit**: nimic promo în comenzi sau în meniu.

### Conturi clienți (`conturi_clienti`)

- **Conține**: `/login`, `/register`, `/contul-meu/…` (profil, comenzi, adrese,
  resetarea parolei), autentificarea cu Google, `{{account_section}}`,
  `{{auth_google_button}}`; meniul Clienți și Conturi clienți;
  dashboard: „Utilizatori” și „Utilizatori recenți”; emailul de bun venit. Tabele:
  `users`, `user_addresses`, `customer_password_resets`.
- **Din vertij** (WP-25): `{{auth_form}}` (VT-C5: formularul de intrare și de înregistrare, doar pe paginile cu adresa
  `login` și `register`, cu capcana anti-robot a codului comun și culoarea principală a temei; câmpurile unui plugin prin
  `inregistrare.formular`); tab-urile pluginurilor în cont (VT-X3, `cont.taburi`, doar cele cu conținut; `cont.antet`
  pentru statistici; decizia OD-20 luată ca recomandarea: pagina de cont a nucleului, nu cea refăcută a vertij);
  confirmarea adresei (VT-C9, OD-19 ca recomandarea, `App\Support\ConfirmareEmail`, migrarea `conturi_clienti/0036`):
  `users.email_confirmed_at`, linkurile din `customer_email_confirmations` (hash sha256, 7 zile, o folosire),
  `GET /cont/confirmare/{token}` (pagina cu butonul) și `POST /cont/confirmare/{token}` (confirmarea), mereu deschise,
  retrimiterea din cont `POST /contul-meu/confirmare-email` cu bugetul ei (5 la 15 minute, separat de înregistrări,
  VT-C8); setarea `customer_email_confirmation_required` (oprită implicit; vertij și Biovitality înghețate pornit, BV din
  decizia proprietarului din 30 octombrie): contul creat cât e pornită primește linkul și nu intră până nu-l folosește,
  conturile de dinainte intră ca înainte.
- **Comenzile fără cont** (regula nucleului G-M4, decizia OD-47, fără setare): o comandă plasată fără cont cu adresa unui
  cont apare în el (și îi completează adresa de facturare) doar după ce clientul dovedește adresa: linkul de confirmare
  (cerut de el, din rândul „Ai comenzi mai vechi făcute cu această adresă…”, textul în `cont_comenzi_neconfirmate_text`;
  nicio trimitere în masă), un link de resetare a parolei folosit sau o logare cu Google. Atunci punctele de fidelitate în
  așteptare ale acelor comenzi se revendică și comenzile se leagă de cont (`orders.user_id`, un rând „cont_comenzi_legate”
  în Jurnal activitate); la înregistrare sau la logare, fără adresa dovedită, nu se revendică nimic.
  **Revizuirea WP-25** (dovada e a unei adrese, nu a contului): linkul ține hash-ul adresei la care a plecat
  (`customer_email_confirmations.email_hash`, migrarea `conturi_clienti/0038`) și confirmă doar dacă adresa contului e tot
  aceea; resetarea și Google confirmă doar adresa pe care au dovedit-o; o adresă schimbată (din cont sau din admin) își
  pierde confirmarea. Linkul se deschide într-o pagină cu un buton (deschiderea singură nu confirmă nimic): cel cerut din
  cont se apasă logat în acel cont, cel de înregistrare cere parola contului (cine și-a făcut cont cu adresa altuia nu-l
  poate confirma prin proprietarul adresei). Resetarea parolei închide celelalte sesiuni ale contului
  (`users.session_version`, verificată de `CustomerAuth`); prima intrare cu Google într-un cont neconfirmat, cu parolă,
  anulează parola veche și sesiunile lui (rândul „cont_parola_anulata_google” în Jurnal activitate).
- **Din Biovitality** (`0e54737`, 5 noiembrie; aceeași regulă, pe care codul comun o avea deja): bifa „Cere confirmarea
  adresei de email” în Conturi clienți (`customer_email_confirmation_required`, acțiunea `save_email_confirmation`);
  `GET /contul-meu/confirmare/{token}` (mereu deschisă), adresa linkului din emailurile trimise de codul Biovitality, duce
  (302) la `/cont/confirmare/{token}`; migrarea adoptivă `conturi_clienti/0053` aduce schema confirmării făcută de codul
  Biovitality la regula de aici (implicitul NULL al lui `users.email_confirmed_at`, linkurile BV legate de adresa lor), iar
  pasul `site/biovitality/0001_confirmare_email` păstrează bifa BV oprită (`customer_email_confirmation_enabled` = 0).
- **Are nevoie de**: nimic.
- **Se oprește**: ca pornit (clienții își văd contul și comenzile).
- **Oprit**: fără conturi pe site (linkurile „Contul meu”/„Autentificare” se scot);
  „Încercări de comandă” (fosta Securitate formulare) arată doar încercările de comandă (și dispare cu totul fără
  magazin);
  emailurile de comandă duc la prima pagină în loc de „Comenzile mele”; emailul
  de bun venit nu mai pleacă (nici în „Trimite email test”). La cupoane,
  restricția „Doar pentru utilizatori” dispare și se păstrează la salvare.

### Blog (`blog`)

- **Conține**: `/blog`, articolele, API-ul blogului, codurile `{{blog_posts}}`,
  `{{latest_posts}}`, `{{category_*}}`, `{{next_event_*}}`, articolele de pe prima
  pagină, meniul Blog; din vertij (WP-24, VT-C4) cardurile de eveniment în sliderul `{{category_posts}}` (doar pentru
  postările cu data unui eveniment: data în colț, locația, „Vezi detalii”; săgețile și punctele merg card cu card),
  `{{category_event_rows:slug}}` (rânduri compacte, câte 3 pe pagină) și `{{category_grid_paged:slug1,slug2}}` (grila
  din mai multe categorii, câte 6), cu clasele `vtcs__`/`vtcg__`/`vter__` ale conținutului vertij; videoul postării și al evenimentului următor (`VideoEmbed`, din
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

- **Conține**: meniul Newslettere, Șabloane newsletter, Liste abonați, Formulare opt-in, Statistici newsletter
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
  Din Bioscem (WP-R din 30 oct, `e2e2d77` … `739f58c`): fiecare abonat primește o campanie o singură dată (lacăt pe campanie,
  adresa trecută „pending” înaintea trimiterii; o trimitere neconfirmată de serverul de email rămâne „unknown”, nu se reia), un
  mesaj respins se reîncearcă de cel mult 3 ori, după 5 respingeri la rând campania trece în „Oprit” (`last_error`) și așteaptă
  „Reia trimiterea”; „Oprește trimiterea”, „Anulează programarea”; un newsletter care a plecat nu se mai modifică, nu se
  programează și nu se retrimite; antetele `List-Unsubscribe` / `List-Unsubscribe-Post`. Formularul public face **dubla
  confirmare** (`newsletter_cereri_confirmare`: limite pe adresă, pe IP — `IpClient::adresa()`, IPv6 pe /64 —, pe tot site-ul
  300 pe oră, nota `newsletter_limita_globala_atinsa` în admin; rutele `GET/POST /newsletter/confirmare/{token}`); linkul de
  dezabonare arată butonul „Confirm dezabonarea”, iar `POST /newsletter/unsubscribe/{token}` (butonul și dezabonarea „cu un clic”
  a clientului de email) e **mereu deschis**, ca GET-ul; un abonat șters intră pe lista „nu trimite” (`newsletter_suprimate`, doar
  amprenta SHA-256 a adresei); importurile și adăugarea din admin nu reactivează un dezabonat. Linkurile se semnează cu o cheie
  aleatoare (`NEWSLETTER_SECRET` din `.env`, altfel `newsletter_cheie_semnare`, `Settings::secretGenerat`); cheia de dinainte
  (WP-17) verifică doar linkurile trimise înainte, până la pragul `newsletter_cheie_limita` (amândouă în `Settings::CHEI_ASCUNSE`).
  Builderul unei campanii are pașii Conținut → Destinatari → Verificare → Trimite / Programează (`views/admin/emails/newsletter/`,
  `public/assets/js/admin/newsletter-pasi.js`), lista de verificare (`NewsletterVerificare`, `POST /admin/emails/builder/verificare`,
  a pluginului; adresele din afara site-ului cerute doar dacă sunt publice, fără redirecționări) și „Alege un șablon” cu
  șabloanele magazinului (`NewsletterSabloane`: logo-ul și culorile din tema pachetului, `tema.json` → `newsletter`, altfel fără
  logo și paleta neutră; fără catalogul pe site, fără carduri de produs; fără magazin, fără prețuri). Componentele adminului
  folosite de pași (`views/admin/componente/`) sunt ale nucleului ([ui/README.md](ui/README.md)). Tabelele și coloanele noi:
  migrarea `newsletter/0040`. Testele: `tools/dev/test-newsletter.php`.
- **Are nevoie de**: nimic.
- **Se oprește**: campaniile pornite se termină (cronul le duce la capăt); „Trimite
  acum” și „Programează” sunt refuzate, iar cronul nu mai pornește campaniile
  programate (rămân programate). Linkurile din newsletterele trimise merg.
- **Oprit**: nimic din newsletter în admin sau pe site; cronul iese cu o linie;
  dezabonarea (GET-ul paginii și POST-ul butonului sau „cu un clic”) și linkurile din newsletterele trimise merg în continuare
  (cu semnătura lor); un link de dezabonare sau de urmărire nevalid dă 404, la fel confirmarea abonării și lista de verificare
  din admin (garda). Pagina
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
  Setări → Manechin. Setări: `mannequin_*` (punctele în
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

### Cereri de ofertă (`cereri_oferta`)

WP-26 (GR-15, din grafoanaytis `d3c4b79`). Un catalog care nu vinde primește cereri în
loc de comenzi.

- **Conține**: codul `{{product_quote_button}}` din șablonul de produs (butonul și un
  sertar lateral cu formularul: nume, companie, email, telefon, mesaj, acordul; un
  offcanvas Bootstrap 5, cu marcajul și clasele din grafoanaytis), `POST /api/cerere-oferta`
  (JSON; capcana pentru roboți, câmpurile mărginite, produsul căutat după slug pe server;
  cererea se salvează întâi, apoi pleacă emailul la destinatarii formularului de contact,
  cu Reply-To = vizitatorul), Magazin/Produse → Cereri de ofertă (lista cu filtrul pe
  stare, starea și nota internă pe rând, ștergerea „moale”; jeton anti-CSRF, un rând în
  Jurnal activitate). Tabele: `quote_requests`, `quote_submit_logs` (migrarea
  `cereri_oferta/0037`; pe grafoanaytis tabelul există deja și e adoptat cu rândurile lui).
- **Limita**: 5 trimiteri la 10 minute de la același vizitator (429), numărate doar cu un
  HMAC al adresei (IPv6 pe /64), nu cu adresa; cheia HMAC e un secret aleator al site-ului (`cereri_oferta_secret_ip`, făcut
  la prima folosire, ascuns din `Settings::all` ca toate `Settings::CHEI_ASCUNSE`); rândurile mai vechi de o zi se șterg din
  mers.
- **Setări** (textele, implicit neutre; grafoanaytis are textele lui prin înghețare, OD-26):
  `cereri_oferta_buton` („Cere ofertă”), `cereri_oferta_titlu`, `cereri_oferta_intro`,
  `cereri_oferta_eticheta_mesaj`, `cereri_oferta_multumire` (răspunsul după trimitere),
  `cereri_oferta_confidentialitate_url` (goală = adresa bannerului de cookie; amândouă
  goale = fără link). Încă fără ecran în admin: se pun prin pachetul site-ului.
- **Bootstrap**: o temă cu `"bootstrap": true` are deja CSS-ul și JavaScript-ul; altfel
  codul aduce JavaScript-ul Bootstrap din nucleu și câteva reguli CSS doar pentru sertar.
- **Rol**: „Administrator magazin” vede lista (lângă comenzi).
- **Are nevoie de**: `catalog`. Fără magazin, cardurile simple de produse spun „Cere
  ofertă” în loc de „Vezi produsul” (GR-16), iar în meniul adminului „Cereri de ofertă” stă în grupul „Comenzi” (singură, deci ca
  link, pe un catalog fără magazin; WP-30, în locul grupului „Produse” al GR-14c).
- **Se oprește**: butonul nu se mai arată (nici scriptul lui), o cerere nouă primește 403;
  lista din admin rămâne.
- **Oprit**: `/api/cerere-oferta` dă 404 (JSON), adminul 403, meniul dispare; rândurile
  rămân (exportul de mai sus nu le cuprinde: nu sunt date cu păstrare legală).

### Campanii pe site (`campanii`)

Cerut pentru Biovitality (5 noiembrie 2026), făcut generic. `App\Support\Campanii`, `CampaniiController`, migrările
`campanii/0054`, `campanii/0056` (pagina fără antet și subsol, codul `{{campanie_produse}}`), `campanii/0057` (colțurile
cardurilor), `campanii/0058` (oferta campaniei, `App\Support\OfertaCampanie`) și `campanii/0066` (produsul ascuns arătat pe
pagina campaniei), testele `tools/dev/test-campanii.php` (în testul de fum pe „toate”) și `tools/dev/campanii-browser.cjs`.
**Oprit pe orice site**: nu e în niciun pachet și în nicio licență de dezvoltare (`licente_dev`); îl pune doar CRM-ul în
licență (`"campanii": "pornit"`). Pentru teste: `tools/dev/php.sh tools/dev/licenta.php biovitality campanii=pornit`.

- **Conține**: Marketing → **Campanii pe site** (`/admin/campanii`, lista; `/admin/campanii/noua`, `/admin/campanii/{id}`,
  editorul; `POST /admin/campanii/salveaza`, `POST /admin/campanii/{id}/sterge`, cu jetonul formularului și un rând în Jurnal
  activitate; doar administratorul general, rolurile parțiale nu au adresa în `Auth::canAccessPath`); pagina campaniei
  `/campanie/{slug}`; agățarea de punctul nou `layout.inceput_body` (bannerul și bara paginii campaniei); insigna din
  `views/site/components/product-card.php` și `views/site/product.php`; trimiterea de la prima intrare din `public/index.php`.
  Tabele: `campanii`, `campanii_produse`.
- **O campanie** are o fereastră (început, sfârșit, ora României, câmpurile `datetime-local`) și bifa „Pornită”; în afara
  ferestrei (sau nebifată) **nu se vede nimic**: nici pagina (404), nici insignele, nici bannerul. Se pot pregăti oricâte
  campanii, dar **două campanii pornite nu au voie să aibă ferestre care se suprapun** (refuzat la salvare, 422, cu numele
  celeilalte), deci pe site e activă cel mult una (decizia de simplitate; dacă totuși ar fi două, din bază, câștigă cea care a
  început ultima). Lista arată starea: Activă acum, Programată, Încheiată, Oprită.
- **Pagina de deschidere**: o pagină din Conținut → Pagini (editorul de pagini al nucleului, cu codurile lui), aleasă în
  campanie; se recomandă o pagină **nepublicată** (ciornă): așa se vede doar prin campanie și doar cât campania e activă
  (o pagină publicată rămâne și la adresa ei, ca orice pagină). Se randează în aspectul site-ului (antetul, subsolul, bannerul
  de cookie-uri), cu o bară lipită sus și butonul **„← Înapoi în site-ul principal”** în stânga (textul se poate schimba),
  spre adresa cerută la intrare. Popup-ul de anunț al nucleului nu se pune peste ea (apare pe pagina următoare). Codurile care
  cer pregătire în controller (`{{shop_catalog}}`, coșul, checkout-ul, contul, formularul de recenzii, `{{products_by_template:…}}`,
  `{{produse_serviciu}}`) ies goale pe ea; celelalte (`{{featured_products}}`, `{{latest_posts}}`, categoriile…) merg.
  `noindex, follow` (meta și `X-Robots-Tag`), fără canonical, `Cache-Control: no-store, private`, nu e în harta site-ului.
  Administratorul logat o vede și în afara ferestrei, ca **previzualizare** (cu o notă), fără să primească cookie-ul.
- **„Pagina campaniei fără antetul și subsolul site-ului”** (bifa din „Pagina de deschidere”; `campanii.fara_antet_subsol`,
  `campanii/0056`): **bifată la o campanie nouă**; în bază 0, deci o campanie făcută înainte de `0056` rămâne cum era până o
  bifează cineva (și se salvează). Bifată, **doar la `/campanie/<slug>`** (`Campanii::faraAntetSubsol`; niciodată la adresa
  proprie a paginii), `views/layout.php` nu mai pune antetul (`$designHeaderOutput` sau antetul de rezervă), subsolul
  (`$designFooterOutput`), sertarul „Oferte” (BBD) și butonul coșului plutitor (scriptul coșului rămâne, ascuns doar butonul,
  ca „Adaugă în coș” de pe carduri să meargă ca în magazin); bannerul de sus al campaniei și popup-ul de anunț nu erau oricum pe
  ea. Rămân: bara „← Înapoi în site-ul principal”, bannerul de cookie-uri, bannerul copiei de verificare, GTM-ul (și
  `noscript`-ul lui), CSS-ul și scripturile site-ului (inclusiv JavaScript-ul antetului și al subsolului, care trebuie să nu
  cadă fără markup-ul lor: de verificat cu tema site-ului; un antet fix care pune `padding-top` pe `body` din CSS lasă spațiul).
  **„Bara de sus”** (lista de lângă bifă; `bara_inchisa`): deschisă (albă, ca până acum) sau închisă (neagră, linia de jos gri
  închis), pentru o pagină cu fundal închis; butonul rămâne în culoarea principală a temei.
- **Codul `{{campanie_produse}}`** (în conținutul paginii campaniei, Conținut → Pagini): pe `/campanie/<slug>` devine grila
  produselor alese ale campaniei (`Campanii::htmlProduse`, randată pe server, în `views/site/custom-page.php`); în rest (la
  adresa proprie a paginii, pe orice altă pagină, cu pluginul oprit) și fără niciun produs vizibil iese **gol**; o pagină fără
  cod nu se schimbă. Produsele: cele bifate în campanie, active, neșterse și **neascunse din catalog** (ca lista magazinului),
  afară de cele ascunse bifate în editorul produsului (Afișare și asocieri, doar cu pluginul pornit) **„Arată pe pagina campaniei
  chiar dacă e ascuns din lista magazinului”** (`products.campanie_arata_ascuns`, `campanii/0066`, 12 nov 2026; în magazin rămân
  ascunse), după nume; disponibilitatea din ERP, ca în catalog. Fiecare card: poza (fișierul refăcut, dimensiunile, variantele, ca pe
  cardul magazinului), numele, insigna campaniei (textul și culorile ei), prețul curent (prețul redus sau al unei perioade de
  reducere în curs, ca pe cardul magazinului, același format) cu prețul vechi tăiat, rândul opțional **„Text sub preț”**,
  **„Adaugă în coș”** și **„Vezi produsul”** (spre `/produs/<slug>`). „Adaugă în coș” are regulile cardului din magazin
  (`views/site/components/product-card.php`): același formular (`POST /cos/adauga/<id>`, o bucată, prins de coșul plutitor,
  deci aceleași reguli de coș, stoc și precomandă); epuizat = „Stoc epuizat”, fără formular; precomanda („Precomandă”) și un
  produs cu oferte BBD de ales („Alege oferta”) duc la pagina produsului; fără magazin pornit, fără buton (și fără preț, fără
  magazin pe site). „Text sub preț” (`text_sub_pret`, 160 de caractere, escapat): `{pret}` = prețul curent, `{pret*1.5}` (sau
  `{pret x 1.5}`, `{pret×1,5}`) = prețul curent înmulțit, de exemplu „2 bucăți: {pret*1.5}”; gol = fără rând. **„Cardurile
  produselor”** (`carduri_inchise`): deschise (albe, chenar gri) sau închise (card `#161616` pe fundal negru, text alb, ca pe
  pagina Biovitality). **Prețul ofertei** (cu oferta campaniei aleasă, doar cât campania arătată e cea activă, nu la
  previzualizarea adminului; `Campanii::randOfertaCard`, aceeași rotunjire ca în coș, de la prețul de acum al produsului, deci
  la un produs cu o reducere proprie de la prețul redus, mai jos, „Produsele campaniei cu o reducere proprie”): un rând sub
  preț, cu prețul tăiat în roșu (`#c0392b`; `#f87171` pe cardurile închise) și prețul de ofertă îngroșat: „1+1 −50%”: „71.67 lei +
  ~~71.67~~ 35.83 lei” (reducerea 35,835 se rotunjește la 35,84, deci a doua bucată costă 35,83, ca în coș); „Al 3-lea la
  −30%”: „Al 3-lea: ~~71.67~~ 50.17 lei”; „3 la preț de 2”: „3 la preț de 2: ~~215.01~~ 143.34 lei” (N × prețul tăiat, M ×
  prețul); „−10%”: „~~71.67~~ 64.50 lei”; prețul de campanie: „~~71.67~~ 59.99 lei” (doar sub prețul produsului). Un produs
  redus (100 lei redus la 90, −20%): prețul cardului „90.00 lei ~~100.00 lei~~”, apoi rândul ofertei „~~90.00~~ 72.00 lei”. Un
  produs cu **oferte BBD de ales** (`Campanii::randOfertaLot`): cardul și pagina lui nu știu ce lot va alege clientul, deci nici
  prețul de la care pornește oferta în coș (al lotului, dacă e mai mic); rândul are doar eticheta, fără sume: „**−20%** în coș,
  la oferta aleasă” (la prețul de campanie, doar cu un preț sub prețul de acum al produsului). Sume socotite de la prețul fără
  lot ar fi greșite (prea mari) pentru loturile reduse, de aceea nu se arată. Fără ofertă sau campanie inactivă: cardul de
  dinainte. **Pagina produsului** are același rând (`Campanii::ofertaPagina`, cu eticheta ofertei într-o pastilă): sub preț în
  aspectul fără șablon; în aspectul cu șablon, scriptul paginii îl mută după primul element de preț al șablonului (`.pdp-price`,
  `.product-price`, `[data-product-price]`, `.price`, altfel elementul cu textul prețului), altfel sub titlu. Prețul tăiat e
  `<s>`, cel nou `<b>` (scriptul paginii care caută prețul printre `span`/`strong` nu le ia drept prețul produsului).
  **Insigna** produsului din campanie stă, în aspectul cu șablon, chiar deasupra titlului (înaintea primului `<h1>` al
  șablonului; fără `<h1>`, în capul panoului, ca înainte).
  **„Colțurile cardurilor”** (`carduri_drepte`, `campanii/0057`): rotunjite (implicit, aspectul de până
  acum) sau drepte (cardul, cutia pozei și butoanele cu `border-radius: 0`; insigna rămâne rotunjită). Editorul nu are o
  previzualizare a cardurilor (se vede pe pagina campaniei, și ca previzualizare a administratorului). Responsivă: câte încap de minimum 230 px pe desktop, două pe rând sub 600 px.
- **Intrarea pe site** (`Campanii::intercepteaza`, înaintea cache-ului de pagini; deciziile proprietarului din 5 nov: **la
  fiecare intrare din afară**, și când vizitatorul a văzut deja pagina în aceeași sesiune, de exemplu a închis fila și a venit
  iar de pe Google): un om care intră pe site, de pe orice link din afară (Google, un email, o rețea socială) sau fără Referer
  (adresa scrisă de mână, un semn de carte, o aplicație care nu trimite Referer), primește `302` la
  `/campanie/<slug>?inapoi=<calea și parametrii ceruți>` (`Cache-Control: no-store, private`), fără niciun cookie. `inapoi` e verificat ca o cale a acestui site (`/…`, fără `//`, `\`, schemă, gazdă, caractere de
  control, nici `/campanie/…`); orice altceva duce la `/` (nicio redirecționare deschisă). **Nu se trimite niciodată**: altă
  metodă decât GET (POST, HEAD), roboții și previzualizările de linkuri (Googlebot, Bing, AdsBot, rețelele sociale, unelte,
  `HeadlessChrome`, fără User-Agent; `Campanii::eRobot`), o cerere care nu cere `text/html` sau nu e navigarea unui document
  (`Sec-Fetch-Dest`/`Sec-Fetch-Mode`, preîncărcările, AJAX), navigarea din interiorul site-ului (`Referer` de pe același site,
  și `www.`: singurul lucru care oprește trimiterea după prima intrare; un browser sau o extensie care nu trimite Referer nici
  pe clicurile din site ar fi trimis la campanie la fiecare clic, caz rar), un administrator logat, adresele excluse din stratul SEO (`SeoRedirects::eExclusa`: admin, API, `/_andaxi/`, coș, checkout, cont, login,
  plăți și întoarcerile lor, webhook-uri, newsletter, fișiere, `/uploads`, `/assets`, adresele mereu deschise ale tuturor
  pluginurilor, `cai_excluse` din `seo.json`) și cele blocate în `robots.txt`, `/campanie`, `/cont`, `/og`, `/tema`,
  `/sitemap…`, `/feed`, `/review-form`, orice cale cu extensie de fișier, paginile altor pluginuri decât catalogul și blogul
  (cele din `pagini`, ex. `checkout/succes`, `acorduri-gdpr`) și paginile de sistem ale hărții site-ului, linkul de ciornă al
  unui produs (`?draft=`). Fără pagină aleasă sau cu „Arată pagina campaniei la prima intrare” debifată, nu se trimite nimeni
  (insigna și bannerul rămân). Cât se plimbă prin site (clicurile din site), vizitatorul nu e trimis la ea; o mai vede la
  următoarea intrare din afară sau dacă apasă bannerul (mai jos).
- **Fără cookie** (de la a doua decizie din 5 nov): `campanie_vazuta` nu se mai pune și nu se mai citește (unul rămas de la
  versiunea de dinainte nu schimbă nimic); se poate scoate din politica de cookie-uri a site-ului.
- **Produsele campaniei cu o reducere proprie** (decizia proprietarului din 10 nov; de pe 5 nov până atunci, un astfel de produs
  nu se putea alege, ca să nu se cumuleze două reduceri): un produs care are deja o schimbare de preț **se poate alege**, iar
  **reducerile se adună**: oferta campaniei se aplică peste prețul lui redus. Reducerea proprie (`Campanii::motivReducere`):
  prețul redus sau o perioadă de reducere în curs (prețul de pe card sub prețul lui, ca în magazin) și ofertele BBD de ales
  (pluginul „Oferte BBD” pornit); precomanda nu are preț propriu în acest cod, deci nu contează. În editor bifa lui e ca a
  oricărui produs, cu nota „(are deja o reducere: <motiv>; oferta campaniei se adaugă peste ea)”, iar salvarea îl primește.
  Exemplu: 100 lei redus la 90, cu −20%: 72 lei. Prețul de la care pornește oferta e prețul liniei din coș de dinainte de ofertă
  (`CheckoutCalculator::effectiveProductPrice`, cu lotul BBD ales): prețul redus, al perioadei în curs (cel mai mic) sau al
  lotului BBD ales (dacă lotul are un preț mai mic; un lot fără preț propriu are prețul produsului). Un produs redus după
  salvare rămâne în ofertă, de la noul lui preț. Regulile pe tipuri sunt mai jos („Oferta campaniei”).
- **Insigna** (textul, implicit „Campanie”, și două culori): pe produsele alese (lista cu căutare din editor), pe pagina
  produsului (în capul panoului, în ambele aspecte: cu șablon și fără), pe cardurile catalogului (`{{shop_catalog}}`,
  `/magazin`, `/categorie/…`, `{{products_by_template:…}}`; jos în stânga pozei, sus sunt reducerea și ofertele BBD), în grila
  `{{featured_products}}` de pe prima pagină (`HomeSections`), pe cardurile produselor similare de pe pagina produsului (cele
  scrise de server, `ProduseSimilare`, și cele refăcute din JavaScript: datele lor primesc `campanie_insigna`, iar rândul din
  șablonul JavaScript din `views/site/product.php` există doar cât e o campanie activă) și în banda
  `{{product_produse_similare}}` / `{{produse_serviciu}}` (`produse-categorie.php`); pe ultimele trei, ca o etichetă în corpul
  cardului (stilul lor vine din pagină sau din temă). Doar cu catalogul pe site. Pagina de prezentare de rezervă
  (`views/site/home.php`, fără pagina „acasă” în admin) nu o are.
- **Bannerul de sus**: textul, linkul (decizia proprietarului din 5 nov: **fără link scris, cât pagina campaniei e pornită,
  bannerul duce la pagina campaniei**, `/campanie/<slug>?inapoi=<pagina curentă>`, deci butonul „Înapoi” de acolo îl întoarce
  pe vizitator unde era; un link scris în campanie, o cale a site-ului sau `http(s)://…`, are întâietate), fundalul și culoarea textului, fontul
  (al site-ului; DM Sans, Playfair, Rubik, IBM Plex din nucleu, locale; Arial, Georgia, Verdana, al sistemului), mărimea (11–32
  px), lipit sus la derulare (`position: sticky`) sau nu; imediat după `<body>`, pe toate paginile publice (nu pe pagina
  campaniei). Editorul are o **previzualizare** care se schimbă cât scrii (un chenar care se derulează, ca să se vadă și
  „lipit”), plus a insignei. Un antet al site-ului lipit și el sus poate sta peste banner (`z-index` 1040): de verificat cu
  tema site-ului.
- **Oferta campaniei** (secțiunea „Oferta campaniei” din editor; `campanii.oferta_tip`, `oferta_n`, `oferta_m`, `oferta_procent`,
  `campanii_produse.pret_campanie`; `App\Support\OfertaCampanie`): o reducere fără cod, doar pe produsele alese ale campaniei,
  doar cât campania e activă (și cu magazinul pornit). Tipurile:
  - **„Al N-lea la −Y%”** (`al_n`; „1+1 −50%” = N 2, Y 50; eticheta „1+1 −50%”, altfel „Al N-lea la −Y%”);
  - **„N la preț de M”** (`n_la_m`, N > M ≥ 1; de exemplu 3 la preț de 2): cele mai ieftine N−M bucăți din fiecare grupă, gratuite;
  - **„−X%”** (`procent`) pe fiecare linie eligibilă;
  - **„Preț de campanie”** (`pret_fix`): prețul scris lângă fiecare produs în lista din editor, care **înlocuiește** prețul (nu
    se adaugă peste o reducere). La salvare: un preț (lipsă, 0 sau un text dau 422) mai mic decât prețul produsului (egal sau
    peste dă 422, „un preț mai mic decât prețul lui (100.00 lei)”, fiindcă nu s-ar aplica niciodată). Unul care nu e sub
    **prețul de acum** (un preț redus sau o perioadă de reducere în curs; editorul arată prețul de acum lângă câmp) se salvează,
    cu un **avertisment** în editor, „Prețuri de campanie care acum nu schimbă nimic” (produsul, prețul de campanie, prețul
    redus și cel de bază; `Campanii::avertismentPretCampanie`), care nu oprește salvarea: se poate pregăti o campanie pentru
    după o reducere temporară, iar o campanie se poate salva sau opri și după ce un produs a primit o reducere mai mare. În coș
    se aplică doar liniilor al căror preț e mai mare (prețul mai mic câștigă; `OfertaCampanie::eligibila`): A redus la 90 cu
    prețul de campanie 85 plătește 85; un lot BBD de 70 cu prețul de campanie 75 rămâne 70; o reducere pusă după salvare sub
    prețul de campanie (A redus la 80) rămâne 80. O linie cu prețul de campanie lipsă, egal sau mai mare **nu e a ofertei**:
    nicio reducere, nu aduce rândul ofertei în coș și primește cuponul ca orice alt produs (lotul de 70 cu DEMO10: −7,00).
  **Perechile și grupele** (`al_n`, `n_la_m`), deterministe: toate bucățile eligibile din coș (peste linii) se pun în ordinea
  prețului, de la cel mai scump; la preț egal, în ordinea liniilor din coș, apoi a bucăților; se iau câte N la rând, iar în
  fiecare grupă întreagă reducerea merge pe ultimele bucăți (cele mai ieftine ale grupei). Bucățile care nu fac o grupă întreagă
  rămân la preț întreg. **Rotunjirea**, în bani întregi (cenți): reducerea unei bucăți (`al_n`) sau a unei linii (`procent`) =
  prețul × procentul, rotunjit la cent, jumătatea în sus (de exemplu 45,99 la −50% = 23,00); gratuitatea și prețul fix sunt
  exacte. **Eligibile** (`OfertaCampanie::eligibila`): liniile produselor campaniei, și cele cu o reducere proprie (prețul
  redus, o perioadă în curs, lotul BBD ales); la prețul de campanie, doar cele cu un preț mai mare decât el (mai sus).
  **Reducerile se adună**: prețul unei bucăți e cel al liniei de dinainte de ofertă (același cu care se
  socotește linia, o singură dată), deci perechile și grupele se fac după prețurile reduse: „−20%” pe 90 (redus de la 100) =
  72,00, pe 3 × 33,33 = 99,99 → 79,99 (19,998 rotunjit la 20,00); „1+1 −50%” pe A redus de la 100 la 70 și B la preț întreg
  80: perechea (80, 70), reducerea 35,00 pe A (cel mai ieftin după prețul de acum); „3 la preț de 2” pe 100, 90 (redus) și un
  lot BBD de 70: lotul gratuit (70,00).
  **Calculul** (`CheckoutCalculator::buildSummary`): reducerea scade din prețul liniei înaintea TVA-ului, deci linia, prețul
  mediu al bucății, TVA-ul liniei (cu cota ei), subtotalul și totalul sunt cele de după ofertă; linia păstrează prețul și
  totalul de dinainte de ofertă (`campanie_pret_initial`, `campanie_total_inainte`; cele reduse, la o linie cu o reducere
  proprie) și reducerea (`campanie_reducere`), iar sumarul are `campanie_oferta` (textul „<campania>: <eticheta>”, reducerea
  totală, îndemnul, nota cuponului) doar cu cel puțin o bucată eligibilă în coș. **Transportul gratuit și punctele** socotesc
  oferta ca pe un cupon: pragul pe subtotalul de dinainte de ofertă, minus reducerile (oferta, cuponul, punctele) doar cu
  „shipping_include_coupons” (implicit da, deci pe ce se plătește); punctele (folosite și câștigate) pe subtotal minus cupon,
  adică pe prețurile de după ofertă, ca la un cupon. **Cuponul** nu se aplică niciunei linii eligibile pentru ofertă (redusă sau
  rămasă fără pereche; și o linie cu o reducere proprie a unui produs al campaniei e eligibilă, deci nu primește cuponul: se
  adună reducerea proprie și oferta, nu și cuponul; o linie la care prețul de campanie nu schimbă nimic nu e eligibilă și
  primește cuponul): se socotește doar pe produsele din afara campaniei (subtotalul eligibil, ca la un cupon „fără cumulare”),
  iar coșul și checkout-ul spun „Cuponul nu se aplică produselor din oferta campaniei”. Dacă, fără produsele ofertei, cuponul nu
  mai are pe ce să se aplice, e **refuzat** cu mesajul „Cuponul nu se aplică produselor din oferta campaniei, iar în coș nu sunt
  alte produse pe care să se aplice.”
  (`CheckoutCalculator::MESAJ_CUPON_OFERTA`): la aplicare (scos din coș, cu mesajul) și la trimiterea comenzii (comanda nu
  se plasează, clientul e întors în coș cu mesajul), deci nu se atașează comenzii și nu consumă un cupon unic sau o
  folosire din `max_uses_total`. **Îndemnul** „Mai adaugă un produs din
  ofertă ca să primești reducerea” (sub sumar, în coș, coșul plutitor și checkout) cât lipsește o bucată pentru prima grupă
  (cu mai multe lipsă: „Mai adaugă N produse …”). **Afișarea**: pe fiecare linie redusă, prețul de dinainte tăiat și „<eticheta>:
  −X lei”; în sumar, rândul „<campania>: <eticheta> (inclusă în prețuri) −X lei” (în pagina coșului, coșul plutitor,
  checkout, emailurile comenzii, sub produse, o singură dată chiar dacă șablonul are și `{{order_items_html}}`, și
  `{{order_summary_rows}}`, și Admin → Comenzi, cu reducerea pe linie). **Pe comandă** (separat de INSERT,
  ca o comandă fără ofertă să se scrie exact ca înainte): `orders.campanie_id`, `campanie_oferta` (textul, înghețat),
  `campanie_reducere`; pe linii `order_items.campanie_reducere`, `campanie_pret_initial`, `line_total` redus și `unit_price` =
  media la cent. **ERP**: cuponul pleacă azi pe comandă (`discountCupon`, o linie de serviciu la cota dominantă), iar ERP-ul
  ține o singură linie pe produs (două linii pe același produs opresc aprobarea), fără reducere pe linie la primire; de aceea
  oferta nu pleacă ca un cupon, ci în prețul liniei: `pretUnitar` = totalul redus al liniei / cantitate, cu 4 zecimale (cât ține
  ERP-ul), deci factura are prețul plătit și TVA-ul cu cota fiecărui produs; `total` e al comenzii. Pentru cantități sub 100,
  totalul liniei din ERP iese la cent cel al site-ului. Liniile fără ofertă pleacă exact ca înainte. **Admin → Comenzi**: produsele unei
  comenzi cu ofertă **nu se editează** (`POST /admin/orders/{id}/items` răspunde „Comanda are oferta campaniei: produsele nu
  se pot modifica aici. Anulează comanda și plaseaz-o din nou, sau modific-o în ERP.”, iar editorul arată liniile doar de
  citit, cu același text): refacerea liniilor le-ar reprețui fără ofertă. Reducerea comercială și destinația (FANbox, ridicare)
  se schimbă ca la orice comandă; transportul lor recalculat socotește oferta ca în coș (subtotalul de dinainte de ofertă,
  oferta printre reduceri). **Din ERP** (o comandă schimbată acolo și trimisă înapoi): liniile primite sunt cele facturate
  de ERP (prețul și totalul lor, deci sumele rămân corecte), iar coloanele ofertei rămân pe liniile păstrate; textul ofertei
  de pe comandă poate rămâne cel de la plasare (doar informativ). Fără ofertă aleasă (implicit) sau fără campanie activă, coșul,
  comanda și ERP-ul sunt octet cu octet cele de dinainte (sumarul coșului nu are nicio cheie nouă).
- **Cache-ul paginilor**: cheia primește `|campanie:<id>:<updated_at>` doar cât e o campanie activă (fără ea, cheia e exact
  cea de dinainte); salvarea și ștergerea golesc cache-ul. Un CDN care ține pagini HTML întregi pentru vizitatori le-ar servi
  fără PHP (atunci nici trimiterea, nici bannerul nu se schimbă până expiră copia).
- **Fără campanie activă** (cazul de pe orice site azi): o singură interogare pe cerere (cu pluginul pe site), niciun octet
  schimbat în pagini (comparația cu Bioscem, G-cmp, pe licența „toate”, care îl are pornit).
- **Are nevoie de**: nimic (insigna, de catalog). **Se oprește**: ca pornit (nu începe nimic cu bani). **Oprit**: `/campanie/…`
  404, adminul 403, meniul dispare, nicio trimitere, insignă sau banner; campaniile rămân în bază, la repornire revin.
- **Folosirea recomandată** (proprietarul a acceptat prudența SEO pe 5 nov): pagina campaniei doar pe **perioade scurte** (zile,
  o campanie anume); pentru o promoție lungă, doar bannerul și insignele (debifat „Arată pagina campaniei la prima intrare”).
  Detaliile: [SEO.md](SEO.md), §5b, „Pagina campaniei (interstițial)”. Adminul rămâne doar al administratorului general.

### Pachete de produse (`pachete_produse`)

Din Biovitality (`90afd46`, `83067d3`, `afc6fd7`, `7391226`; „ultimul commit revizuit” `d95598e`). `App\Support\PacheteProduse`,
`PacheteProduseController`, migrarea `pachete_produse/0055`. Pe Biovitality (`licente_dev.biovitality`, deci și licența copiei de
verificare) și pe „toate”; pe niciun alt site și în niciun pachet de abonament (îl pune CRM-ul în licență doar unui site care
vinde pachete).

- **Un pachet e un produs ca oricare altul**: prețul (cu reducerea și perioadele ei), TVA-ul, stocul (și cel din ERP), greutatea,
  transportul gratuit, cuponul, punctele, linia comenzii și ce pleacă în ERP sunt ale lui, deci coșul, checkout-ul, comanda și
  ERP-ul îl socotesc exact ca pe orice produs (ca în codul Biovitality, nu se desface în componente). În plus știe din ce produse
  ale magazinului e făcut: `products.bundle_items_json`, `[{"id": <produs>, "qty": "<cantitatea, text>", "desc": "<descrierea
  proprie>"}]`, în ordinea din editor, cel mult 12, fiecare produs o dată.
- **Conține**: în editorul produsului (Produse, secțiunea „Câmpuri suplimentare”), caseta „Conținutul pachetului” (produsul ales
  din listă, cantitatea, descrierea proprie, ordinea ↑ ↓, ✕), arătată doar când șablonul ales folosește un slot
  `{{bundle_item_…}}`; salvată într-o instrucțiune separată, doar când formularul a trimis câmpul. În șablonul produsului, sloturile
  `{{bundle_item_N_name}}`, `_desc` (descrierea proprie, altfel descrierea scurtă a produsului), `_qty`, `_url`, `_image_url`,
  `_image` (un `<img>` gata făcut; restul se escapează) pentru N = 1…12 și `{{bundle_items_count}}`: numele, poza și descrierea se
  citesc din produsul ales la fiecare afișare, un produs șters e sărit, sloturile necompletate ies goale. În coș (pagina coșului și
  coșul flotant), pe linia unui produs care intră în pachete, caseta „Face parte dintr-un pachet” / „Face parte din N pachete”:
  fiecare pachet activ, cu stoc și care nu e deja în coș, de la cel mai ieftin (prețul redus, altfel prețul), cu poza, „Vezi
  pachetul” și „Schimbă în pachet” (`POST /cos/schimba-pachet`, formularul coșului, butonul cu „cheie:idPachet”; `POST
  /api/cart/swap-bundle`, JSON, pentru coșul flotant): produsul iese din coș și intră pachetul, o bucată, după ce perechea e
  verificată în bază (pachetul activ chiar conține produsul: altfel 422) și pachetul ca la „Adaugă în coș” (găsit, nu epuizat, în
  limita stocului din ERP; altfel 404/409, cu mesajele codului Biovitality). Stilurile casetei stau în
  `public/assets/css/pachete-produse.css` (regulile din `app.css` al codului Biovitality), încărcată doar când un produs din coș
  are pachete (pagina coșului o pune înaintea listei, coșul flotant o cere din răspunsul lui, `pachete_css`); `bundle_hints` apare
  în liniile sumarului și ale coșului flotant doar unde sunt propuneri. Cu niciun pachet pe site (azi pe orice site în afară de
  Biovitality), paginile, coșul și răspunsurile lui sunt octet cu octet cele de dinainte (G-cmp).
- **Paginile cu pachete** (`/pachete` la Biovitality): pagini din Conținut → Pagini cu `{{products_by_template:…}}` (al catalogului,
  WP-19), în cardurile catalogului.
- **Are nevoie de**: magazin. **Se oprește**: ca pornit (nu începe nimic cu bani; „Schimbă în pachet” e refuzat oricum cât magazinul
  se oprește, „Momentan nu se mai pot plasa comenzi online.”). **Oprit**: nicio casetă în coș și în editor, adresele de schimbare
  404, sloturile șablonului ies goale; produsele-pachet rămân produse obișnuite (se vând, cu prețul lor), conținutul lor rămâne în
  bază, neatins la salvarea produsului.
- **Dovada**: `tools/dev/test-calcule-bv.php` (în G-calc) față de codul Biovitality la `d95598e`, pe o bază făcută ca a lui, cu
  pachete inventate: calculele coșurilor cu pachete (și pachetele propuse pe linii), pagina pachetului, cardurile, caseta din coș,
  coșul flotant și „Schimbă în pachet”, și în Chromium; pe copia de verificare, `compara-calcule.php --profil=biovitality`.

### Fișiere în cloud (`fisiere_cloud`)

Din Biovitality (`f5486ee` … `f9b4d78`, 26–31 august; „ultimul commit revizuit” `d95598e`), portat pe 6 noiembrie, după adoptarea
biovitality.ro (pe codul comun, adresele lui dădeau „Modulul nu este inclus în abonament” sau 404). `App\Support\BunnyStorage`,
`FileLocks`, `FileVersions`, `FolderPasswords`, `AppTokens` (copiate cum sunt, fără `ensureSchema`), `App\Support\FisiereCloud`,
`FisiereCloudController` (metodele `bunny*` ale lui `AdminController` din Biovitality), `DesktopApiController` (neschimbat), migrarea
`fisiere_cloud/0059`, stilurile în `public/assets/css/fisiere-cloud.css` (regulile `bunny-*` ale `app.css` din Biovitality, încărcate
doar pe paginile lui). Pe Biovitality (`licente_dev.biovitality`, deci și licența copiei de verificare) și pe „toate”; în niciun pachet
de abonament (`client`: îl pune CRM-ul în licență doar unui site care îl folosește).

- **Conținut → bunny.net** (`/admin/bunny`): folderele și fișierele zonei de storage bunny.net (Storage Zone; adresa publică din Pull
  Zone), căutarea în folder, folder nou (marcajul ascuns `.bunny-folder`: Bunny n-are „mkdir”), urcarea (numele curățat de diacritice
  și spații, un nume luat primește „-1”), ștergerea și redenumirea (fișier sau folder: Bunny n-are redenumire, deci copiere +
  ștergere; un folder cu peste 400 de elemente se refuză; dacă ceva pică la mijloc, originalul rămâne neatins), trimiterea pozelor și
  clipurilor în Galerie (cu adresa de pe CDN). **Preluarea pentru editare**: lacătul pe fișier (cine, de când, nota), expiră după
  `bunny_lock_expire_hours` (24); doar cel care l-a preluat salvează versiunea nouă, iar copia de dinainte merge în `_versiuni/<cale>/
  <AAAALLZZ-HHMMSS>-<nume>` (se păstrează `bunny_versions_keep`, 10); arhiva `_versiuni` nu se redenumește, nu se șterge și nu se
  scrie nimic în ea de aici (nici din aplicație: o scrie doar codul care face copiile);
  Administratorul General eliberează forțat (scris în Jurnal activitate). Descărcarea trece prin server, cu cheia zonei (nu prin
  adresa publică). **Folderele cu parolă**: parola (`password_hash`, cel puțin 6 caractere, indiciu opțional) apără tot ce e înăuntru,
  oricât de adânc, și copiile lui din `_versiuni` (câștigă folderul cel mai adânc; de aceea parola unui subfolder o pune doar cine are
  deschise folderele de deasupra; căile se compară fără litere mari); deschiderea ține o oră de la ultima folosire, legată de sesiune
  (browserul) sau de cheia de aplicație; 5 parole greșite = 5 minute de pauză, numărate pe administrator și protecție (oricâte
  sesiuni sau chei ar avea; cheia e id-ul protecției, deci nici o redenumire nu șterge numărătoarea); schimbarea parolei închide folderul pentru toți; o parolă se pune doar pe un folder și se schimbă sau se scoate
  doar cu cea veche, verificată prin aceeași frână (Administratorul General poate fără ea, scris în jurnal); redenumirea mută parola
  după folder, literă cu literă (exact calea mutată), ștergerea o șterge (un folder cu un subfolder cu parolă închis nu se șterge
  și nu se redenumește; aici literele mari nu contează, ca să refuze mai mult). Dacă folderul a primit o parolă între afișare și
  trimiterea formularului „Pune parolă”, cererea se refuză (nu scrie peste ea). Într-un folder cu parolă nu se arată
  adresele publice și miniaturile de pe CDN, iar în Galerie nu pleacă nimic (nici deschis). Dacă lista folderelor cu parolă nu se poate
  citi, totul e închis. E o barieră între colegi: fișierul din bunny.net rămâne necriptat, iar adresa lui publică merge pentru cine o
  știe.
- **Aplicația de desktop** (`/admin/bunny/app`): fiecare administrator își face chei (`bvd_` + 48 hex, arătate o singură dată; în bază
  rămâne doar amprenta sha256), câte una pe calculator (cel mult 10 active), și le retrage; Administratorul General le vede și le
  retrage și pe ale celorlalți (un calculator pierdut); butonul de descărcare a programului din zonă
  (`bunny_app_download_path`). **API-ul aplicației Windows** (`/api/desktop/*`: ping, files, locks, download, checkout, upload,
  release, release-all, upload-new, rename, folders, delete, folders/unlock, folders/lock; `Authorization: Bearer` sau `X-App-Token`,
  fără sesiune; drepturile adminului care a făcut cheia, `Auth::canAccessPath('/admin/bunny')`): adresele, codurile de stare (200, 401,
  403, 409, 413, 422, 423, 502, 503), antetele și cheile JSON sunt exact ale codului Biovitality, deci aplicația instalată
  (`desktop/BiovitalityFiles` din repo-ul Biovitality) merge neschimbată. Garda căilor: orice cale trece prin
  `BunnyStorage::normalizePath` (fără `..`, `.`, segmente goale, `\`), deci nu iese din zonă.
- **Setări → bunny.net** (`/admin/settings/bunny`, doar administratorul general): zona, parola ei (păstrată când rândul e lăsat gol,
  arătată doar cu ultimele 4 caractere), regiunea, adresa CDN, dosarul de bază, „Testează conexiunea”, „Detectează zonele din cont”
  (cu cheia API a contului; parolele zonelor găsite rămân 15 minute în sesiune, pe server, iar formularul trimite doar numărul zonei), ținta încărcărilor din admin (`bunny_upload_target`: „În bunny.net (CDN)” trimite pozele urcate din
  Galerie, produse, blog direct în zonă, `galerie/<secțiune>`; dacă Bunny nu răspunde, rămân pe server), orele lacătului, versiunile
  păstrate, locul programului. Setările stau în `settings` (`bunny_*`; fără valori implicite în `Settings::DEFAULTS`: rezervele din cod
  sunt cele ale codului Biovitality, iar pe Biovitality înghețarea le-a scris la adoptare).
- **Rolul „Cloud user”** (`Auth::ROLE_CLOUD` = `utilizator_cloud`): doar `/admin/bunny` (fișierele, aplicația de desktop), ajunge acolo
  după logare. Cele patru roluri ale Bioscem au și ele `/admin/bunny`, iar „Administrator magazin” și `/admin/competition`, ca în
  codul Biovitality. Rolul e cunoscut pe orice site (altfel `Auth::normalizeRole` l-ar face Administrator General); în Administratori se
  oferă doar cu pluginul pe site, iar un cont care îl are îl păstrează la salvare (câmp ascuns). **De știut:** migrarea de date
  `nucleu/0020` (rolurile adminilor, din WP-03) știe doar cele patru roluri, deci la adoptarea Biovitality (5 noiembrie) orice cont
  „Cloud user” a devenit Administrator General în bază (`role` și `roles_json` rescrise). Lista ei de roluri stă doar în 0020, care e
  publicată și aplicată (o schimbare ar da codul 12 pe fiecare site) și nu mai rulează pe nicio bază pe care a rulat; în rest, codul
  (`Auth::roles`, și rezerva `Auth::ensureSchema`) știe rolul, deci nimic nu-l mai rescrie. **Rolul Cloud user după adoptare**: îl pune
  la loc `scripts/restaureaza-roluri-cloud.php` (prin `bin/platforma`, ca celelalte scripturi), din copia de dinainte
  (`--copie=<…>/copii/<data>/baza-inainte.sql.gz`): citește din ea doar rândurile din `admins` (trecute rând cu rând, fără s-o
  încarce), arată „email: acum <roluri> → <roluri vechi>”, iar cu `--aplica` scrie, într-o tranzacție, role și roles_json de atunci
  doar conturilor pe care 0020 le-a lăsat cum le-a lăsat (cele schimbate de mână după adoptare și cele șterse doar se arată) și retrage
  cheile de aplicație ale acestor conturi făcute după 5 noiembrie, 20:00 (`--din=`); fără hash-uri de parolă în ieșire, un rând în
  Jurnal activitate. Sesiunile deja deschise țin rolul de la logare: după el, oamenii se deloghează și se loghează din nou.
- **CSRF** (în plus față de codul Biovitality, care se baza doar pe cookie-ul `SameSite=Lax`): fiecare POST din admin cere jetonul
  formularului (`App\Support\AdminCsrf`); fără el, nimic nu se face („Sesiunea formularului a expirat…”). O cerere mai mare decât
  `post_max_size` (PHP îi aruncă tot corpul, cu jeton cu tot) primește „Fișierul e prea mare”; din browser, ca în aplicație, cel mult
  64 MB pe fișier. API-ul aplicației nu are sesiune, deci nici cookie: cheia din antet e singura dovadă.
- **Ce s-a strâns după revizia de securitate** (6 noiembrie, înainte de pornire): copiile din `_versiuni` păzite de parola
  originalului și ascunse din listă cât folderul e închis (browser, aplicație, Galerie); căile cu alte litere mari (`Contracte/…`)
  păzite la fel; parola unui subfolder doar cu folderele de deasupra deschise, doar pe foldere; parola actuală (schimbare, scoatere)
  prin frâna deblocării; frâna pe administrator și folder, cu o scriere atomică înaintea verificării (10 cereri trimise deodată primesc
  tot cel mult 5 verificări; numărătoarea ține de protecție, nu de cale); redenumirea unui folder cu un subfolder cu parolă închis se
  refuză ca ștergerea, iar protecțiile se mută doar pe calea mutată exact (altfel `a` → `b` lua protecția lui `A/secret`); Galeria verifică fiecare cale bifată și refuză tot ce e într-un folder cu parolă; ștergerea unui folder cu
  un subfolder cu parolă închis se refuză; fără adrese CDN și miniaturi într-un folder cu parolă; nimic scris de mână în `_versiuni`;
  parolele care nu se pot citi închid tot (în aplicație 503, în browser „Nu pot verifica acum parolele folderelor”); textele din
  `confirm()` (numele folderului, emailul din lacăt, numele partenerului la `concurenta`) ca JSON, nu ca șir care se poate închide;
  limita de 64 MB și mesajul la `post_max_size`; parolele zonelor detectate nu mai ajung în pagină; cel mult 10 chei active pe
  administrator, iar Administratorul General le retrage pe ale oricui; încărcările din admin nu merg într-un folder de galerie cu
  parolă (rămân pe server). **API-ul aplicației**: aceleași adrese și forme de răspuns; se schimbă doar cererile refuzate acum pe drept,
  cu coduri pe care aplicația le știe deja: 423 `folder_locked` (o copie din `_versiuni` a unui folder închis, ștergerea sau
  redenumirea unui folder cu un subfolder închis), 409 `archive` (scrieri în `_versiuni`), 503 (parolele nu se pot citi), iar pauza după greșeli ține pentru toate
  cheile aceluiași administrator. **Rămâne, de știut**: un fișier HTML sau SVG urcat în zonă se deschide de pe adresa CDN ca pagină, cu
  scripturile lui (pe domeniul CDN-ului, nu al site-ului, deci fără sesiunea adminului); fișierele de lucru nu se filtrează după tip,
  iar dacă trebuie, din bunny.net (Pull Zone → Edge Rules: `Content-Disposition: attachment` pentru `.html`, `.htm`, `.svg`). Tot
  neschimbate: un id de administrator refolosit după ștergerea contului (lacătele și cheile se leagă de id) și fereastra dintre
  verificarea și mutarea de la redenumirea unui folder.
- **Se oprește**: ca pornit (nu e nimic cu bani; fișierele în lucru se pot termina). **Oprit**: paginile dau „Modulul nu este inclus în
  abonament”, meniul și butonul din Galerie dispar, `/api/desktop/*` răspunde cu JSON-ul 404 al gărzii (cu `error`, pe care aplicația
  îl arată), încărcările din admin rămân pe server; tabelele, setările și fișierele din zonă rămân neatinse.
- **Schema**: `fisiere_cloud/0059`, adoptivă (definițiile codului Biovitality, `bv.sql`): pe biovitality.ro tabelele există cu
  rânduri și migrarea le găsește („detectata”, 0 operații). Pe copia de verificare: doar structura, bunny.net oprit și fără chei
  (regulile Bioscem 22, `tools/verificare/reguli/bioscem.php`).
- **Dovada**: `tools/dev/test-fisiere-cloud.php` (în testul de fum, pe „toate”), față de codul Biovitality la `d95598e`, pe două baze
  făcute ca a lui și câte o zonă bunny.net falsă (`tools/dev/lib/bunny-fals.php`): A adoptarea (cele cinci tabele, rândurile și
  setările `bunny_*` neschimbate de migrări); B aceleași 90 de cereri ale aplicației pe ambele coduri (fiecare adresă, Bearer și
  X-App-Token, JSON și multipart, parola de folder cu frâna, lacătele, versiunile, redenumirile, integrarea oprită): același cod,
  același Content-Type, același JSON octet cu octet (momentele înlocuite), același fișier descărcat, apoi aceleași fișiere în zonă,
  aceleași rânduri în tabele și în jurnal, aceleași cereri spre Bunny (singura cerere cu alt răspuns, declarată: ștergerea unui folder
  cu un subfolder cu parolă închis, 409 → 423; pauza după greșeli e pe administrator, nu pe cheie); C doar pe codul comun: pluginul
  oprit, CSRF, „Cloud user”; D revizia de securitate, punct cu punct; plus, în A, scriptul de roluri pe o copie făcută ca la adoptare.

### Analiza prețurilor concurenței (`concurenta`)

Din Biovitality (`649c837`, `7ee1a86`, `729d6fa`, `ea927aa`, 20 august – 4 septembrie; „ultimul commit revizuit” `d95598e`), portat pe
6 noiembrie, după `fisiere_cloud`. `ConcurentaController` (metodele `competition*` ale lui `AdminController` din Biovitality), vederea
`views/admin/competition.php` (stilurile și scripturile ei, în pagină, ca în codul Biovitality), migrarea `concurenta/0060`. Prețul
nostru vine din `AdminController::pretulProdusului` (aceeași regulă ca lista produselor: prețul redus și perioadele lui). Pe Biovitality
(`licente_dev.biovitality`) și pe „toate”; în niciun pachet de abonament (`client`).

- **Produse → Analiza competiție** (`/admin/competition`; administratorul general și „Administrator magazin”, ca în codul Biovitality):
  partenerii (concurenții) ca o bandă de carduri (numele, site-ul, o perioadă implicită de campanie, activ sau nu, ordinea; editare,
  ștergere cu tot cu prețurile lui), iar în tabel câte o pereche de coloane pentru fiecare partener activ: prețul lui și diferența
  față de prețul nostru (RON și %, mai ieftin / mai scump). Prețul care contează la partener e cel de campanie dacă e bifată la acel
  produs, completată și perioada ei (sau, fără ea, perioada implicită a partenerului) acoperă ziua de azi; altfel prețul normal. Plus,
  pe celulă: „Out of stock”, „Doar în magazin” (nu e pe site-ul lui, dar e în magazinul fizic), linkul produsului. Fiecare câmp se
  salvează singur (`POST /admin/competition/cell`, JSON: câmpul, valoarea curățată: sumele cu virgulă sau punct, un preț 0 = „neaflat”,
  datele ISO, linkul cu `https://` pus dacă lipsește); „Salvează tot” (fără JavaScript) scrie tot formularul într-o tranzacție.
  „Golește tabelul” șterge toate prețurile (partenerii rămân). **Exportul Excel** (`GET /admin/competition/export`): foaia „Comparație
  prețuri” (produsele, prețul nostru și, pe fiecare partener activ, prețul, diferența, campania, perioada, stocul, linkul) și foaia
  „Parteneri”.
- **Reparat față de codul Biovitality**: bifa „Doar în magazin” se citea din bază, dar nu ajungea în celulă (`competitionPricesMap`),
  deci după reîncărcare apărea mereu goală, „Salvează tot” o scria înapoi 0, iar exportul arăta „Disponibil”; acum se vede, se păstrează
  și apare în export.
- **CSRF** (în plus față de codul Biovitality): fiecare POST cere jetonul formularului (`_csrf`; la celule antetul `X-CSRF-Token`, trimis de
  scriptul tabelului; fără el celula răspunde 403 JSON). Numele coloanei scrise de o celulă vine doar din lista fixă a câmpurilor; restul
  SQL-ului e cu parametri.
- **Are nevoie de**: catalog (produsele). **Se oprește**: ca pornit. **Oprit**: pagina dă „Modulul nu este inclus în abonament”, intrarea
  din meniu dispare; partenerii și prețurile rămân în bază.
- **Schema**: `concurenta/0060`, adoptivă (definițiile codului Biovitality, cu coloanele adăugate de ALTER-urile lui): pe biovitality.ro
  „detectata”, 0 operații. Pe copia de verificare: copiate cum sunt (regulile Bioscem 23).
- **Dovada**: `tools/dev/test-concurenta.php` (în testul de fum, pe „toate”), față de codul Biovitality la `d95598e`, pe două baze făcute
  ca a lui: A adoptarea; B aceeași parte a paginii sub antet (cardurile, tabelul, prețurile efective), octet cu octet, și aceleași răspunsuri
  și rânduri după fiecare acțiune (partenerii, celulele, „Salvează tot”, exportul celulă cu celulă, ștergerea, golirea, rolurile); C bifa
  „Doar în magazin”, singura diferență, declarată pe celula ei; D CSRF și pluginul oprit, doar pe codul comun.

### Statistici site (`statistici`)

Cerut pentru Biovitality (8 noiembrie 2026, după un video viral: traficul pe ore din TikTok, Instagram și Facebook și comenzile
venite de acolo), făcut generic. `App\Support\Statistici` (colectarea, sarea, clasificările), `StatisticiAgregare`, `StatisticiRaport`,
`StatisticiController`, vederile `views/admin/statistici*.php`, cronul `scripts/statistici-agregare.php`, migrarea `statistici/0061`,
testele `tools/dev/test-statistici.php` (în testul de fum, pe „toate”) și `tools/dev/statistici-browser.cjs`. **Oprit pe orice site**: nu
e în niciun pachet și în nicio licență de dezvoltare (`licente_dev`); îl pune doar CRM-ul în licență (`"statistici": "pornit"`). Pentru
teste: `tools/dev/php.sh tools/dev/licenta.php biovitality statistici=pornit`.

- **Fără cookie-uri, fără stocare în browser, fără amprentare.** Un vizitator e, pe o zi, `sha256(sarea zilei + site + IP + User-Agent)`
  (primii 16 octeți). Sarea: 32 de octeți aleatori în `storage/statistici/sare-AAAALLZZ.bin` (0600), făcută la prima cerere a zilei
  (atomic, cu `link`) și ștearsă a doua zi (la prima cerere a zilei noi și la fiecare rulare a cronului). Adresa IP (`IpClient`) și
  User-Agent-ul nu se scriu nicăieri: doar familia browserului (inclusiv „TikTok (aplicație)”, „Instagram (app)”, „Facebook (aplicație)”; până pe 9 nov, „Instagram (aplicație)” avea 21 de caractere, peste coloana de 20, deci un server strict nu scria rândul), a sistemului și
  felul dispozitivului (desktop, telefon, tabletă), plus treapta lățimii ecranului. Fără consimțământ (nu se citește și nu se scrie nimic
  pe dispozitivul vizitatorului), deci se numără toți vizitatorii, și cei care n-au acceptat cookie-urile.
- **Nu se numără**: administratorii logați (scriptul nu e pus în pagina lor; punctul de primire citește sesiunea doar dacă browserul are
  deja cookie-ul ei, fără sesiune nouă), roboții (`Campanii::eRobot`: Googlebot, previzualizările de linkuri ale rețelelor, unelte,
  HeadlessChrome, fără User-Agent), browserele automatizate (`navigator.webdriver`), preîncărcările (`Sec-Purpose: prefetch`; scriptul
  așteaptă `prerenderingchange` și o filă vizibilă), „Do Not Track” și „Global Privacy Control” (setarea `statistici_respecta_dnt`,
  implicit da, în Rapoarte → Statistici → Setări).
- **Scriptul** (`Statistici::scriptPagina`, agățat de `layout.final_body`, 1,4 KB, în HTML, deci merge și pe o pagină servită din cache-ul
  de pagini sau dintr-un CDN): la pagina văzută trimite cu `navigator.sendBeacon` (altfel `fetch` cu `keepalive`) `{e:"v", p: calea fără
  parametri, r: document.referrer, w: lățimea, u: utm_source/medium/campaign/content/term, c: semnele gclid/gbraid/wbraid (g), fbclid (f),
  ttclid (t), q: are ?q=, t: felul paginii, i: id-ul produsului}`; la ascunderea filei sau la plecare, `{e:"l", p, d: secundele active}`.
  Nimic altceva din adresă (niciun alt parametru, niciun email din adresă). Pe o pagină de eroare (404, 410, 503) scriptul nu se pune,
  deci o adresă care nu există nu ajunge în statistici; nici pentru administratorul logat.
- **Punctul de primire** `POST /api/statistici` (`Statistici::intercepteaza`, din `public/index.php`, după citirea setărilor și înaintea
  sesiunii: niciun cookie, niciun fișier de sesiune, nicio redirecționare SEO; ruta e și în router, a pluginului, pentru gardă): `Origin`
  al site-ului (fără el, `Sec-Fetch-Site: same-origin` sau un Referer al site-ului), altfel 403; corpul cel mult 2 KB (413), JSON validat
  (400); cel mult 120 de evenimente pe minut de la o adresă (`Statistici::cheieIp`: sarea zilei + adresa, IPv6 cu toată rețeaua /64, fără
  browser, deci un robot care își schimbă User-Agent-ul tot cade în ea; 120, nu 60, fiindcă în spatele unei adrese de operator mobil stau
  mulți oameni), altfel 429; plafoanele întregului site, numărate într-un fișier sub `flock` (`storage/statistici/contor.json`, fără
  interogare): `statistici_max_minut` pe minut (implicit 1500; de ridicat înaintea unui vârf așteptat) și `statistici_max_zi` pe zi (implicit
  200 000), ambele în Setări; peste ele evenimentul se aruncă (204); utm-urile în
  litere mici, cel mult 100 de caractere, cele cu „@” aruncate; un singur `INSERT`; răspunsul 204, `Cache-Control: no-store`; orice
  eroare e prinsă (500/503 fără corp), deci nu oprește și nu încetinește pagina. Nu are nevoie de excepție CSRF: nu folosește sesiunea.
- **Pe server**, cu același hash calculat din cerere: adăugarea în coș (`SiteController::cartAdd`, `cartItemAddApi`, înlocuirea cu un
  pachet), pagina checkout, comanda plasată (`Statistici::laComanda`, lângă `SursaComenzii::salveaza`: și hash-ul pe comandă, în
  `orders.statistici_vizitator`), căutarea de produse din antet (`/api/search/products`: textul normalizat — litere mici, spațiile strânse,
  100 de caractere — și numărul de rezultate; literele scrise una după alta, la cel mult 90 de secunde, devin o singură căutare). O căutare
  care pare o dată personală (un „@” sau 6 cifre la rând, și cu spații sau liniuțe între ele: email, telefon, CNP, număr de comandă sau de
  card) nu se păstrează deloc, și nici începutul ei scris până atunci (evenimentul ultimei căutări din 90 de secunde care e începutul
  ei se șterge). În agregatele zilei rămân doar termenii a cel puțin 2 vizitatori diferiți ai zilei (cel mult 200); restul „(altele)”,
  care nu se arată. Evenimentele nu au id-ul comenzii.
- **Vizita**: același vizitator, cel mult 30 de minute între două evenimente (sarea se schimbă la miezul nopții, deci o vizită nu trece în
  ziua următoare). Sursa ei e a primei pagini: întâi semnele de reclamă (gclid → Google Ads, ttclid → TikTok, fbclid → Facebook sau
  Instagram), apoi utm (cpc/ppc/paid + google → Google Ads; email/newsletter → Email; facebook, fb, ig, instagram, tiktok, youtube,
  pinterest, linkedin, twitter/x, whatsapp, bing; alt `utm_source` → „Campanie (utm)”, cu sursa și mediul ca detaliu), apoi Referer-ul
  (Facebook cu l./m./lm.facebook.com, Instagram, TikTok, YouTube, Pinterest, LinkedIn, X/t.co, WhatsApp/wa.me; Google organic; Bing;
  DuckDuckGo, Yahoo, Yandex, Ecosia, Brave, Baidu… → „Alte motoare de căutare”; webmailurile → Email; aplicațiile Android
  `android-app://`; orice alt domeniu → „Alte site-uri”, cu domeniul), apoi browserul aplicației (TikTok, Instagram și Facebook deschid
  linkul în browserul lor, adesea fără Referer: se recunosc după User-Agent), altfel „Direct”. O pagină cerută din site la începutul unei
  vizite noi = Direct. „Angajată” = cel puțin 2 pagini, 10 secunde active, o adăugare în coș sau o comandă; „rata de respingere” = restul.
- **Comanda** se atribuie ultimei vizite a vizitatorului din aceeași zi, de dinaintea comenzii, care n-a fost Direct (ca Google Analytics);
  fără ea, vizitei în care a comandat; fără nicio vizită (scriptul blocat de browser), „Necunoscută”. O comandă plasată a doua zi după
  vizită nu se poate lega (hash-ul s-a schimbat): e prețul lipsei cookie-urilor. Comenzile și veniturile sunt cele de acum din `orders`:
  fără cele șterse, anulate, eșuate, rambursate, returnate sau neplătite încă (`AcasaAdmin::FARA_VANZARE`). Lângă ele, tabelul „Sursa
  comenzilor (cookie, cu acord)” arată `orders.sursa` (WP-40) pentru perioada aleasă.
- **Agregarea** (`StatisticiAgregare`): fiecare zi neîncheiată se reface întreagă (șterge și scrie, într-o tranzacție, cu lacătul
  `GET_LOCK`) în `statistici_vizite` (o vizită pe rând, cu vizitatorul doar ca număr de ordine al zilei), `statistici_pagini`,
  `statistici_produse`, `statistici_cautari` (pe zi și sursă) și `statistici_comenzi` (atribuirea); `statistici_zile` ține ce s-a agregat.
  Evenimentele zilei se citesc pe 16 bucăți, după primul octet al hash-ului (un vizitator e întreg într-o bucată), deci memoria nu crește cu
  traficul. **Gunoiul nu ajunge în agregatele păstrate**: o cale văzută de un singur vizitator (sau peste primele 500 ale zilei) devine
  „(altele)”, și ca pagină de intrare; un produs care nu e în catalog se sare. O zi agregată după 00:15 a zilei următoare e încheiată: nu
  se mai reface, hash-ul ei se golește de pe comenzi (după cheia primară, în tranzacții scurte) și evenimentele „comandă” ale zilei se
  șterg, iar cheia limitei pe adresă (`ip_cheie`) se golește, deci după încheiere nimic nu mai leagă o comandă de evenimentele brute
  (atribuirea rămâne în `statistici_comenzi`: doar sursa, detaliul, campania și dispozitivul, fără vizita ei). În `statistici_vizite`
  vizitatorul e un număr aleator de 32 de biți, unic în zi și altul la fiecare agregare (doar pentru numărarea vizitatorilor unici),
  deci nu spune ordinea sosirii.
  Cronul agregă zilele neîncheiate și șterge evenimentele brute mai vechi de `statistici_zile_brute` zile (implicit 30; agregatele
  rămân). Pagina din admin agregă la deschidere doar dacă agregarea a rămas în urmă de peste 2 ore (cronul lipsește), cel mult 2 zile pe
  cerere, cu o limită de timp; dacă rulează altă agregare sau nu merge, arată ultimele date cu nota „Statisticile se calculează”, niciodată o
  eroare. Cu cronul la oră, cifrele de azi sunt de cel mult o oră („Acum pe site” e mereu din evenimentele brute).
- **Tab-urile** (din 9 nov, la cererea proprietarului; `?tab=`, cu perioada și filtrele păstrate la schimbare, deci se pot pune în
  semne de carte): Prezentare (cifrele, „Acum pe site”, graficele, primele 5 din surse, pagini, produse, căutări), Surse (și campaniile
  UTM), Pagini și produse, Căutări, Vânzări (comenzile, conversia și veniturile pe sursă, cu graficele lor), Dispozitive, Impact postare,
  Setări (`/admin/statistici/impact` și `/admin/statistici/setari` rămân și ele). Perioada din butoane (Azi, Ieri, 7, 30, 90 de zile,
  Personalizat); comparația, un buton. Fiecare cifră cu culoarea și iconița ei și diferența ca o pastilă verde/roșie; fiecare sursă cu
  insigna ei (TikTok, Instagram, Facebook, Google, Google Ads, email, direct…); în tabele, bara cotei din total în spatele rândului;
  graficele cu explicația la trecerea cu mouse-ul (sau la atingere); „Acum pe site” cu punctul verde și paginile și sursele ca etichete;
  o perioadă fără vizite arată un mesaj („Încă nu sunt vizite înregistrate…”, și că administratorii logați nu se numără), nu zerouri.
- **Rapoarte → Statistici** (`/admin/statistici`; administratorul general, „Administrator magazin” și „Administrator emailuri”): perioada
  (azi, ieri, 7/30/90 de zile, altă perioadă) și comparația cu perioada dinainte; „Acum pe site” (vizitatorii din ultimele 5 minute, cu
  paginile și sursele, reîmprospătat la 30 de secunde); cifrele (vizitatori unici, vizite, pagini, pagini/vizită, durata medie, rata de
  respingere, adăugări în coș, comenzi, rata de conversie, venituri); graficul vizitelor și al veniturilor pe ore (1–2 zile) sau pe zile
  (SVG în pagină, fără bibliotecă); tabelele, sortabile la clic pe antet, cu exportul CSV fiecare (`;`, UTF-8 cu BOM): Surse (cu detaliile
  la ▸: domeniul, aplicația, utm), Campanii UTM, Pagini cele mai vizitate, Pagini de intrare, Produse cele mai văzute (cu rata de
  adăugare în coș), Categorii, Căutări pe site, Căutări fără rezultat, Dispozitive, Browsere, Sisteme, Țări (doar dacă găzduirea trimite un
  antet de țară: `CF-IPCountry`, `X-Country-Code`…; fără bază GeoIP; fără antet, tabelul lipsește). Un clic pe o sursă, un detaliu, o
  campanie sau un dispozitiv filtrează tot tabloul (paginile, produsele și căutările doar după sursă). **Impactul unei campanii sau
  postări** (`/admin/statistici/impact`): sursa (de exemplu TikTok) sau campania utm și momentul postării, apoi vizitele, comenzile și
  veniturile pe ore, lângă aceleași ore cu o săptămână înainte (și CSV). Cuvintele căutate pe Google nu se văd (Google nu le trimite):
  pagina o spune; o legătură cu Google Search Console poate veni mai târziu.
- **Propoziția pentru politica de confidențialitate** (și în Setări, cu numărul de zile al site-ului): „Pentru statistici de trafic folosim
  o analiză proprie, fără cookie-uri. Nu păstrăm adresa IP; pentru a număra vizitele unei zile folosim un cod care se schimbă zilnic. Datele
  detaliate se șterg după 30 de zile, iar rapoartele (paginile vizitate, sursa vizitei, tipul dispozitivului și al browserului) sunt
  agregate. Dacă browserul dumneavoastră trimite semnalul «Do Not Track» sau «Global Privacy Control», nu sunteți numărat.”
- **Vizitatori unici pe mai multe zile** = suma vizitatorilor unici ai fiecărei zile („Vizitatori unici (pe zile)”): fără cookie-uri, același
  om venit în două zile se numără de două ori. Pagina o spune la „?”.
- **Cronul** (în hPanel, ca celelalte): `17 * * * * /opt/alt/php83/usr/bin/php -d extension=sodium.so /home/uXXXXXXXXX/platforma/bin/platforma
  --site=/home/uXXXXXXXXX/domains/<domeniu>/site scripts/statistici-agregare.php`.
- **Un CDN în fața site-ului**: hash-ul folosește adresa din `IpClient` (`REMOTE_ADDR`, sau `X-Forwarded-For` doar de la un proxy din
  `IP_PROXY_DE_INCREDERE`). Dacă CDN-ul găzduirii e în față și rețelele lui nu sunt în `IP_PROXY_DE_INCREDERE`, toți vizitatorii au
  adresa CDN-ului: vizitatorii unici ies mult prea puțini (doar browserele diferă) și limita de 60 pe minut se împarte. De verificat cu
  `tools/verificare/stare.php` (clasa lui `REMOTE_ADDR`) înainte de pornire; aceeași setare ține limitele checkout-ului.
- **Are nevoie de**: nimic (coșul și comenzile doar cu magazinul). **Se oprește**: ca pornit. **Oprit**: niciun script în pagini (paginile
  ies octet cu octet ca înainte), `POST /api/statistici` primește 404-ul JSON al gărzii, adminul 403, meniul dispare, cronul nu face
  nimic, iar comenzile nu primesc hash-ul; datele rămân. Cu licența „toate”, comparația cu Bioscem (G-cmp) scoate scriptul din fiecare
  pagină publică (`$seoB`, „statistici: scriptul paginilor”).
- **Ce lipsește încă**: legătura cu Google Search Console (cuvintele căutate pe Google); căutarea din blog nu se notează (doar căutarea
  de produse); o comandă plasată în altă zi decât vizita nu se leagă de sursa vizitei (fără cookie nu se poate).

### Donații (`donatii`)

Din vertij (`32db865` … `8412906` „fundație, popup, mod Exemplu”, 22 iulie; `dc52498` … `ca1120c` „donații lunare, chitanțe,
versiunea API Stripe”, 5 octombrie; „ultimul commit revizuit” `9ab7999`), portat pe 9 noiembrie 2026. `DonatiiController`,
`App\Support\DonationService` (copiată, fără `ensureSchema`), vederile `views/site/donation-widget.php`, `views/site/donatii/tab-cont.php`,
`views/admin/donations.php`, `views/admin/settings-donations.php`, scriptul `public/assets/js/donation-widget.js` (aceiași octeți),
migrarea `donatii/0062`. Are nevoie de `plata_stripe`; în pachetul „Asociație” și în `licente_dev.vertij`.

- **Sertarul „SUSȚINE”** (agățarea `layout.final_body`, pe paginile publice): o dată sau lunar, sumele, destinațiile, comisionul acoperit
  la cerere, numele, emailul, abonarea la newsletter, acordul; apare cu donațiile pornite (`donations_enabled`) și cheia publică Stripe,
  sau în **modul „Exemplu”** (`donations_demo_enabled`, ori fără cheie: tot fluxul, fără nicio plată). Plata: `POST /api/donations/intent`
  (JSON) face rândul „pending” și, pentru o donație unică, un PaymentIntent; pentru una lunară, clientul Stripe, produsul „Donație lunară”
  (făcut o dată, ținut în `donations_stripe_product_id`, uitat dacă Stripe refuză) și abonamentul (`default_incomplete`, cheia de
  confirmare din `confirmation_secret`, cu rezerva pentru versiunile API de dinainte de basil). Cu pluginul „se oprește” sertarul
  dispare și nicio donație nouă nu pornește.
- **Webhook-ul** (același `/webhook/stripe`, aceeași semnătură, prin dispecerul `StripeEvenimente`, mai sus; proprietarul: metadata
  `type = donation` sau un abonament, o factură, o plată găsite în `donations`): `payment_intent.succeeded` („paid”, chitanța cerută la
  Stripe, emailul de mulțumire, o singură dată), `payment_intent.payment_failed` („failed”, o plată reușită rămâne „paid”), `invoice.paid`
  și `invoice.payment_succeeded` (fiecare lună a unei donații lunare: un rând nou „paid”, cu factura, chitanța și emailul; ambele forme ale
  facturii; indexul unic pe `stripe_invoice_id` oprește dublurile, deci un endpoint care trimite ambele tipuri nu dublează nimic),
  `customer.subscription.deleted` (`cancelled_at`). Deciziile proprietarului din 9 noiembrie: prima factură a unei donații lunare încă
  „pending” o marchează plătită (orice formă a facturii, deci orice versiune API a contului Stripe); `invoice.payment_failed` (luna
  neîncasată: un rând „failed”, același rând „paid” dacă Stripe reîncasează), `customer.subscription.updated` (`canceled`, `unpaid`,
  `incomplete_expired` = `cancelled_at`; `active`, `past_due` îl golesc la loc, `unpaid` nu schimbă nimic: cât Stripe poate încasa,
  donatorul își vede donația și o poate opri), `charge.refunded` (`refunded_cents`, `refunded_at`; întreagă = starea `refunded`; în
  admin, în tab și în totaluri; o donație rambursată nu mai trece în „paid”/„failed” la un eveniment întârziat și nu primește alt email).
  În versiunile basil, factura lunilor 2+ vine fără plată: plata se cere la Stripe (`expand[]=payments`), ca rambursarea lunii să se
  găsească; dacă cererea nu reușește, rambursarea acelei luni nu se notează. **Handler-ele rulează în orice stare a pluginului**: un abonament lunar al unui site
  cu pluginul oprit se reînnoiește la Stripe și se notează mai departe (fără email).
- **Contul donatorului** (tab-ul „Donații”, `cont.taburi`, doar pentru cine a donat; insigna „lunar”): fiecare donație lunară activă
  cu „Oprește donația lunară” (`POST /contul-meu/donatii/opreste`: formularul trimite `subscription_id`, acceptat doar dacă e al uneia din
  donațiile active ale contului; abonamentul anulat la Stripe, `cancelled_at`) și istoricul cu chitanțele și rambursările. Ștergerea
  contului (`utilizator.sters`) oprește în Stripe donațiile lui lunare active și dezleagă rândurile de cont. Donațiile făcute fără cont cu adresa contului apar (și se pot opri) doar după ce adresa e
  dovedită (`users.email_confirmed_at`, regula comenzilor fără cont, G-M4); până atunci tab-ul arată „Confirmă adresa ca să le vezi aici”
  cu butonul nucleului (`/contul-meu/confirmare-email`).
- **Admin** (Clienți → Donații, Setări → Setări donații): lista cu filtrul de stare, căutarea și totalurile (încasat, luna curentă, plătite,
  donatori lunari activi); setările sertarului.
- **Diferențe față de vertij** (în `site-uri/vertij/ce-se-schimba.md`): jetonul formularului (AdminCsrf) pe setări și pe oprirea donației;
  donația doar ca JSON (415 altfel); `Idempotency-Key` pe creările spre Stripe (`donatie-<referință>…`); chitanța arătată doar ca link
  `https://`; configurarea sertarului scrisă cu `JSON_HEX_TAG`; donațiile fără cont doar după dovada adresei (și doar cele fără cont,
  `user_id IS NULL`); revizuirea de securitate din 9 noiembrie: bugetul vizitatorului (`App\Support\LimitaCereri`, 10 donații la 15
  minute, apoi 429), suma maximă (`DonationService::MAX_LEI`, 100.000 lei), destinația doar din listă, mesajul Stripe doar în
  `storage/logs/account.log` (vizitatorul primește un text general), mai multe donații lunare, rambursările, ștergerea contului.
- **Stări**: **se oprește** — fără sertar și fără donații noi, contul și adminul rămân; **oprit** — adresele 404/403, meniul dispare,
  rândurile rămân, webhook-ul notează în continuare banii primiți (export legal: `scripts/exporta-date-legale.php --ce=donatii`). Un
  abonament lunar nu se oprește singur odată cu pluginul: se oprește din Stripe (decizia proprietarului).
- **Schema**: `donatii/0062`, adoptivă (definițiile `DonationService::ensureSchema` din vertij, cu coloanele și indexurile adăugate
  după): pe vertij-tinitus.ro „detectata”, 0 operații. Pe copia de verificare: doar structura (regulile Bioscem 25).

### Membri (`membri`)

Din vertij (`d3439a5` … `ffa3d9c` „conturi profesionale, categorii de membri, cereri noi”, 30 august – 5 octombrie; `50ef812`
„confirmarea emailului”; `af8ab87` „poza de profil”; `7d7d8cc` + `b0a81e1` „plata activării în listele adminului”, 26 octombrie),
portat pe 9 noiembrie 2026. `MembriController`, `App\Support\ProfessionAccounts` (copiată, fără `ensureSchema`, dosarul documentelor
prin `Paths`), vederile `views/site/membri/*`, `views/admin/account-*.php`, `views/admin/membri/activation-*.php`, migrarea `membri/0063`.
Are nevoie de `conturi_clienti` și `plata_stripe`; în pachetul „Asociație” și în `licente_dev.vertij`.

- **Tipurile de cont** (Clienți → Tipuri de cont, `/admin/accounts/professions`): categoriile de membru (nume, slug, descriere, cotizația
  și perioada ei, activ, ordinea) și câmpurile fiecăreia (text, text lung, număr, dată, listă, bifă, document; obligatoriu, activ, ajutor).
- **Înscrierea** (agățările `inregistrare.*`, doar cu pluginul pornit): cu măcar o categorie activă, formularul de înregistrare
  (`/register`, `{{auth_form}}`) e cel al categoriei (`inregistrare.inlocuieste_campurile`, `inregistrare.formular`, cu
  `enctype="multipart/form-data"`): tipul de cont, datele contului (prenume, nume, email, telefon, parolă: `inregistrare.campuri`),
  câmpurile categoriei, documentele (extensiile și mărimea din setări, conținutul verificat cu finfo; în `storage/account-documents`,
  `Paths::stocareSite`, numele opac, 0640) și acordul GDPR. Contul primește codul de membru (`<prefix>-00042`), cererea „așteaptă
  confirmarea pe email” și emailul de confirmare (linkul `/cont/confirmare/{token}`, doar hash-ul sha256 al tokenului în
  `account_verifications`; valabil 7 zile de la trimitere), apoi `/cont/verifica-email` (cu retrimiterea, `POST /cont/retrimite-confirmarea`,
  cu jetonul formularului, 5 la 15 minute). O înscriere nouă pe o adresă care are deja cont (`inregistrare.cont_existent`) **nu schimbă
  contul** (revizuirea de securitate din 9 noiembrie; vertij relua înscrierea peste cont, cu parola nouă): cu adresa nedovedită, ultima
  cerere „awaiting_email” și contul nefolosit, linkul cererii se retrimite și celelalte sesiuni se încheie; altfel, „Intră în cont sau
  resetează parola”.
- **Confirmarea**: linkul unei cereri e servit de pagina nucleului (`ConfirmareEmailController`: `confirmare.link_plugin` la GET, butonul
  „Confirmă adresa” și parola contului; `confirmare.foloseste_plugin` la POST): cererea trece „în verificare”, adresa e dovedită și pentru
  nucleu (`ConfirmareEmail::marcheazaConfirmat`), adminul primește emailul (`account_verification_notify_email`), clientul intră în cont.
  Orice altă dovadă a adresei (un link al nucleului, resetarea parolei, logarea cu Google: `confirmare.dupa`, din
  `ConfirmareEmail::marcheazaConfirmat`) face la fel. Cu `customer_email_confirmation_required` (vertij: înghețat 1), un cont cu cererea
  neconfirmată nu intră (`logare.blocata`), afară de cererile de dinainte de adoptare (mai vechi decât momentul în care `membri/0063` a
  găsit tabelele vertij), care intră ca pe codul vertij (și cărora o înscriere nouă nu le închide sesiunile). La adoptare,
  `site/vertij/0001` dovedește adresa membrilor care o confirmaseră cu linkul pe codul vertij (nu și pe cea a „cererilor noi” născute
  confirmate sau a cererilor decise fără confirmare).
- **Cererile** (Clienți → Cereri conturi): pe stări, cu căutarea, numărul documentelor și **ultima plată a cotizației** (suma, perioada
  achitată, starea, intervalul, plătit la; plățile încasate întâi, altfel ultima încercare); cererea cu răspunsurile, documentele
  (`/admin/accounts/documents/{id}`: doar adminului, PDF și imaginile inline, cu `Content-Security-Policy: … sandbox`, restul descărcate),
  plățile, decizia (aprobată / respinsă cu motivul; jurnalul de activitate și emailul deciziei; doar pe o cerere „pending” care e ultima a
  contului). O cotizație rambursată din Stripe (`charge.refunded`, găsită după plata ei; `membri/0065`) apare „Rambursată” în listă, în
  cerere și în lista utilizatorilor, cu un rând în jurnal; contul nu se dezactivează singur. Aceleași cinci coloane în Clienți →
  Clienți (`admin.utilizatori.coloane`), cu derularea pe orizontală a tabelului (`.admin-table-scroll`, vertij `b0a81e1`).
- **Prelungirea fără plată** (12 noiembrie 2026, doar pe codul comun): în cererea aprobată care e ultima a contului, panoul
  „Prelungește contul fără plată” (`POST /admin/accounts/verifications/{id}/extend`, 1–60 de luni, motivul opțional) prelungește contul ca
  o plată (`ProfessionAccounts::extendActivation`: de la expirare dacă mai are zile, altfel de azi), cu un rând `granted` („Acordat fără
  plată”, suma 0) în `account_activations` și `account_extended_free` (cu motivul) în jurnalul adminului. Fără email, fără Stripe; rândul nu
  apare în „Cotizația mea” (acolo doar plățile).
- **Cotizația** (tab-ul „Activează contul” / „Cotizația mea”, `cont.taburi`, cu insigna zilelor rămase sau „!”): pe 1, 3, 6, 12 luni
  (`account_activation_durations`, prețul proporțional cu cel al categoriei); `POST /contul-meu/activare` face plata „pending” și sesiunea
  Stripe Checkout (`metadata.type = account_activation`); întoarcerea (`GET /contul-meu/activare/retur`, **mereu deschisă**) și webhook-ul
  (`checkout.session.completed` plătită, `async_payment_succeeded`; `expired`, `async_payment_failed` = „failed”) prelungesc contul de la
  data expirării dacă mai are zile, o singură dată (doar cererea care trece plata în „paid”), cu chitanța și emailul „Contul tău este
  activ”. Întoarcerea are un buget pe vizitator (20 la 15 minute) și primește doar un id de sesiune Checkout (`cs_…`). Cu pluginul „se
  oprește” nu pornește nicio plată nouă. Cererea nouă după o respingere: `/contul-meu/cerere-noua`.
- **Conținutul pentru membri**: poarta nucleului (`AccesMembri`, mai sus), cu agățările `continut.membru_activ` (aprobat și cotizația la zi),
  `continut.nota` și `continut.nota_titlu` (explicația pe stări a vertij: oaspete, confirmare, verificare, respins, neactivat, expirat; 403,
  noindex); bifa „Doar cont activ” din editorul paginii și al articolului.
- **Contul**: poza de profil (`cont.profil`, în „Profilul meu”: `POST /contul-meu/poza`, JPG/PNG/WebP până la `account_avatar_max_mb`, în
  `uploads/gallery/profile` și în Galerie, folderul `account_avatar_folder`; cea veche ștearsă); categoria, starea și codul de membru în
  antetul contului (`cont.antet`). La ștergerea unui cont (din admin sau de client, `utilizator.sters`): cererile, documentele de pe disc,
  plățile cotizației și poza de profil.
- **Setări conturi** (Clienți → Setări conturi): documentele, duratele, prefixul codului (implicit „M”; vertij înghețat „SVTH”), eticheta
  contului din antet, numele și logo-ul emailurilor, poza de profil, acordul GDPR, textele secțiunii de activare și ale notei.
- **Diferențe față de vertij** (în `site-uri/vertij/ce-se-schimba.md`): linkul de confirmare cere apăsarea butonului și parola contului
  (vertij confirma și loga la simpla deschidere); logarea unui cont neconfirmat oprită; jetonul formularului pe POST-urile din admin și
  pe formularele contului (cotizația, poza, cererea nouă); documentul cu CSP sandbox; plata cotizației prelungește contul o singură dată
  și la două confirmări simultane; poza de profil ștearsă odată cu contul; cererile șterse și la „Șterge contul” (vertij: doar din admin).
- **Stări**: **se oprește** — fără înscrieri noi de membru (formularul devine cel clasic) și fără plăți noi ale cotizației; restul rămâne;
  **oprit** — adresele 404/403, meniul și coloanele dispar, paginile „doar cont activ” dau 404; rândurile, documentele și pozele rămân,
  webhook-ul și întoarcerea de la Stripe notează în continuare plățile.
- **Schema**: `membri/0063`, adoptivă (cele cinci tabele și coloanele contului `profession_id`, `account_status`, `account_active_until`,
  `member_code` unic, `avatar_url`, `stripe_customer_id`, exact ca în vertij): pe vertij-tinitus.ro „detectata”, 0 operații (indexul unic
  al codului de membru e acolo sub două nume). Pe copia de verificare: tabelele doar structura, pe conturi categoria, starea și data
  păstrate, codul, poza și clientul Stripe golite (regulile Bioscem 25).

**Dovada (ambele pluginuri)**: `tools/dev/test-donatii-membri.php` (în testul de fum, pe „toate”), față de codul vertij la `9ab7999`, pe
două baze făcute ca a lui, fiecare cod cu un Stripe fals al lui (în copia codului vertij, singura schimbare e adresa API-ului): A
adoptarea; B webhook-ul (aceleași răspunsuri, rânduri, cereri spre Stripe și emailuri; un eveniment retrimis: „duplicate”); C sertarul,
donațiile din sertar, înscrierea cu documente, verificarea emailului, confirmarea, secțiunea cotizației octet cu octet, plata și
întoarcerea, tab-ul donațiilor și oprirea, poza, cererea nouă, nota pe stări, fiecare pagină de admin (partea pluginului octet cu octet)
și acțiune, celulele din lista de utilizatori, ștergerea unui membru; D doar pe codul comun: CSRF, JSON, logarea, donațiile după dovadă,
pluginurile oprite și reparațiile revizuirii de securitate din 9 noiembrie (unde codul comun diferă voit de vertij, declarate la locul
lor): înscrierea peste un cont existent, resetarea parolei ca dovadă, expirarea linkului, decizia, bugetul și limitele donației, mai multe
donații lunare, evenimentele Stripe noi, rambursările, ștergerea contului, membrii vechi la adoptare.

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
  editabil de client în Emailuri de comandă, nu se ascunde singur fără recenzii.
- **Emailurile tranzacționale și formularele de contact** stau, ca în Bioscem, în
  aceeași pagină cu newsletterul (tab-urile nucleului rămân și fără newsletter).
- **Schema bazei**: migrările fac pe orice site și tabelele și coloanele
  pluginurilor oprite (OD-02, [MIGRARI.md](MIGRARI.md)); nu se văd, dar există.
- **Pagina de prezentare de rezervă** (`views/site/home.php`, folosită când nu e
  nicio pagină „acasă” în admin) are texte Bioscem; linkurile ei spre pluginuri
  ascunse se scot, textul rămâne. E pe lista rămasă a verificării 11 din
  `verifica-rute.php` (modul ratchet: lista poate doar să scadă); dacă Bioscem o
  folosește azi spune sonda pachetului înaintea adoptării (`tools/sonda/pachet-site.php
  --site=bioscem`, V2: paginile publicate cu slug gol, „acasa” sau „home”; WP-14).
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
- **Rolurile de administrator** sunt cele patru din Bioscem, plus „Cloud user” al pluginului `fisiere_cloud`
  (mai sus), cu prefixele de adrese scrise în `Auth::canAccessPath`; pluginurile nu-și declară încă rolurile.
  Rolurile legate de un plugin se ascund (vezi mai sus), dar un plugin nou cu
  pagini de admin are nevoie de o declarație de rol în catalog. Până atunci,
  `/admin/cereri-oferta` (WP-26) e scris la „Administrator magazin”, lângă comenzi.
- **Șabloanele de email ale magazinului** („Comandă expediată”, „Comandă livrată”)
  rămân în Emailuri de comandă și fără FAN (sunt textul clientului); nu se mai
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

**Codul nou al unui plugin** stă într-un controller al lui (`src/Http/Controllers/<Nume>Controller.php`), nu în
`SiteController`/`AdminController`, care nu se mai lungesc. Controllerele noi folosesc trăsătura `Comun` (WP-25, VT-X4:
`db()`, `jsonResponse()`, `requestPayload()`, `appUrl()`, garda adminului `guard()`), ca `ConfirmareEmailController`
(WP-25) și `CereriOfertaController` (WP-26).

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
tools/dev/php.sh tools/dev/test-campanii.php                 # pluginul campanii (regulile, adminul, site-ul, Chromium 1366/390 px)
tools/dev/php.sh tools/dev/test-statistici.php               # pluginul statistici (sursele, hash-ul, agregarea, punctul de primire, tabloul, Chromium)
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
