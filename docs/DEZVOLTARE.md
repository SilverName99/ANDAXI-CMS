# Dezvoltare ANDAXI-CMS

## De unde pornește codul

Codul a fost copiat din site-ul Bioscem (`Nou-site-bioscem`, branch
`claude/migrate-bioscem-site-bz38y4`, commit
`11b104043ae87ab2e05ab6cfb10cd460939ac303`, 8 octombrie 2026), cu `git archive`,
deci doar fișierele urmărite în git: fără sesiuni, cache, încărcări sau `.env`.

Primul commit din acest repo („Punct de plecare”) este codul Bioscem așa cum
este, cu excepțiile de mai jos. Orice schimbare de comportament vine în
commit-uri separate, ca diferențele față de Bioscem să se poată citi.

### Singurele diferențe față de Bioscem în commit-ul de plecare

- **Parola de admin de rezervă a fost scoasă.** `config/app.php` avea o parolă
  implicită scrisă în cod, aceeași pe toate site-urile, folosită de
  `scripts/install.php` când `.env` nu avea `ADMIN_DEFAULT_PASSWORD`. Acum nu mai
  există nicio valoare de rezervă: `install.php` refuză să pornească (cod de
  ieșire 1), înainte să atingă baza de date, dacă `ADMIN_DEFAULT_PASSWORD`
  lipsește sau are sub 10 caractere. `.env.example` nu mai conține parola.
- `README.md` e nou (scurt); README-ul Bioscem nu a fost copiat (descria
  instalarea și cronurile site-ului Bioscem, cu căile lui de pe server).
- `.gitignore` acoperă acum tot ce scrie aplicația la rulare: conținutul din
  `storage/` (sesiuni, cache, jurnale, copii, importuri), `public/uploads/`,
  sitemap-ul generat în `public/` și `public/assets/.htaccess`. Folderele din
  `storage/` există goale, cu `.gitkeep`.

### Ce NU a fost copiat și de ce

| Ce | De ce |
|---|---|
| `.git` (istoricul Bioscem) | repo nou; originea e notată mai sus |
| `storage/sessions/*` (≈1.700 fișiere în clona locală) | sesiunile pot conține date de clienți (coșuri, conturi) |
| `storage/cache/*`, `storage/import/*` | cache generat; dump-uri WordPress cu date reale |
| `public/uploads/*`, sitemap-ul generat | fișierele încărcate ale clientului; nu erau în git oricum |
| `README.md` Bioscem | specific site-ului Bioscem (căi pe server, cronuri, „de actualizat înainte de lansare”) |
| `docs/templates/*` | tema Bioscem (header, footer, pagini, cu telefoanele firmei); nu e folosită de cod; va deveni pachetul de temă Bioscem, în afara nucleului |
| `scripts/import-bioscem.php` | import unic din magazinul WooCommerce Bioscem (adresa bioscem.ro scrisă în cod) |
| `scripts/import-wordpress-users.php` | import unic al clienților din WordPress-ul Bioscem; locul lui e unealta internă „Migrare din WordPress” |
| `scripts/import-brand-taguri-wp.php` | import unic de mărci/etichete/SEO dintr-un dump WordPress Bioscem |
| `scripts/import-greutati.php` | import unic de greutăți de produse Bioscem |
| `scripts/clean-product-names.php` | curățare unică a numelor de produse importate |
| `scripts/analyze-product-sections.php`, `scripts/apply-product-sections.php` | împărțirea unică a descrierilor WooCommerce în secțiuni (folosesc doar ele între ele) |
| `scripts/set-product-template.php`, `scripts/set-similar-products.php` | operații în masă făcute o dată pe catalogul Bioscem |
| `scripts/genereaza-seo-produse.php` | generare unică de texte SEO pentru produsele Bioscem |
| `scripts/bbd-stock-debug.php`, `scripts/cos-debug.php`, `scripts/erp-stock-debug.php` | scripturi de depanare făcute pentru incidente Bioscem |
| `scripts/test-precomanda.php` | test făcut pe baza Bioscem (scrie și șterge comenzi); va fi înlocuit de testele automate |
| `scripts/verifica-precomanda-produs.php`, `scripts/verifica-telefon-comanda.php` | verificări punctuale pe date Bioscem |

