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
Node + Playwright (pentru capturi) și `python3` (`test-verificare.php` rulează
`configureaza.php` și într-un terminal adevărat). Pentru testele migrărilor, și un
MySQL 8 lângă MariaDB (`tools/dev/mysql8.sh`; pe Hostinger nu se știe încă ce server e).

**PHP-ul minim** (`php_min` în `config/app.php`): **8.3** (decizia OD-48, 19 octombrie 2026):
toate cele patru site-uri live și site-ul de verificare rulează PHP 8.3, deci trecerea unui site
pe codul comun nu-i schimbă versiunea, iar întoarcerea la codul vechi rămâne sigură. Versiunea
se ridică pentru toate site-urile odată, după cele patru adoptări. Codul care ajunge pe site-uri
nu are voie să folosească nimic mai nou decât 8.3 (din 8.4: proprietăți cu `get`/`set`,
vizibilitate asimetrică, `new X()->m()` fără paranteze, `array_find`, `mb_trim`,
`#[\Deprecated]`…). Se verifică așa: `tools/dev/instaleaza.sh --php-min` instalează `php8.3`
(din depozitul ondrej/php deja configurat; `php` rămâne cel implicit), `tools/dev/verifica-php.sh`
verifică sintaxa fiecărui fișier de pe site cu el, iar `CMS_DEV_PHP=php8.3` pornește orice
unealtă (serverul, copia de bază, testul de fum, testele pluginurilor) cu PHP-ul minim.
`scripts/diagnostic.php` iese cu 1 dacă PHP-ul liniei de comandă e mai vechi decât minimul.
Până la decizie minimul provizoriu a fost 8.0; codul scris atunci (de exemplu `esteLista` în
locul lui `array_is_list`, clasele migrărilor fără `readonly`) rămâne cum e. Ce s-a găsit
atunci rulând uneltele cu 8.0 nu mai e o limită, dar explică unele ocolișuri din unelte:
`mb_str_pad` nu exista (8.3); o metodă privată se chema prin Reflection doar după
`setAccessible(true)`; `escapeshellarg` scotea literele cu diacritice (localul C), deci textele
cu diacritice se dau printr-un fișier, nu prin linia de comandă. Rămâne valabil: procesele de
lucru ale lui `php -S` (`PHP_CLI_SERVER_WORKERS`) se opresc explicit (`opreste_server_php` din
`comun.sh`), pentru că pe 8.0 nu se opreau odată cu procesul principal.

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
tools/dev/php.sh tools/dev/licenta.php bioscem      # licența de dezvoltare a Bioscem (listă explicită, fără unealta internă)
tools/dev/php.sh tools/dev/licenta.php grafoanaytis # la fel pentru biovitality, vertij, grafoanaytis
tools/dev/php.sh tools/dev/verifica-rute.php        # fiecare rută are proprietar în catalogul pluginurilor
tools/dev/php.sh tools/dev/instantaneu-rute.php     # suprafața fiecărei licențe de test față de tools/dev/instantanee/ (--actualizeaza)
tools/dev/php.sh tools/dev/test-pluginuri.php       # testele pluginurilor: licența, garda, fiecare pachet, „se oprește”
tools/dev/php.sh tools/dev/test-pluginuri.php --fiecare-plugin   # plus, pe rând, fiecare plugin oprit cu restul pornite (~20 min)
tools/dev/compara-cu-baza.sh                        # HTML identic cu codul Bioscem însuși (ultimul commit revizuit din docs/de-portat.md)
tools/dev/compara-cu-baza.sh --licenta=bioscem      # la fel, cu licența de dezvoltare a Bioscem
tools/dev/compara-cu-baza.sh --baza=f5ae5ea         # istoric: față de codul dinaintea pluginurilor (SEO și porturile scoase explicit)
tools/dev/php.sh tools/dev/test-seo.php             # SEO pe fiecare pachet: adresa unică, harta, sitemap, robots.txt, date structurate (docs/SEO.md)
tools/dev/php.sh tools/dev/test-verificare.php      # site-ul de verificare: poarta, configureaza.php, stare.php (docs/VERIFICARE.md)
tools/dev/php.sh tools/dev/test-sonde.php           # verificările pe site-urile live: agregate.php, sonda schemei, din-sonda.php (docs/VERIFICARE.md)
tools/dev/php.sh tools/dev/migrari/din-sonda.php <amprenta.json>   # sonda unui site live → fixture în tools/dev/migrari/vechi/privat/ (în afara git-ului)
tools/dev/php.sh scripts/migreaza.php --plan         # migrările: ce s-ar face pe baza de test (--aplica, --verifica, --stare; docs/MIGRARI.md)
tools/dev/php.sh tools/dev/test-migrari.php          # testele migrărilor: runner-ul și T1–T11 pe schemele celor cinci coduri (MariaDB și MySQL 8)
tools/dev/php.sh tools/dev/test-migrari.php --runner # doar runner-ul, cu fiecare cod de ieșire (--migrari: doar migrările adevărate)
tools/dev/php.sh tools/dev/verifica-migrari.php      # fișierele de migrare și DDL-ul din cod (și în testul de fum)
tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --verifica   # migrările de bază față de schema făcută de cod (MariaDB și MySQL 8)
tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat    # după o migrare nouă: asteptat.json, migrari.lock, ultima.php
tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri # după ce se mută „ultimul commit revizuit”: tools/dev/migrari/vechi/
tools/dev/mysql8.sh porneste                         # al doilea server de test, MySQL 8 (opreste, stare)
tools/dev/verifica-php.sh                           # sintaxa codului de pe site-uri cu PHP-ul minim (php_min)
CMS_DEV_PHP=php8.3 tools/dev/smoke.sh --toate-pachetele   # orice unealtă, cu PHP-ul minim
tools/dev/porti.sh --wp=WP-01                       # porțile unui pachet de lucru din planul Etapei 1, în ordine
tools/dev/php.sh tools/dev/de-portat.php            # commit-urile noi din codurile vechi, de trecut în docs/de-portat.md
tools/dev/php.sh scripts/diagnostic.php --licenta   # licența se verifică? PHP-ul e destul de nou? (verificarea dinaintea unui deploy)
```

Pluginurile, licența și garda rutelor sunt descrise în [PLUGINURI.md](PLUGINURI.md);
migrările bazei de date în [MIGRARI.md](MIGRARI.md).

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
| `licenta.php` | Perechea de chei de dezvoltare (în locul celei a CRM-ului) și licențele de test: pachete, „toate” (cu unealta internă), licențele de dezvoltare ale celor patru site-uri existente (`bioscem`, `biovitality`, `vertij`, `grafoanaytis`: liste scrise explicit în `config/plugins.php` → `licente_dev`, ce are codul vechi al fiecăruia, nu contractul; un plugin portat mai târziu nu intră singur în licența Bioscem), `cheie=stare`, fără licență (șterge și starea aplicată); `stari`, `cron`, `arata`, `presetari` pentru teste. |
| `verifica-rute.php` | Listează fiecare rută cu proprietarul ei (nucleu sau plugin) și pică dacă o rută nu are proprietar, dacă o declarație din catalog nu acoperă nicio rută, dacă meniul și rutele nu se potrivesc, dacă un cron nu are garda, dacă codul întreabă de un plugin inexistent sau dacă un bloc `<?php if (…): ?>` de pe coloana 0 dintr-un șablon nu închide HTML-ul pe care îl deschide (un `endif` pus greșit lasă, cu pluginul oprit, jumătăți de tabel). |
| `test-pluginuri.php` | Testele mecanismului de pluginuri: licența (A: și ultima licență aplicată când nicio licență nu se verifică, codul 2 al cronurilor fără licență, momentul intrării în „se oprește”, jurnalul o dată pe zi, dosarul fără drept de scriere, JSON-ul canonic față de Node, filtrul care nu atinge listele goale ale clientului), garda rutelor pe fiecare pachet (B: plus linkul de urmărire din newsletter, robots.txt, API-urile catalogului, „Trimite email test”, acțiunile necunoscute din Setări magazin, adresele mereu deschise fără o plată adevărată, redirecționările în buclă), fiecare pachet parcurs pagină cu pagină pe site, în contul clientului și în tot meniul adminului, plus cu Playwright și sursa editoarelor de pagini și de șablon de produs (C), magazinul „se oprește” cu o comandă existentă dusă la capăt, ERP-ul care trimite comenzile plasate înainte de oprire, FAN oprit fără niciun AWB și fără regula coletelor trimisă în ERP, Setări livrare fără ERP (D), comparația cu codul de bază, cu licența „toate” și cu cea a Bioscem (E) și, la cerere, fiecare plugin oprit pe rând cu restul pornite (F). În browser se verifică și textul vizibil: nicio etichetă sau explicație a unui plugin oprit, nici în ferestrele comenzilor și ale produselor, în tab-urile setărilor sau în contul clientului. Plus SEO (G: `test-seo.php`, pe fiecare pachet). Opțiuni: `--fara-garda`, `--fara-browser`, `--fara-baza`, `--fara-seo`, `--fiecare-plugin`, `--capturi=DIR`. Pornește singur serverul dacă nu merge. |
| `compara-cu-baza.sh` (+ `.php`, `lib/diferente-asteptate.php`, `lib/salvari-admin.php`) | **Implicit, față de codul Bioscem însuși**: scoate cu `git archive` commit-ul „ultimul revizuit” al Bioscem din `docs/de-portat.md` (azi `9f59e79`) din clona `CMS_DEV_REPO_BS` în `$CMS_DEV_DIR/baza-Nou-site-bioscem-<commit>`, îl pornește pe portul +1 (cu routerul de aici și o copie a pozelor încărcate) și pornește codul curent pe portul +4 cu datele site-ului Bioscem (`site-uri/bioscem/`; Bioscem le are scrise în cod), pe aceeași bază. Compară răspunsul a peste 70 de pagini-cheie (vizitator, client, patru roluri de admin, o pagină de test cu liste goale scrise de client), plus `/sitemap.xml`, adresa unică (litere mari, „/” final) și fiecare regulă din harta adreselor vechi a Bioscem (din pachet și din `config/redirecturi.php` al codului Bioscem; cele două liste trebuie să fie aceleași, deci o regulă nouă neportată pică): codul, `Location`, anteturile (tot ce trimite codul, în ordine, fără `Date`, `Content-Length` și antetul gărzii de dezvoltare; valoarea cookie-urilor, `ETag` și `Last-Modified` mascate) și pagina, fără tokenuri și versiuni de fișiere. Plus **salvările din admin** (ce rămâne în bază după un POST, pe care paginile GET nu-l văd): cheia ofertelor BBD la salvarea produsului (cu o ofertă nouă care s-ar ciocni, o cheie dublată, una care nu e text), categoriile bifate în editorul unei postări, re-salvarea ei (categoria principală păstrată, debifată, trimisă explicit) și duplicarea. Fiecare caz pregătește câteva rânduri, trimite aceeași cerere întâi codului Bioscem, apoi codului curent, cu baza pusă la loc înainte de fiecare și la final (verificat: aceleași rânduri ca la început), și compară răspunsul și rândurile atinse. Pe rezultatul codului Bioscem, ca niciun caz să nu treacă în gol: fiecare salvare trebuie să se vadă în bază (re-salvarea schimbă și rezumatul postării, deci o salvare care nu scrie nimic pică, deși redirecționează la fel), un caz de tip „schimbare” trebuie să arate schimbarea commit-ului din care vine (fără ea rezultatul ar fi altul), iar cele două cazuri de tip „regresie” (principala debifată, `category_id` trimis explicit) cer rezultatul de pe ramurile pe care `b3781a4` nu le schimbă. Paginile la care codul de stare diferă voit (`$coduriDeBioscem`: azi `/checkout/succes` deschis direct, 500 în Bioscem, pagina aici; „Erori moștenite” 7) trec doar cu exact codurile declarate și cu textul paginii pe pagina curentă. Nimic din livrarea 1 SEO nu se mai scoate: Bioscem o are deja. Se scot doar diferențele declarate explicit, fiecare cu pagina și motivul ei (`$deBioscem`, `$porturiBiovitality`, `$porturiVertij`, `$porturiGrafo`, marcajele de port `$marcajePort`); fiecare trebuie găsită exact o dată, pe pagina ei. Altă bază: `--baza-repo=<clonă>@<commit>`. Cu `--licenta=bioscem` pune înainte licența de dezvoltare a Bioscem (fără unealta internă; panoul „Import din backup WordPress” lipsește intenționat și nu se compară) și la final pune la loc „toate”. **Istoric**, `--baza=f5ae5ea`: față de commit-ul dinaintea pluginurilor din acest repo, cu diferențele SEO ale livrării 1 (date structurate, robots.txt, width/height, poza principală, stilurile catalogului, textul „Sitemap XML”) și porturile din Bioscem după el (`$porturiBioscem`) scoase explicit, doar din pagina codului curent. `--autotest`: doar verificarea mecanismului de diferențe (texte, marcaje, coduri de stare, anteturi: un `X-Robots-Tag` pierdut, un `Cache-Control` schimbat sau un cod declarat care nu mai e cel primit pică). Diferențele (cu anteturile în capul fiecărui fișier): `$CMS_DEV_DIR/compara/*.diff`. |
| `instantaneu-rute.php` (+ `instantanee/`, `lib/cereri-rute.php`) | Instantaneul rutelor: pentru fiecare licență de test (`toate`, `fara`, cele patru site-uri, cele patru pachete), ce face garda cu fiecare rută (`CONTROLLER`, `MEREU`, `403`, `404`), meniul adminului și cronurile, comparate cu `tools/dev/instantanee/rute-<licența>.txt` din git. O schimbare dă un diff și codul 1; `--actualizeaza` rescrie fișierele, care intră apoi în commit (cine revizuiește vede în `git diff` orice adresă nouă a unui pachet). Rutele împărțite (`rute_impartite`) apar cu fiecare valoare din fiecare listă (o valoare mutată la alt plugin se vede în diff); aceeași listă de cereri o folosește testul B. Plus regulile scrise în script, independente de catalog: fără magazin, coșul, checkout-ul, plățile și comenzile nu ajung niciodată la controller (nici ca `MEREU`); la fel conturile, recenziile, acordurile GDPR fără pluginul lor; adresele mereu deschise rămân deschise în orice licență și sunt doar cele din lista fixă `$mereuDeschiseFixe` a scriptului (una nouă se adaugă acolo, explicit); `licente_dev.bioscem` e lista Bioscem de la `4915478`. Regulile pică și cu `--actualizeaza`. Fără server și fără bază; rulează în testul de fum și ca secțiunea B0 a testului pluginurilor. |
| `verifica-php.sh` | `php<php_min> -l` pe tot ce ajunge pe site-uri (`src/`, `views/`, `config/`, `scripts/`, `public/`, `bootstrap.php`, `database/` cu migrările, `tools/verificare/`, `tools/sonda/`). |
| `test-migrari.php` (+ `migrari/test-baza.php`) | Testele migrărilor ([MIGRARI.md](MIGRARI.md), „Testele”; poarta G-mig, și cu PHP-ul minim; `--runner`: doar runner-ul, poarta G-runner; `--migrari`: doar migrările adevărate). **Runner-ul** pe migrări de jucărie scrise de test: fără bază Server, Amprenta (rândurile din `information_schema` ale MariaDB și ale MySQL dau aceeași amprentă), definițiile și corpul unui CREATE TABLE, Registru; apoi pe MariaDB și pe MySQL 8 detectarea (ce scrie serverul = ce înțelege Amprenta; un tabel sărit de `information_schema` cât altă conexiune îi schimbă structura, 1684, citit din nou), Schema direct (avertisment la altă definiție, index echivalent sub alt nume, cursa cu alt proces, valori repetate numărate fără valori, blocare refuzată; `tabelComplet` și în cursa cu codul vechi care face tabelul cu toate coloanele, dar fără indecși; colația coloanelor, în amprentă doar cea diferită de a tabelului, și CHARACTER SET/COLLATE refuzate într-o migrare) și `scripts/migreaza.php` cu fiecare cod de ieșire: oprit după fiecare operație și reluat (aceeași amprentă, fiecare rând numărat o dată), oprit chiar înainte și chiar după commit-ul fiecărei bucăți (`MIGRARI_TEST_OPRESTE_IN`; pică dacă cursorul ar ieși din tranzacția bucății), bugetul, migrări schimbate, blocante, contract, parametrii site-ului, lacătele (și al sincronizării FAN), starea neatinsă de un runner refuzat (3, 12) și scrisă cu codul final, un tabel ocupat, mărimea tabelelor în plan, un tabel `#sql-*` rămas, `sql_mode`, verificarea, configurarea, pașii site-ului, `--json`, nicio valoare (nici cu ghilimele sau `\` în ea) și nicio parolă în ieșire; aceeași amprentă pe ambele servere (și pentru `tinyint(1) unsigned`, un text implicit `x'y`); o conexiune care primește id-ul de obiect al alteia; linia de comandă fără fișiere de sesiune. **Migrările adevărate**, T1–T11 (WP-04): instalarea nouă (plus G-baza), baseline-ul pe schema fiecărui cod (`migrari/vechi/<cod>.sql`, cu numerele din migrari.md §2.2) și pe o bază veche cu rânduri inventate (aceleași date ca funcțiile codului), pe schema live când vine sonda (T2-live; altfel SĂRIT, cu motivul) și pe o sondă simulată, a doua aplicare, drumul unui site cu `date-demo.php`, opririle după fiecare operație, codul vechi în paralel, migrări schimbate, o migrare blocantă, aceeași amprentă pe ambele servere, funcțiile `ensure*` (ale codului comun și ale Bioscem, fără nicio excepție) pe o bază migrată, cu licența „toate” dintr-un dosar al testului (`CMS_DEV_LICENTA_DOSAR`; licența locală nu contează), verificarea fișierelor pe greșeli puse într-o copie. Își face și își șterge bazele `cms_mig_t_*`; pornește și oprește MySQL 8 dacă nu mergea (`--doar-mariadb`: fără el, rezultat parțial, spus explicit). Raport: `$CMS_DEV_DIR/test-migrari-raport.txt`. |
| `verifica-migrari.php` | Verificarea fișierelor de migrare și a DDL-ului din cod ([MIGRARI.md](MIGRARI.md), „Verificarea fișierelor de migrare”; în testul de fum și în G-mig): registrul, `migrari.lock` și `ultima.php` la zi, fără DDL scris de mână, `AFTER`/`FIRST`, `IF NOT EXISTS`, `COLLATE` în migrări, ștergeri doar în „contract”; fiecare DDL și fiecare definiție de coloană din codul de pe site-uri în lista `migrari/ddl-din-cod.txt` (lista doar scade: `--actualizeaza-lista`); pe o bază goală `cms_mig_v_*`, `asteptat.json` la zi și fiecare obiect făcut de o migrare al proprietarului ei în catalog (`config/plugins.php` → `tabele`). `--fara-baza`: fără server (spus în rezultat). |
| `mysql8.sh` | `porneste`, `opreste`, `stare` pentru un MySQL 8 despachetat în `CMS_DEV_MYSQL8_DIR` (doar socket, fără port TCP). |
| `test-verificare.php` | Testele site-ului de verificare ([VERIFICARE.md](VERIFICARE.md)): `IpClient`, credențialele Basic, cookie-ul porții, dosarul site-ului (fără server); apoi o copie a codului într-o găzduire simulată (`$CMS_DEV_DIR/verificare/acasa/domains/verificare.exemplu.test/public_html`), servită pe `CMS_DEV_PORT`+7 cu un mediu gol, ca pe Hostinger: fără `.env` 503 la orice; fiecare refuz al `configureaza.php` și configurarea pe o bază goală; poarta (401, 429, limita pe /64 la IPv6, formularul, câmpurile trimise ca liste, sonda); `stare.php` (ieșirea lui în raport); o copie „încărcată” pe baza de dezvoltare și una expirată; `SITE_COPIE_VERIFICARE=0`. Își face și își șterge bazele `cms_verif_gol`, `cms_verif_plina` și utilizatorul `cms_verif_test`. Cu un PHP sub minim instalat (`php7.0` … `php8.2`) verifică și mesajul pentru un PHP prea vechi; cu `python3`, `configureaza.php` într-un terminal adevărat (`lib/terminal.py`: Ctrl-C și SIGTERM în mijlocul unei parole lasă terminalul cu ecou). Poarta G-verif îl rulează și cu PHP-ul minim. Raport: `$CMS_DEV_DIR/test-verificare-raport.txt`. |
| `test-sonde.php` | Testele verificărilor pe site-urile live ([VERIFICARE.md](VERIFICARE.md), „Verificările pe site-urile live”; poarta G-sonde, și cu PHP-ul minim): garda instrucțiunilor (doar SELECT/SHOW cu LIMIT), tiparele ieșirii, tiparul coloanelor cu date personale, clasele CSS (din HTML, CSS și JavaScript), textele fixe ale erorilor PHP (`tools/verificare/lib/intrare.php`); apoi, într-o găzduire simulată (`$CMS_DEV_DIR/sonde/`, site-ul de verificare și patru site-uri „live” inventate pe `cms_sonde_t`, o copie a bazei de dezvoltare cu rânduri inventate pentru fiecare verificare): fiecare refuz al locului, fiecare număr al fiecărui set, sesiunea doar de citire (serverul refuză scrierile, 1792; un ALTER din altă conexiune nu așteaptă în mijlocul unui grup; un utilizator doar cu SELECT ajunge), sonda (amprenta ei = a bazei), `migrari/din-sonda.php` pe MariaDB și MySQL 8 (refuzurile și cu căi relative), aceleași rânduri pe MySQL 8, bugetul, textele lungi cu memoria PHP mică (citite pe bucăți, cele prea mari numărate, o eroare fatală de memorie: „OPRIT”, fără să se agațe); fiecare rulare are un termen de 300 s; plus: nicio dată personală, parolă sau nume de bază în ieșiri, baza neschimbată (CHECKSUM TABLE), niciun fișier schimbat în dosarele site-urilor live, în site-ul de verificare doar `storage/verificare/sonde/`. Își face și își șterge bazele `cms_sonde_t`, `cms_sonde_rt` și utilizatorii `cms_sonde_*`; pornește MySQL 8 dacă nu mergea. Raport: `$CMS_DEV_DIR/test-sonde-raport.txt`. |
| `migrari/genereaza-fixture.php` | Schema pe care o face codul unui site pe o bază goală: `scripts/install.php`, apoi fiecare funcție `ensure*Schema`/`asigura*Schema`, prin Reflection, în procese noi, până nu se mai schimbă; pe o copie a codului (arborele de lucru, sau `--repo=<clonă>@<commit>` cu `git archive`), cu pluginurile toate pornite, fără licență, pe o bază `cms_mig_fx_*`. `--cod=<cod>` scrie `<cod>.sql` (doar structură, se încarcă pe MariaDB și pe MySQL 8) și `<cod>-amprenta.json` în `$CMS_DEV_DIR/fixture-migrari/` (`--iesire=`; fixture-urile din git, WP-04: `tools/dev/migrari/vechi/`); `--compara` pune amprentele una lângă alta. `--fixture-uri` scrie fixture-urile din git (`tools/dev/migrari/vechi/`), `--verifica-fixture-uri` verifică că sunt la zi (T2). `--asteptat` scrie `database/migrari/asteptat.json`, `migrari.lock` (o migrare publicată schimbată e refuzată) și `ultima.php`. `--verifica` (poarta G-baza) e acceptanța migrărilor de bază ([MIGRARI.md](MIGRARI.md), „Migrările de bază”), pe ambele servere, inclusiv migrările de date: aceleași rânduri inventate trec prin funcțiile codului și prin migrări și trebuie să iasă la fel. Portat din uneltele analizei migrărilor. |
| `migrari/vechi/` | Fixture-urile testelor migrărilor: `<cod>.sql` (schema pe care o face codul cms, bs, bv, vt, gr, din „ultimul commit revizuit”, doar structură) și `extra-<cod>.txt`, scrise de `genereaza-fixture.php --fixture-uri`; `variante.sql` + `extra-variante.txt` (o bază veche, scrisă de mână, cu rânduri inventate); `privat/` (sonda live, ignorat de git). `migrari/excluderi.php`: ce nu primește o instalare nouă. |
| `migrari/din-sonda.php` | Din sonda unui site live (`tools/sonda/amprenta-schema.php`, fișierul trimis de proprietar): `tools/dev/migrari/vechi/privat/<cod>-live.sql` (doar structură), `extra-<cod>.txt` (ce are site-ul în plus față de `database/migrari/asteptat.json` sau `--fata-de=`) și `<cod>-fata-de-asteptat.txt`. `privat/` e ignorat de git (repo public, OD-49); refuză o intrare sau o ieșire din repo neignorată, și cu căi relative (făcute absolute față de dosarul curent înainte de verificare). |
| `porti.sh` | Porțile unui pachet de lucru din planul Etapei 1 (tabelul WP → porți e în script; `--lista` îl arată), în ordine, oprindu-se la prima care pică; o poartă a cărei unealtă vine într-un WP următor pică, nu se sare. Raportul: `$CMS_DEV_DIR/porti-<WP>.txt`; rezumatul lui intră în mesajul ultimului commit al WP-ului. |
| `de-portat.php` | Commit-urile noi de pe ramurile celor patru coduri vechi (`CMS_DEV_REPO_BS`, `_BV`, `_VT`, `_GR`), de după „ultimul commit revizuit” din [de-portat.md](de-portat.md). Doar citește (fără `git fetch`); iese cu 2 dacă sunt commit-uri noi. `--revizuit=<site>` scrie doar commit-ul revizuit (îl folosește `compara-cu-baza.sh`). |
| `test-seo.php` | Testele SEO ([SEO.md](SEO.md)): excluderile (fiecare adresă mereu deschisă și fiecare rută de plată, cont, admin, API), harta demo și pachetul Bioscem, robots.txt, JSON-LD și sitemap-ul pe fiecare pachet (fiecare adresă din sitemap cerută), gazda canonică pe un al doilea server (`CMS_DEV_PORT`+2, `APP_URL=https://exemplu-demo.test`, inventat: fiecare POST și fiecare cale exclusă pe www răspunde exact ca pe gazda corectă), mentenanța, oprirea de urgență (`CMS_DEV_PORT`+3, `SEO_REDIRECTURI=0`). Rulează și ca secțiunea G din `test-pluginuri.php`. Raport: `$CMS_DEV_DIR/test-seo-raport.txt`. |
| `site-demo/` | Datele inventate ale site-ului de dezvoltare (`redirecturi.json`, `seo.json`): câte o regulă pentru fiecare caz verificat de `test-seo.php`. `comun.sh` pune `SITE_DATE_DOSAR` aici. |
| `crawl-pachet.cjs` | Folosit de `test-pluginuri.php`: deschide paginile unui pachet în Chromium (vizitator, client logat cu un produs în coș, admin cu ferestrele comenzilor și ale produselor), întoarce linkurile, formularele și textul vizibil din DOM și erorile JavaScript, fotografiază meniul adminului. |

Variabile pe care le poți schimba din mediu:

| Variabilă | Implicit | Ce e |
|---|---|---|
| `CMS_DEV_DB` | `cms_dev` | baza de test; trebuie să înceapă cu `cms_` (se șterge la fiecare instalare) |
| `CMS_DEV_SOCK` | `/tmp/claude-0/maria/sock` | socketul MariaDB |
| `CMS_DEV_MYSQL8_DIR`, `CMS_DEV_SOCK_MYSQL8` | `/tmp/claude-0/my8`, `…/run/sock` | al doilea server, MySQL 8, pentru `test-migrari.php` (`tools/dev/mysql8.sh`) |
| `CMS_DEV_DB_USER` / `CMS_DEV_DB_PAROLA` | `root` / gol | utilizatorul bazei |
| `CMS_DEV_PORT` | `8701` | portul serverului; uneltele folosesc și porturile următoare: +1 copia de bază, +2/+3 `test-seo.php`, +4 codul curent în `compara-cu-baza.sh` față de un repo vechi, +7 copia din `test-verificare.php` |
| `CMS_DEV_PHP` | `php` | comanda PHP a tuturor uneltelor (de exemplu `php8.3`, PHP-ul minim) |
| `CMS_DEV_REPO_BS`, `CMS_DEV_REPO_BV`, `CMS_DEV_REPO_VT`, `CMS_DEV_REPO_GR` | `../Nou-site-bioscem`, `../Mutare-Site-Biovitality`, `../vertij-tinitus-refacut`, `../site-nou-grafoanaytis.ro` | clonele codurilor vechi (doar citite), pentru `compara-cu-baza.sh` și `de-portat.php` |
| `CMS_DEV_DIR` | `storage/logs/dev` | jurnale, emailuri capturate, parola, raportul testului |
| `CMS_DEV_LICENTA_DOSAR` | gol (= `storage/licenta`) | alt dosar al licenței (devine `LICENTA_DOSAR`), pentru un test care nu vrea să atingă licența locală și nici să depindă de ea (`test-migrari.php`, T4 și T10: licența „toate” în dosarul lui de lucru) |
| `SITE_DATE_DOSAR` | `tools/dev/site-demo` | datele site-ului (harta adreselor vechi, regulile SEO); vezi [SEO.md](SEO.md) |
| `CMS_DEV_CAPTURI` | `$CMS_DEV_DIR/capturi` | unde scrie Playwright PNG-urile |
| `NODE_PATH`, `PLAYWRIGHT_BROWSERS_PATH` | `/opt/node22/lib/node_modules`, `/opt/pw-browsers` | unde e Playwright |

### Cum rulează aplicația în dezvoltare

- **Fără `.env`.** Configurarea vine din variabile de mediu puse de
  `comun.sh`. Dacă există un `.env` în rădăcină, uneltele refuză să pornească:
  `.env` ar avea prioritate și ar putea indica o bază reală. Fără nicio bază
  configurată (nici `.env`, nici `DB_DATABASE` în mediu) aplicația răspunde la
  orice cu 503 „Site neconfigurat” (poarta din `public/index.php`,
  [VERIFICARE.md](VERIFICARE.md)), deci un server pornit altfel decât prin
  `comun.sh` nu arată nimic.
- **Doar baze `cms_*`.** `instaleaza.sh` și `date-demo.php` refuză orice alt nume.
- **PHP** pornește cu `display_errors=0`, `error_reporting=E_ALL`, toate
  mesajele în `storage/logs/dev/php-erori.log`, `allow_url_fopen=0` și
  `sendmail_path` = `mail-capturat.sh`.
- **Sesiunile** stau, ca pe server, în `storage/sessions/` (ignorat de git). Doar ale
  paginilor: linia de comandă (cronurile, `tools/dev/php.sh …`) nu pornește sesiuni.
- **Licența.** `comun.sh` dă aplicației cheia publică a perechii de chei de
  dezvoltare (`LICENTA_CHEIE_PUBLICA`, cheia privată în
  `storage/logs/dev/licenta-cheie-privata.txt`, niciodată în git) și
  `LICENTA_SITE=demo`; `instaleaza.sh` scrie licența „toate”, deci local site-ul
  are toate pluginurile pornite, plus unealta internă de migrare (licența de
  dezvoltare a Bioscem nu o are: `licenta.php bioscem`; și cu ea paginile ies identice
  cu Bioscem azi, vezi `compara-cu-baza.sh --licenta=bioscem`). Licența stă în
  `storage/licenta/` (ignorat de git), lângă `stare-aplicata.json` (ce a aplicat
  site-ul ultima oară). `comun.sh` pune și
  `CMS_DEV_GARDA_ANTET=1`: garda pluginurilor adaugă atunci antetul
  `X-Andaxi-Garda`, ca testele să știe că a răspuns ea; pe site-uri variabila nu există.
  La fel `CMS_DEV_MARCAJE_PORT=1`: `App\Support\Dev::marcaj()` scrie atunci marcajele
  porturilor (mai jos); pe site-uri e un șir gol.

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

### Bioscem nu se schimbă: cum se dovedește

Până trece pe codul comun, Bioscem merge pe codul lui (`Nou-site-bioscem`). Dovada că
ANDAXI-CMS face exact ce face Bioscem e comparația cu **codul Bioscem însuși**, nu cu o
copie veche din acest repo:

- [de-portat.md](de-portat.md) ține, pentru fiecare cod vechi, „ultimul commit revizuit”
  și starea fiecărui commit de după desprindere. `tools/dev/php.sh tools/dev/de-portat.php`
  arată ce a apărut nou (verificarea săptămânală). Un commit Bioscem portat mută „ultimul
  commit revizuit” la el; `compara-cu-baza.sh` se compară apoi cu acel commit.
- `compara-cu-baza.sh` (cu licența „toate” și cu `--licenta=bioscem`) trebuie să iasă cu 0.
  Se compară codul, `Location`, anteturile (`Content-Type`, `Cache-Control`, `X-Robots-Tag`,
  cookie-urile …) și corpul fiecărui răspuns, iar pentru salvările din admin și rândurile
  rămase în bază (un port care schimbă doar ce se salvează primește aici un caz nou, în
  `$salvari` din `compara-cu-baza.php`, cu commit-ul Bioscem din care vine; POST-ul lui schimbă
  și un câmp care arată că salvarea a ajuns în bază, iar rezultatul Bioscem trebuie să arate
  schimbarea commit-ului, sau, la un caz de tip „regresie”, ramura pe care commit-ul nu o schimbă). O diferență voită se declară în `compara-cu-baza.php`, cu pagina și motivul ei: textele
  mici în listele `$deBioscem` (ce scrie nucleul altfel decât Bioscem), `$porturiBiovitality`,
  `$porturiVertij`, `$porturiGrafo`; o pagină care în Bioscem cade și aici merge, în
  `$coduriDeBioscem` (cu ambele coduri și un text al paginii); o bucată mai mare adusă dintr-un site vechi se scrie
  între marcaje, `<?= Dev::marcaj('bv:nume') ?>…<?= Dev::marcaj('bv:nume', true) ?>`
  (`App\Support\Dev`), declarate în `$marcajePort`. Un marcaj se scrie la mijlocul rândului
  (un `?>` de la capăt de rând înghite sfârșitul de rând); fiecare trebuie găsit exact o dată
  pe pagina lui. Ce vede clientul altfel intră și în `site-uri/bioscem/ce-se-schimba.md`.
- Ce nu se vede în HTML (scripturile de cron, `install.php`, `diagnostic.php`, un JavaScript
  care se schimbă doar cu un plugin oprit) e scris în capul lui `compara-cu-baza.sh`.

### Testul de fum (`tools/dev/smoke.sh`)

Pornește de la o bază goală și verifică, în ordine:

1. instalarea (`install.php` + `seed.php` + date demo), apoi, fără server,
   instantaneul rutelor, mecanismul diferențelor declarate ale comparației cu baza și
   verificarea fișierelor de migrare (`verifica-migrari.php`: și DDL-ul nou din cod);
2. site-ul public: acasă, magazin, categorie, marcă, produs cu și fără șablon,
   produs cu oferte BBD, produs la precomandă, căutare, adăugare în coș, coș,
   checkout, login, înregistrare, cont fără login (redirect), blog, articol,
   contact, pagină legală, `robots.txt`, 404, `/health`; apoi SEO: `/sitemap.xml`
   (cu produse doar cu catalogul), robots.txt (regulile coșului doar cu magazinul),
   datele structurate (ofertă doar cu magazinul pornit), „/” final → 301, o adresă
   veche din datele site-ului → 301, un POST cu „/” final neatins;
3. o comandă reală, ramburs, plasată ca un client (cu protecția anti-robot):
   redirect la pagina de mulțumire, comanda în bază, emailul capturat;
4. adresele de confirmare fără semnătură (Stripe, EuPlătesc, BT, ERP): trebuie
   refuzate (400/401/403), niciodată 404 sau 500; adresele pluginurilor care nu sunt în
   pachet (`/login`, `/register`, `/auth/google`, `/plata/…`, recenziile, `POST /contul-meu/login`)
   dau 404 cu antetul gărzii;
5. contul de client (login + istoricul comenzilor);
6. adminul: login, dashboard, **fiecare intrare din meniu** (citită din
   pagină, nu scrisă de mână), paginile la care se ajunge din butoane, exporturi,
   generarea sitemap-ului și `/sitemap.xml`, logout; un admin de blog nu intră
   la plăți;
7. fiecare cron, o dată (cererile lor spre ERP/FAN/bancă trebuie să se oprească
   local), apoi `scripts/migreaza.php --stare` și `--plan` pe baza de test (făcută încă de
   cod: migrările de bază trebuie să găsească tot, fără nicio operație în grupul B,
   0001–0019); niciunul nu lasă un fișier de sesiune nou;
8. jurnalul PHP: niciun `Warning`, `Notice` sau `Fatal` (mesajele `Deprecated`
   se raportează, dar nu opresc testul);
9. capturi Playwright: dashboard, meniul cu toate grupurile deschise, lista de
   comenzi; cererile paginii spre alte servere sunt blocate și raportate.

Raportul: `storage/logs/dev/smoke-raport.txt`. Codul de ieșire e 0 doar dacă
totul a trecut.

**Pe pachete** (`--pachet=<nume>` sau `--toate-pachetele`): după instalare se
pune licența pachetului (prezentare, catalog, magazin, asociatie; sau, cu `--pachet=`,
licența de dezvoltare a unui site existent: bioscem, biovitality, vertij, grafoanaytis), iar fiecare
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
5. **Fiecare rulare a unui script CLI (inclusiv cronurile) deschidea o sesiune
   PHP** (`bootstrap.php`) și lăsa un fișier gol în `storage/sessions/`. Pe
   Bioscem, 878 din cele 1.711 fișiere de sesiune din clona locală sunt goale.
   Se șterg singure după 30 de zile. **Reparat** (WP-02, odată cu runner-ul
   migrărilor): în linia de comandă `$_SESSION` e un array gol, fără fișier
   (`ANDAXI_CU_SESIUNE` pentru un script care chiar vrea o sesiune); paginile, neschimbate.
   Trecut în `site-uri/bioscem/ce-se-schimba.md` și în capul lui `compara-cu-baza.sh`.
6. **Adminul încarcă CodeMirror de pe `cdn.jsdelivr.net` pe fiecare pagină**:
   fără acel CDN, editoarele de cod nu merg. Nereparat (portul C6/C7 din
   Biovitality, încărcare leneșă).
7. **`/checkout/succes` deschis direct (fără numărul comenzii) dădea eroare 500**:
   pagina din bază cu tokenul `{{checkout_success_order_info}}` trimitea starea
   comenzii (text) în locul totalului (număr), iar PHP-ul cu tipuri stricte
   oprea pagina (`TypeError`). Găsit de testul pluginurilor, care cere fiecare
   rută. **Reparat** (al doilea parametru nu se mai trimite); pagina de
   mulțumire reală, `/checkout/succes/<număr>`, nu era afectată. Bioscem live are
   încă eroarea: la trecere e o diferență vizibilă, trecută în
   `site-uri/bioscem/ce-se-schimba.md` și declarată în `compara-cu-baza.php`
   (`$coduriDeBioscem`: pagina se cere și trece doar cu 500 în Bioscem și pagina aici).

8. **Securitate formulare: rândurile din aceeași secundă nu au o ordine fixă.** Lista
   unește jurnalul de înregistrare și pe cel de checkout și le ordonează după
   `created_at, id`; un rând din fiecare tabel cu aceeași secundă și același id (le scriu
   testele, câte o cerere în fiecare) apare când într-o ordine, când în alta. Găsit de
   comparația cu codul Bioscem (are aceeași interogare). Nereparat (schimbă doar ordinea a
   două rânduri din admin, rar); `compara-cu-baza.php` scoate din baza de test rândurile de
   checkout care se suprapun așa, înainte de comparare.

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
- O cerere `HEAD` pe o adresă corectă răspunde 404: routerul are doar rute GET și
  POST (moștenit din Bioscem, neschimbat). Stratul SEO tratează HEAD ca GET doar
  când are ceva de reparat (de exemplu „/” final → 301).