Niciunul dintre fișierele de mai sus nu este folosit de cod (verificat cu
`git grep` pe numele lor înainte de copiere). `src/` a fost copiat întreg:
`WordPressDumpReader` și `WordPressPassword` le folosește adminul (importul de
utilizatori din backup WordPress, login cu parole vechi WordPress);
`ProductSections` nu mai e folosit de nimic după scoaterea celor două scripturi
de secțiuni și se mută la unealta internă „Migrare din WordPress” când o facem.

Scripturile păstrate în `scripts/`: `install.php`, `seed.php`, cronurile
(`erp-sync.php`, `bt-ipay-sync.php`, `fan-tracking-sync.php`,
`fan-lockers-sync.php`, `fan-nomenclator-sync.php`, `newsletter-campaigns.php`,
`abandoned-cart-emails.php`) și `diagnostic.php` (arată ce `.env` și ce bază vede
cronul, fără secrete).

### Secrete

Am căutat în tot codul copiat chei, parole și tokenuri (Stripe, SendGrid,
chei private, IBAN, JWT, atribuiri de tip `password = '...'`). Singurul secret
găsit a fost parola de rezervă de mai sus. Celelalte secrete ale unui site stau
în `.env` (BT iPay) și în tabelul `settings` (FAN, SMTP, SendGrid, Stripe,
EuPlătesc, ERP, Google, tawk.to), deci nu sunt în cod.

## Mediul de dezvoltare local

Uneltele stau în `tools/dev/`. Nu se urcă niciodată pe site-uri (și oricum
`.htaccess`-ul din rădăcină trimite tot traficul în `public/`, deci `tools/`
nu e accesibil din browser).

**Cerințe:** PHP 8.x CLI cu `pdo_mysql`, `curl`, `gd`, `intl`, `mbstring`,
`sodium`; MariaDB/MySQL accesibil printr-un socket; `curl`, `mysql`; opțional
Node + Playwright (pentru capturi).

### Pe scurt

```sh
tools/dev/smoke.sh                       # totul de la zero: bază, date demo, server, verificări, capturi
tools/dev/smoke.sh --fara-capturi        # la fel, fără Playwright
tools/dev/smoke.sh --toate-pachetele     # testul de fum pe fiecare pachet de abonament
tools/dev/instaleaza.sh                  # doar baza de test, de la zero, cu date demo și licența „toate”
tools/dev/instaleaza.sh --fara-demo      # doar install.php + seed.php (+ licența)
tools/dev/server.sh                      # pornește serverul: http://127.0.0.1:8701
tools/dev/server.sh --opreste            # îl oprește
tools/dev/php.sh scripts/erp-sync.php    # orice script sau cron, pe baza de test
tools/dev/php.sh tools/dev/licenta.php prezentare   # altă licență (pachet sau cheie=stare)
tools/dev/php.sh tools/dev/licenta.php bioscem      # licența reală a Bioscem: tot ce are cod, fără unealta internă
tools/dev/php.sh tools/dev/verifica-rute.php        # fiecare rută are proprietar în catalogul pluginurilor
tools/dev/php.sh tools/dev/test-pluginuri.php       # testele pluginurilor: licența, garda, fiecare pachet, „se oprește”
tools/dev/php.sh tools/dev/test-pluginuri.php --fiecare-plugin   # plus, pe rând, fiecare plugin oprit cu restul pornite (~20 min)
tools/dev/compara-cu-baza.sh                        # cu toate pluginurile, HTML identic cu codul dinaintea pluginurilor
tools/dev/compara-cu-baza.sh --licenta=bioscem      # la fel, cu licența reală a Bioscem
tools/dev/php.sh scripts/diagnostic.php --licenta   # licența se verifică? (verificarea dinaintea unui deploy)
```

Pluginurile, licența și garda rutelor sunt descrise în [PLUGINURI.md](PLUGINURI.md).

Conturi (toate inventate, aceeași parolă, nouă la fiecare bază, în
`storage/logs/dev/admin-parola.txt`):

- admin general: `admin@exemplu.ro`;
- admini cu rol parțial: `magazin@exemplu.ro`, `blog@exemplu.ro`, `emailuri@exemplu.ro`;
- clienți: de exemplu `andrei.exemplu@exemplu.ro` (15 clienți, `prenume.nume@exemplu.ro`).

### Fișierele din `tools/dev/`

| Fișier | Ce face |
|---|---|
| `comun.sh` | Setările comune: numele bazei, socketul, portul, folderul de jurnale; mediul aplicației (variabile de mediu, nu `.env`); comanda PHP de dezvoltare. |
| `instaleaza.sh` | Șterge și recreează baza de test, rulează `scripts/install.php`, `scripts/seed.php`, apoi `date-demo.php`. Curăță sesiunile, cache-ul, emailurile capturate și sitemap-ul generat. |
| `date-demo.php` | Creează din prima clipă toate tabelele/coloanele pe care codul le adaugă „din mers”, pune setările de dezvoltare și scrie datele demo. |
| `server.sh` | Pornește `php -S` pe `127.0.0.1:$CMS_DEV_PORT`, cu `public/` ca rădăcină și `router.php`. |
| `router.php` | Face pentru `php -S` ce fac pe Hostinger cele două `.htaccess`: fișierele existente se servesc direct, restul ajunge la `public/index.php`. |
| `php.sh` | Rulează un script PHP al proiectului cu mediul de dezvoltare (pentru cronuri). |
| `mail-capturat.sh` | Ține locul lui `sendmail`: fiecare email trimis cu `mail()` se scrie în `storage/logs/dev/mail/*.eml`. |
| `smoke.sh` | Testul de fum (mai jos), pe pachetul „toate” sau pe oricare pachet. |
| `capturi.cjs` | Capturile Playwright din admin. |
| `licenta.php` | Perechea de chei de dezvoltare (în locul celei a CRM-ului) și licențele de test: pachete, „toate” (cu unealta internă), „bioscem” (ca licența reală a Bioscem, fără unealta internă), `cheie=stare`, fără licență (șterge și starea aplicată); `stari`, `cron`, `arata` pentru teste. |
| `verifica-rute.php` | Listează fiecare rută cu proprietarul ei (nucleu sau plugin) și pică dacă o rută nu are proprietar, dacă o declarație din catalog nu acoperă nicio rută, dacă meniul și rutele nu se potrivesc, dacă un cron nu are garda, dacă codul întreabă de un plugin inexistent sau dacă un bloc `<?php if (…): ?>` de pe coloana 0 dintr-un șablon nu închide HTML-ul pe care îl deschide (un `endif` pus greșit lasă, cu pluginul oprit, jumătăți de tabel). |
| `test-pluginuri.php` | Testele mecanismului de pluginuri: licența (A: și ultima licență aplicată când nicio licență nu se verifică, codul 2 al cronurilor fără licență, momentul intrării în „se oprește”, jurnalul o dată pe zi, dosarul fără drept de scriere, JSON-ul canonic față de Node, filtrul care nu atinge listele goale ale clientului), garda rutelor pe fiecare pachet (B: plus linkul de urmărire din newsletter, robots.txt, API-urile catalogului, „Trimite email test”, acțiunile necunoscute din Setări magazin, adresele mereu deschise fără o plată adevărată, redirecționările în buclă), fiecare pachet parcurs pagină cu pagină pe site, în contul clientului și în tot meniul adminului, plus cu Playwright și sursa editoarelor de pagini și de șablon de produs (C), magazinul „se oprește” cu o comandă existentă dusă la capăt, ERP-ul care trimite comenzile plasate înainte de oprire, FAN oprit fără niciun AWB (D), comparația cu codul de bază, cu licența „toate” și cu cea a Bioscem (E) și, la cerere, fiecare plugin oprit pe rând cu restul pornite (F). În browser se verifică și textul vizibil: nicio etichetă sau explicație a unui plugin oprit, nici în ferestrele comenzilor și ale produselor, în tab-urile setărilor sau în contul clientului. Opțiuni: `--fara-garda`, `--fara-browser`, `--fara-baza`, `--fiecare-plugin`, `--capturi=DIR`. Pornește singur serverul dacă nu merge. |
| `compara-cu-baza.sh` (+ `.php`) | Scoate codul unui commit (implicit `f5ae5ea`, dinaintea pluginurilor) în `$CMS_DEV_DIR/baza-<commit>`, îl pornește pe portul următor cu aceeași bază și compară HTML-ul a peste 60 de pagini-cheie (vizitator, client, patru roluri de admin, plus o pagină de test cu liste goale scrise de client), fără tokenuri și versiuni de fișiere. Cu `--licenta=bioscem` pune înainte licența reală a Bioscem (fără unealta internă; panoul „Import din backup WordPress” lipsește intenționat și nu se compară) și la final pune la loc „toate”. Diferențele: `$CMS_DEV_DIR/compara/*.diff`. |
| `crawl-pachet.cjs` | Folosit de `test-pluginuri.php`: deschide paginile unui pachet în Chromium (vizitator, client logat cu un produs în coș, admin cu ferestrele comenzilor și ale produselor), întoarce linkurile, formularele și textul vizibil din DOM și erorile JavaScript, fotografiază meniul adminului. |

Variabile pe care le poți schimba din mediu:

| Variabilă | Implicit | Ce e |
|---|---|---|
| `CMS_DEV_DB` | `cms_dev` | baza de test; trebuie să înceapă cu `cms_` (se șterge la fiecare instalare) |
| `CMS_DEV_SOCK` | `/tmp/claude-0/maria/sock` | socketul MariaDB |
| `CMS_DEV_DB_USER` / `CMS_DEV_DB_PAROLA` | `root` / gol | utilizatorul bazei |
| `CMS_DEV_PORT` | `8701` | portul serverului |
| `CMS_DEV_DIR` | `storage/logs/dev` | jurnale, emailuri capturate, parola, raportul testului |
| `CMS_DEV_CAPTURI` | `$CMS_DEV_DIR/capturi` | unde scrie Playwright PNG-urile |
| `NODE_PATH`, `PLAYWRIGHT_BROWSERS_PATH` | `/opt/node22/lib/node_modules`, `/opt/pw-browsers` | unde e Playwright |

### Cum rulează aplicația în dezvoltare

- **Fără `.env`.** Configurarea vine din variabile de mediu puse de
  `comun.sh`. Dacă există un `.env` în rădăcină, uneltele refuză să pornească:
  `.env` ar avea prioritate și ar putea indica o bază reală.
- **Doar baze `cms_*`.** `instaleaza.sh` și `date-demo.php` refuză orice alt nume.
- **PHP** pornește cu `display_errors=0`, `error_reporting=E_ALL`, toate
  mesajele în `storage/logs/dev/php-erori.log`, `allow_url_fopen=0` și
  `sendmail_path` = `mail-capturat.sh`.
- **Sesiunile** stau, ca pe server, în `storage/sessions/` (ignorat de git).
- **Licența.** `comun.sh` dă aplicației cheia publică a perechii de chei de
  dezvoltare (`LICENTA_CHEIE_PUBLICA`, cheia privată în
  `storage/logs/dev/licenta-cheie-privata.txt`, niciodată în git) și
  `LICENTA_SITE=demo`; `instaleaza.sh` scrie licența „toate”, deci local site-ul
  are toate pluginurile pornite, plus unealta internă de migrare (licența reală a
  Bioscem nu o are: `licenta.php bioscem`; și cu ea paginile ies identice cu
  Bioscem azi, vezi `compara-cu-baza.sh --licenta=bioscem`). Licența stă în
  `storage/licenta/` (ignorat de git), lângă `stare-aplicata.json` (ce a aplicat
  site-ul ultima oară). `comun.sh` pune și
  `CMS_DEV_GARDA_ANTET=1`: garda pluginurilor adaugă atunci antetul
  `X-Andaxi-Garda`, ca testele să știe că a răspuns ea; pe site-uri variabila nu există.

### Ce nu poate ieși din calculator

Am verificat fiecare legătură a codului cu exteriorul:

| Legătura | Unde e în cod | În dezvoltare |
|---|---|---|
| ERP ANDAXI | `ErpClient` (curl, adresa din `erp_url`) | `erp_enabled=0`, `erp_url=http://127.0.0.1:9`, cheie inventată |
| FAN Courier | `FanCourierGateway` (curl, `api.fancourier.ro`, rezervă `file_get_contents`) | `FAN_API_BASE=http://127.0.0.1:9`, date de acces inventate, fără tarif live și fără AWB automat |
| Banca Transilvania iPay | `BtIpayGateway` (curl, `ecclients(-sandbox).btrl.ro`) | `BT_IPAY_MODE=test`, `BT_IPAY_BASE_URL_OVERRIDE=http://127.0.0.1:9`, date inventate |
| EuPlătesc | formular trimis de browser la `secure.euplatesc.ro` | **oprit** (`euplatesc_enabled=0`): codul nu are o adresă de test separată |
| Stripe | `StripeGateway` (curl, `api.stripe.com`) | oprit, chei inventate `sk_test_demo` |
| Email SMTP | `OrderMailer` (socket spre `smtp_host`) | `smtp_host` gol ⇒ se folosește `mail()` ⇒ fișier `.eml` local |
| SendGrid | `OrderMailer` (curl, `api.sendgrid.com`) | nefolosit (`email_delivery_method=smtp`), cheie goală |
| Login cu Google (clienți) | `SiteController` (curl, `oauth2.googleapis.com`) | oprit |
| Orice `file_get_contents('http…')` | rezervele de mai sus, când lipsește curl | imposibil: `allow_url_fopen=0` |
| În pagină: GA4, GTM, Google Ads, Clarity, tawk.to | `views/layout.php` | oprite |

Pe deasupra, toate procesele pornite de unelte au `http_proxy`/`https_proxy`/
`ALL_PROXY` = `http://127.0.0.1:9` (cu `NO_PROXY=127.0.0.1,localhost`): orice
cerere curl spre internet, chiar dacă cineva schimbă o setare din adminul de
test, se oprește imediat local. Singura excepție rămasă: dacă cineva scrie în
adminul de test un server SMTP real, emailurile pleacă prin el (socketul SMTP
nu trece prin proxy). Datele demo nu conțin adrese reale.

Ce încarcă browserul din afară, fără date de-ale noastre: fonturile Google pe
site și CodeMirror de pe `cdn.jsdelivr.net` în admin (Playwright le blochează
în capturi).

### Datele demo (toate inventate)

Firma este „Firma Demo SRL”, emailurile sunt `@exemplu.ro`, telefoanele
`0700 000 xxx`, AWB-urile încep cu `DEMO`, IBAN-ul e `RO00 DEMO…`. Localitățile
FAN sunt câteva orașe reale (nomenclator public), lockerele sunt inventate.

- 7 categorii, 40 de produse (3 din `seed.sql`): 3 inactive, 1 în coșul de
  gunoi, 4 cu oferte BBD (câte 2 loturi), 2 la precomandă (stoc 0, maxim 2 pe
  comandă, 20 în total), 1 epuizat, 6 cu preț redus, imagini PNG generate local
  în `public/uploads/demo/`; un șablon de pagină de produs cu tokenurile
  principale (2 produse rămân fără șablon, ca să se vadă și `product.php`);
- 15 clienți cu adrese; 3 admini cu rol parțial;
- 60 de comenzi în ultimele ~4 luni: finalizate, în procesare, în așteptare,
  plată în așteptare, anulate, rambursate, returnate, eșuate, 2 în coșul de
  gunoi; plată ramburs, OP, EuPlătesc, BT, Stripe; FAN la adresă și FANbox; 2
  precomenzi în așteptare, 3 comenzi cu lot BBD, 4 cu cupon, comenzi pe firmă,
  comenzi venite din Google Ads; tranzacții BT pentru comenzile BT; un link de
  plată deschis; obiecte promoționale pe câteva comenzi;
- 10 cupoane (procent, sumă fixă, expirat, oprit, cu limită, 5 coduri unice);
- puncte de fidelitate pentru comenzile finalizate + o ajustare manuală + o
  regulă; 25 de recenzii (5 nemoderate); 3 coșuri abandonate;
- blog: 2 autori, 8 articole (o ciornă, un eveniment); newsletter: 2 liste, 30
  de abonați (3 dezabonați), o campanie trimisă și o ciornă; 3 mesaje de contact;
- paginile site-ului cu tokenurile folosite de un site real (`{{shop_catalog}}`,
  `{{cart_form}}`, `{{checkout_form}}`, `{{account_section}}`, `{{blog_posts}}`,
  `{{featured_products}}` …) și 4 pagini legale. Login, înregistrare și contact
  rămân pe paginile PHP de rezervă (ca să fie verificate și ele).

Setările de dezvoltare: numele magazinului „Firma Demo”, emailuri doar
`@exemplu.ro`, plăți/ERP/FAN ca în tabelul de mai sus; restul setărilor rămân
cele implicite din cod (de exemplu TVA 19%, numerotarea comenzilor de la 203800).

### Testul de fum (`tools/dev/smoke.sh`)

Pornește de la o bază goală și verifică, în ordine:

1. instalarea (`install.php` + `seed.php` + date demo);
2. site-ul public: acasă, magazin, categorie, marcă, produs cu și fără șablon,
   produs cu oferte BBD, produs la precomandă, căutare, adăugare în coș, coș,
   checkout, login, înregistrare, cont fără login (redirect), blog, articol,
   contact, pagină legală, `robots.txt`, 404, `/health`;
3. o comandă reală, ramburs, plasată ca un client (cu protecția anti-robot):
   redirect la pagina de mulțumire, comanda în bază, emailul capturat;
4. adresele de confirmare fără semnătură (Stripe, EuPlătesc, BT, ERP): trebuie
   refuzate (400/401/403), niciodată 404 sau 500;
5. contul de client (login + istoricul comenzilor);
6. adminul: login, dashboard, **fiecare intrare din meniu** (citită din
   pagină, nu scrisă de mână), paginile la care se ajunge din butoane, exporturi,
   generarea sitemap-ului și `/sitemap.xml`, logout; un admin de blog nu intră
   la plăți;
7. fiecare cron, o dată (cererile lor spre ERP/FAN/bancă trebuie să se oprească
   local);
8. jurnalul PHP: niciun `Warning`, `Notice` sau `Fatal` (mesajele `Deprecated`
   se raportează, dar nu opresc testul);
9. capturi Playwright: dashboard, meniul cu toate grupurile deschise, lista de
   comenzi; cererile paginii spre alte servere sunt blocate și raportate.

Raportul: `storage/logs/dev/smoke-raport.txt`. Codul de ieșire e 0 doar dacă
totul a trecut.

**Pe pachete** (`--pachet=<nume>` sau `--toate-pachetele`): după instalare se
pune licența pachetului (prezentare, catalog, magazin, asociatie), iar fiecare
verificare știe al cui plugin e pagina. Paginile pluginurilor care nu sunt în
pachet trebuie să dea 404 pe site și „Modulul nu este inclus în abonament” (403)
în admin; comanda, emailul ei și verificările cronurilor acelor pluginuri sunt
sărite; cronurile lor trebuie să iasă fără să facă nimic; adresele de confirmare
răspund în orice pachet. Rapoartele: `smoke-raport-<pachet>.txt`, capturile cu
sufixul pachetului (`admin-meniu-prezentare.png`). Cu pachetul „toate”,
rezultatul e identic cu cel de dinainte de mecanismul de pluginuri.

## Erori moștenite

Găsite de testul de fum pe codul Bioscem neschimbat (commit-ul „Punct de
plecare”), cu datele demo. Nu le-am reparat decât dacă reparația era
banală și era clar o greșeală; restul se repară în etapele lor, ca schimbări
separate.

1. **`scripts/fan-tracking-sync.php` scria 2 avertismente PHP la fiecare
   rulare** (`use RuntimeException; use Throwable;` fără efect, într-un fișier
   fără namespace). Cronul rulează la 10–15 minute pe Bioscem, deci jurnalul de
   erori se umplea. A fost singura eroare care oprea testul de fum pe codul de
   plecare. **Reparat** (cele două rânduri scoase; comportamentul cronului nu se
   schimbă, numele se rezolvă la fel fără ele).
2. **`fputcsv()` fără parametrul `$escape`** (exporturile de comenzi, produse
   și localități/județe FAN din `AdminController`): mesaje `Deprecated` doar pe
   PHP 8.4, câte unul pe rând exportat. Pe PHP 8.3 și mai vechi nu apar.
   **Reparat** (Etapa 1, la exportul fără coloanele pluginurilor oprite): fiecare
   apel dă explicit valorile implicite de până acum (`'"'`, `'\\'`), deci fișierul
   iese la fel, fără mesaje.
3. **Produsele inactive se văd în magazin** (`/magazin`, categorie, marcă) și
   pagina lor răspunde 200; doar sitemap-ul le exclude. E defectul cunoscut din
   analiză (`loadProducts` filtrează doar `deleted_at`; reparația există în
   Biovitality, portul C8). Nereparat: schimbă ce văd clienții Bioscem.
4. **`install.php` și `seed.php` ies cu codul 0 când baza nu e disponibilă**
   (`exit("mesaj")`), deci un script automat ar crede că instalarea a reușit.
   Portul „scripturi cu exit 1 la DB căzută” din grafoanaytis o rezolvă.
   Uneltele de dezvoltare verifică singure baza înainte.
5. **Fiecare rulare a unui script CLI (inclusiv cronurile) deschide o sesiune
   PHP** (`bootstrap.php`) și lasă un fișier gol în `storage/sessions/`. Pe
   Bioscem, 878 din cele 1.711 fișiere de sesiune din clona locală sunt goale.
   Se șterg singure după 30 de zile. Nereparat.
6. **Adminul încarcă CodeMirror de pe `cdn.jsdelivr.net` pe fiecare pagină**:
   fără acel CDN, editoarele de cod nu merg. Nereparat (portul C6/C7 din
   Biovitality, încărcare leneșă).
7. **`/checkout/succes` deschis direct (fără numărul comenzii) dădea eroare 500**:
   pagina din bază cu tokenul `{{checkout_success_order_info}}` trimitea starea
   comenzii (text) în locul totalului (număr), iar PHP-ul cu tipuri stricte
   oprea pagina (`TypeError`). Găsit de testul pluginurilor, care cere fiecare
   rută. **Reparat** (al doilea parametru nu se mai trimite); pagina de
   mulțumire reală, `/checkout/succes/<număr>`, nu era afectată.

De știut (nu sunt erori):

- **BT iPay nu apare în checkout în modul test**, intenționat (`BtIpayPayments::vizibilInCheckout`):
  în test se încearcă doar „Plată de test 1 leu” din admin. Cu EuPlătesc și
  Stripe oprite, checkout-ul de dezvoltare are doar ramburs și ordin de plată.
- **Coloana `orders.erp_status` are implicit `pending`**, deci și comenzile
  unui magazin fără ERP sunt „de trimis”. Dacă ERP-ul se pornește mai târziu,
  cronul `erp-sync.php` trimite toate comenzile vechi neanulate (inclusiv cele
  finalizate). De ținut minte la pornirea pluginului `erp` pe un site care l-a
  avut oprit.
- `/admin/users/points/history` fără parametri răspunde 400 (nu e în meniu; se
  deschide cu un client ales).
