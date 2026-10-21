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
cronul, fără secrete). Din Bioscem `7c561fa` și `0f5f568` (WP-R): `seo-texte.php` și
`avertisment-produse.php`, scripturi de aplicat o dată pe un site, generice: textele și
produsele le citesc din dosarul site-ului (`seo-texte.json`, `avertismente-produse.json`;
Bioscem: `site-uri/bioscem/`); `--plan` arată ce ar schimba, `--aplica` scrie (după o copie
a valorilor de acum în `storage/`), `--restaureaza` le pune la loc din copie.

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
tools/dev/instaleaza.sh                  # doar baza de test, de la zero (prin migrări), cu date demo și licența „toate”
tools/dev/instaleaza.sh --fara-demo      # doar install.php + seed.php (+ licența)
tools/dev/instaleaza.sh --pe-vechi       # baza făcută ca înainte de migrări, cu date demo, apoi baseline-ul și pașii de activare (un site vechi adoptat)
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
tools/dev/compara-cu-baza.sh --baza=f5ae5ea         # istoric: față de codul dinaintea pluginurilor (livrarea 1 SEO și porturile Bioscem, pe commit-uri, scoase explicit)
tools/dev/php.sh tools/dev/test-seo.php             # SEO pe fiecare pachet: adresa unică, harta, sitemap, robots.txt, date structurate (docs/SEO.md)
tools/dev/php.sh tools/dev/test-verificare.php      # site-ul de verificare: poarta, gărzile, configureaza/stare/licenta/sterge.php (docs/VERIFICARE.md)
tools/dev/php.sh tools/dev/test-sonde.php           # verificările pe site-urile live: agregate.php, sonda schemei, din-sonda.php (docs/VERIFICARE.md)
tools/dev/php.sh tools/dev/test-incarcare.php       # încărcarea copiei de verificare: incarca.php --din (anonimizată în drum), --reia, --demo (docs/VERIFICARE.md)
tools/dev/compara-calcule-local.sh                  # golden master-ul (codul Bioscem însuși față de codul comun, aceleași comenzi: diferite 0) și amprenta paginilor (docs/VERIFICARE.md)
tools/dev/php.sh tools/dev/test-paths.php           # căile site-ului (Paths), stub-ul, MENTENANTA și pingul, pe o găzduire simulată din Etapa 2 (mai jos, „Unde scrie codul”)
tools/dev/php.sh tools/dev/migrari/din-sonda.php <amprenta.json>   # sonda unui site live → fixture în tools/dev/migrari/vechi/privat/ (în afara git-ului)
tools/dev/php.sh scripts/migreaza.php --plan         # migrările: ce s-ar face pe baza de test (--aplica, --verifica, --stare; docs/MIGRARI.md)
tools/dev/php.sh tools/dev/test-migrari.php          # testele migrărilor: runner-ul și T1–T11 pe schemele celor cinci coduri (MariaDB și MySQL 8)
tools/dev/php.sh tools/dev/test-migrari.php --runner # doar runner-ul, cu fiecare cod de ieșire (--migrari: doar migrările adevărate)
tools/dev/php.sh tools/dev/verifica-migrari.php      # fișierele de migrare și DDL-ul din cod (și în testul de fum)
tools/dev/php.sh tools/dev/test-instalare.php        # instalarea prin migrări, garda, /health, pașii de activare, 0 DDL pe cerere
tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate   # înghețarea setărilor celor patru site-uri (după orice schimbare a DEFAULTS sau a unei rezerve)
tools/dev/php.sh tools/inghetare/verifica-inghetare.php        # înghețările: complete, fără date private, reproductibile din clone (și în testul de fum)
tools/dev/php.sh tools/dev/test-inghetare.php        # testele generatorului și ale verificării (pasul din runner: test-migrari.php, secțiunea I)
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
| `instaleaza.sh` | Șterge și recreează baza de test, rulează `scripts/install.php` (migrările, seed-ul neutru, setările, pașii de activare, adminul; [MIGRARI.md](MIGRARI.md), „Instalarea”), `scripts/seed.php`, apoi `date-demo.php` și licența „toate”. Curăță sesiunile, cache-ul, emailurile capturate și sitemap-ul generat. `--pe-vechi`: baza făcută ca înainte de migrări (`migrari/pe-vechi.php`), seed, date demo, licența, apoi baseline-ul (`migreaza.php --aplica`), ca un site vechi adoptat. `--sterge-baza`: doar șterge baza `CMS_DEV_DB` (o bază făcută pentru o poartă). |
| `date-demo.php` | Pune setările de dezvoltare și scrie datele demo. Schema o fac migrările (nu mai cheamă funcțiile `ensure*`); categoriile de blog ale articolelor demo: „Noutăți” și „Evenimente” (seed-ul neutru) și „Ghiduri demo”. Singura excepție de la „doar baze `cms_*`”: copia demonstrativă de pe site-ul de verificare (`tools/verificare/incarca.php --demo`, WP-09), care îl încarcă după ce definește `ANDAXI_DEMO_PE_VERIFICARE`; atunci cere `SITE_COPIE_VERIFICARE=1` și o bază cu `_verificare` (sursa `demo`, `anonimizat = 0`), iar un refuz e o excepție (încărcarea șterge copia). Cu numerotarea veche a comenzilor (`order_number_mode` „legacy”, pachetul vertij) nu dă de două ori același număr. |
| `server.sh` | Pornește `php -S` pe `127.0.0.1:$CMS_DEV_PORT`, cu `public/` ca rădăcină și `router.php`. |
| `router.php` | Face pentru `php -S` ce fac pe Hostinger cele două `.htaccess`: fișierele existente se servesc direct, restul ajunge la `public/index.php`. |
| `php.sh` | Rulează un script PHP al proiectului cu mediul de dezvoltare (pentru cronuri). |
| `mail-capturat.sh` | Ține locul lui `sendmail`: fiecare email trimis cu `mail()` se scrie în `storage/logs/dev/mail/*.eml`. |
| `smoke.sh` | Testul de fum (mai jos), pe pachetul „toate” sau pe oricare pachet. |
| `capturi.cjs` | Capturile Playwright din admin. |
| `licenta.php` | Perechea de chei de dezvoltare (în locul celei a CRM-ului) și licențele de test: pachete, „toate” (cu unealta internă), licențele de dezvoltare ale celor patru site-uri existente (`bioscem`, `biovitality`, `vertij`, `grafoanaytis`: liste scrise explicit în `config/plugins.php` → `licente_dev`, ce are codul vechi al fiecăruia, nu contractul; un plugin portat mai târziu nu intră singur în licența Bioscem), `cheie=stare`, fără licență (șterge și starea aplicată); `stari`, `cron`, `arata`, `presetari` pentru teste. |
| `verifica-rute.php` | Listează fiecare rută cu proprietarul ei (nucleu sau plugin) și pică dacă o rută nu are proprietar, dacă o declarație din catalog nu acoperă nicio rută, dacă meniul și rutele nu se potrivesc, dacă un cron nu are garda, dacă codul întreabă de un plugin inexistent sau dacă un bloc `<?php if (…): ?>` de pe coloana 0 dintr-un șablon nu închide HTML-ul pe care îl deschide (un `endif` pus greșit lasă, cu pluginul oprit, jumătăți de tabel). |
| `test-pluginuri.php` | Testele mecanismului de pluginuri: licența (A: și ultima licență aplicată când nicio licență nu se verifică, codul 2 al cronurilor fără licență, momentul intrării în „se oprește”, jurnalul o dată pe zi, dosarul fără drept de scriere, JSON-ul canonic față de Node, filtrul care nu atinge listele goale ale clientului), garda rutelor pe fiecare pachet (B: plus linkul de urmărire din newsletter, robots.txt, API-urile catalogului, „Trimite email test”, acțiunile necunoscute din Setări magazin, adresele mereu deschise fără o plată adevărată, redirecționările în buclă), fiecare pachet parcurs pagină cu pagină pe site, în contul clientului și în tot meniul adminului, plus cu Playwright și sursa editoarelor de pagini și de șablon de produs (C), magazinul „se oprește” cu o comandă existentă dusă la capăt, ERP-ul care trimite comenzile plasate înainte de oprire, FAN oprit fără niciun AWB și fără regula coletelor trimisă în ERP, Setări livrare fără ERP (D), comparația cu codul de bază, cu licența „toate” și cu cea a Bioscem (E) și, la cerere, fiecare plugin oprit pe rând cu restul pornite (F). În browser se verifică și textul vizibil: nicio etichetă sau explicație a unui plugin oprit, nici în ferestrele comenzilor și ale produselor, în tab-urile setărilor sau în contul clientului. Plus SEO (G: `test-seo.php`, pe fiecare pachet). Opțiuni: `--fara-garda`, `--fara-browser`, `--fara-baza`, `--fara-seo`, `--fiecare-plugin`, `--capturi=DIR`. Pornește singur serverul dacă nu merge. |
| `compara-cu-baza.sh` (+ `.php`, `lib/diferente-asteptate.php`, `lib/salvari-admin.php`, `lib/normalizeaza-html.php`) | **Implicit, față de codul Bioscem însuși**: scoate cu `git archive` commit-ul „ultimul revizuit” al Bioscem din `docs/de-portat.md` (azi `4254ca4`) din clona `CMS_DEV_REPO_BS` în `$CMS_DEV_DIR/baza-Nou-site-bioscem-<commit>`, îl pornește pe portul +1 (cu routerul de aici și o copie a pozelor încărcate) și pornește codul curent pe portul +4 cu datele site-ului Bioscem (`site-uri/bioscem/`; Bioscem le are scrise în cod), pe aceeași bază. Compară răspunsul a peste 70 de pagini-cheie (vizitator, client, patru roluri de admin, o pagină de test cu liste goale scrise de client), plus `/sitemap.xml`, adresa unică (litere mari, „/” final) și fiecare regulă din harta adreselor vechi a Bioscem (din pachet și din `config/redirecturi.php` al codului Bioscem; cele două liste trebuie să fie aceleași, deci o regulă nouă neportată pică): codul, `Location`, anteturile (tot ce trimite codul, în ordine, fără `Date`, `Content-Length` și antetul gărzii de dezvoltare; valoarea cookie-urilor, `ETag` și `Last-Modified` mascate) și pagina, fără tokenuri și versiuni de fișiere (normalizarea e în `lib/normalizeaza-html.php`, din WP-11 comună cu amprenta paginilor, `tools/verificare/amprenta-site.php`). Plus **salvările din admin** (ce rămâne în bază după un POST, pe care paginile GET nu-l văd): cheia ofertelor BBD la salvarea produsului (cu o ofertă nouă care s-ar ciocni, o cheie dublată, una care nu e text), categoriile bifate în editorul unei postări, re-salvarea ei (categoria principală păstrată, debifată, trimisă explicit) și duplicarea; din livrarea 2 SEO și avertismentul pe produs (WP-R, Bioscem `6c8c789` … `4254ca4`): un antet, un meniu și un subsol cu logo-ul și linkuri vechi spre categorii, câmpurile SEO ale unei categorii și ale unei mărci, numele magazinului, livrarea cu prețuri fixe (datele structurate a trei produse și fluxul Merchant), avertismentul salvat din editorul produsului, păstrat de o salvare fără câmpurile lui, golit, fereastra din coș și caseta de la finalizarea comenzii, o comandă refuzată fără bifă și una plasată cu bifa (rândurile comenzii, confirmarea, emailurile capturate, Admin → Comenzi; id-ul comenzii și ora scoase din comparație); cele două constante Bioscem mutate în `site-uri/bioscem/seo.json` pe care datele demo nu le ating: titlul unui produs al unei mărci cu „Harrison” (cuvântul generic al mărcilor, `titluri.cuvinte_generice_marca`) și id-urile `BS-<id>` din flux ale produselor cu codul gol sau dublat (`flux_merchant.prefix_id`; trei copii ale ceaiului de mentă, cu id-uri fixe). O cerere a unui caz poate fi a unui vizitator nou (`'viz'`), a unui vizitator nou care a acceptat toate cookie-urile (`'viz-acord'`), poate aștepta (`'pauza'`, garda anti-robot a checkout-ului) și poate fi făcută din răspunsurile de dinainte (tokenul formularului). Un caz poate veni din alt site (`'sursa' => 'vertij'`): videoul .mp4 al unei postări (ramura pe care portul vertij `0fc2b2c` nu o schimbă) și un link YouTube (vizitator fără acord: caseta „Pornește videoclipul”) și unul Vimeo (cu acord: playerul, cu `dnt=1`), unde pe codul Bioscem trebuie să fie caseta video de azi, iar diferența e declarată în `$porturiVertij` doar pe paginile acelor postări. Pe durata comparației, `compara-cu-baza.sh` pune în `public/` logo-ul de test (`seo.json` → `logo_antet`, de mărimea declarată) și fișierele publice ale pachetului (`site-uri/<site>/public/`), și le scoate la final. Fiecare caz pregătește câteva rânduri, trimite aceeași cerere întâi codului Bioscem, apoi codului curent, cu baza pusă la loc înainte de fiecare și la final (verificat: aceleași rânduri ca la început), și compară răspunsul și rândurile atinse. Pe rezultatul codului Bioscem, ca niciun caz să nu treacă în gol: fiecare salvare trebuie să se vadă în bază (re-salvarea schimbă și rezumatul postării, deci o salvare care nu scrie nimic pică, deși redirecționează la fel), un caz de tip „schimbare” trebuie să arate schimbarea commit-ului din care vine (fără ea rezultatul ar fi altul), iar cele două cazuri de tip „regresie” (principala debifată, `category_id` trimis explicit) cer rezultatul de pe ramurile pe care `b3781a4` nu le schimbă. Paginile la care codul de stare diferă voit (`$coduriDeBioscem`: azi `/checkout/succes` deschis direct, 500 în Bioscem, pagina aici, „Erori moștenite” 7; și pingul `/_andaxi/ping`, 404 în Bioscem, JSON-ul aici, WP-07) trec doar cu exact codurile declarate și cu textul paginii pe pagina curentă. Nimic din livrarea 1 SEO nu se mai scoate: Bioscem o are deja. Se scot doar diferențele declarate explicit, fiecare cu pagina și motivul ei (`$deBioscem`, `$porturiBiovitality`, `$porturiVertij`, `$porturiGrafo`, marcajele de port `$marcajePort`); fiecare trebuie găsită exact o dată, pe pagina ei, iar una negăsită în toată rularea (pagina ei nu se mai cere) pică la final. O declarație pe un prefix de pagini (`/admin*`) poate scoate câteva pagini (`fara_pagini`, unde textul nu are voie să apară: de exemplu editoarele de cod, care încarcă și acum CodeMirror, WP-12 C6) și poate cere alt număr de apariții pe o pagină anume (`ori_pe_pagina`, de exemplu cele două selectoare de imagini din Setări magazin, C7); un răspuns 3xx (redirecționarea unui rol fără drept) nu are pagină, deci declarațiile nu i se aplică. **Reparațiile** (cazurile de salvare de tip „reparatie”, WP-12): un defect al Bioscem pe care codul comun îl repară voit (C2: salvarea produsului fără câmpul ofertelor le păstrează; X1b: o postare cu categoria doar ca text o păstrează la re-salvare, iar una cu numele unei categorii din listă e bifată și se leagă); `verifica` cere defectul pe rezultatul codului Bioscem, `verifica_curent` reparația pe al codului curent, iar starea diferă exact prin `diferente` (nici mai mult, nici altceva). Pe durata comparației, setările înghețate ale Bioscem (`site-uri/bioscem/setari-inghetate.json`, fără cele private; azi doar `erp_trimite_din` gol) sunt puse în baza de test, iar la final valorile de dinainte (sau lipsa lor) se pun la loc. Altă bază: `--baza-repo=<clonă>@<commit>`. Cu `--licenta=bioscem` pune înainte licența de dezvoltare a Bioscem (fără unealta internă; panoul „Import din backup WordPress” lipsește intenționat și nu se compară) și la final pune la loc „toate”. **Istoric**, `--baza=f5ae5ea` (poarta G-cmp-istoric): față de commit-ul dinaintea pluginurilor din acest repo, cu diferențele SEO ale livrării 1 (date structurate, robots.txt, width/height, poza principală, stilurile catalogului, textul „Sitemap XML”) și porturile din Bioscem după el (`$porturiBioscem`, fiecare cu commit-ul Bioscem din care vine: colete `de5c2b0`; livrarea 2 SEO și avertismentul pe produs, `6c8c789` … `4254ca4`, din WP-R, 21 octombrie) scoase explicit, doar din pagina codului curent. O declarație poate avea o listă de pagini (aceeași schimbare pe mai multe, de exemplu un rând nou în `<head>`) și numărul exact de apariții pe fiecare (`ori`, de exemplu titlul produsului din `<title>`, `og:title` și `twitter:title`); un port Bioscem nou își adaugă aici declarațiile, în același commit (față de Bioscem lista nu se aplică). `--autotest`: doar verificarea mecanismului de diferențe (texte, marcaje, coduri de stare, anteturi: un `X-Robots-Tag` pierdut, un `Cache-Control` schimbat sau un cod declarat care nu mai e cel primit pică; o declarație greșită, fără motiv, cu o listă de pagini goală sau cu `ori` care nu e un întreg de cel puțin 1, pică cu numele câmpului, și pe o pagină pe care numărătoarea s-ar potrivi). Diferențele (cu anteturile în capul fiecărui fișier): `$CMS_DEV_DIR/compara/*.diff`. |
| `instantaneu-rute.php` (+ `instantanee/`, `lib/cereri-rute.php`) | Instantaneul rutelor: pentru fiecare licență de test (`toate`, `fara`, cele patru site-uri, cele patru pachete), ce face garda cu fiecare rută (`CONTROLLER`, `MEREU`, `403`, `404`), meniul adminului și cronurile, comparate cu `tools/dev/instantanee/rute-<licența>.txt` din git. O schimbare dă un diff și codul 1; `--actualizeaza` rescrie fișierele, care intră apoi în commit (cine revizuiește vede în `git diff` orice adresă nouă a unui pachet). Rutele împărțite (`rute_impartite`) apar cu fiecare valoare din fiecare listă (o valoare mutată la alt plugin se vede în diff); aceeași listă de cereri o folosește testul B. Plus regulile scrise în script, independente de catalog: fără magazin, coșul, checkout-ul, plățile și comenzile nu ajung niciodată la controller (nici ca `MEREU`); la fel conturile, recenziile, acordurile GDPR fără pluginul lor; adresele mereu deschise rămân deschise în orice licență și sunt doar cele din lista fixă `$mereuDeschiseFixe` a scriptului (una nouă se adaugă acolo, explicit); `licente_dev.bioscem` e lista Bioscem de la `4915478`. Regulile pică și cu `--actualizeaza`. Fără server și fără bază; rulează în testul de fum și ca secțiunea B0 a testului pluginurilor. |
| `verifica-php.sh` | `php<php_min> -l` pe tot ce ajunge pe site-uri (`src/`, `views/`, `config/`, `scripts/`, `public/`, `bootstrap.php`, `database/` cu migrările, `deploy/` cu stub-ul, `tools/verificare/`, `tools/sonda/`, plus `tools/dev/lib/normalizeaza-html.php`, pe care îl încarcă `amprenta-site.php` pe server). |
| `test-paths.php` | Testele căilor site-ului (WP-07; mai jos, „Unde scrie codul”; poarta G-paths, și cu PHP-ul minim). **A**, fără server: în așezarea de azi fiecare cale `Paths` e cea de până acum; într-un proces cu constantele stub-ului, `.env` și `storage/` ale dosarului site-ului, docroot-ul din `PLATFORMA_PUBLIC_DIR` (fără el, `public_html/` de lângă site), dosarele private cu gardul lor, jurnalul PHP al site-ului; căutarea din acceptanța WP-07 (nicio cale `storage/`, `uploads/`, `sessions/`, `.env` sau `public/` construită în afara `Paths` în `bootstrap.php`, `src/`, `views/`, `public/`, `scripts/`, `config/`; și pe construcțiile vechi, ca să nu treacă în gol); erorile PHP ale liniei de comandă, cu setările php.ini-ului obișnuit, pe stderr (în Etapa 2 și în jurnalul site-ului). Doar A: `--doar-cai` (îl rulează testul de fum). **B**, o găzduire simulată din Etapa 2 în `$CMS_DEV_DIR/paths/acasa`: o versiune de cod comună (fișierele din git, cu modificările necomise, `VERSION`, plus un `.env` și o poză „capcană” pe care nu are voie să le folosească nimeni) și două site-uri cu bazele lor (`cms_paths_a`, `cms_paths_b`, instalate cu `scripts/install.php` ca `bin/platforma --site=`), servite cu stub-ul din `deploy/stub/` de `php -S` pe `CMS_DEV_PORT`+9 și +10, cu un mediu gol: pingul (versiunea, `la_zi`, no-store, fără cookie și sesiune, și cu „/” la sfârșit), câte o pagină cu numele din baza site-ului, sesiunea în `site/storage/sessions` al fiecăruia (cookie-ul unui site nu deschide nimic pe celălalt), pozele din `public_html/uploads`, poza de distribuire cu cache-ul în `site/storage/cache/og`, sitemap-ul scris de admin în `public_html`, jurnalul PHP și jurnalul pluginurilor ale site-ului, `MENTENANTA` (503, `Retry-After: 120`, `mentenanta.html`; `/_andaxi/`, `/webhook/`, `/api/erp/` trec), `RELEASE` lipsă sau greșit (503, și unul care fără verificarea formei ar ajunge la versiunea care există, `../releases/<v>`) și o versiune din `site/releases/`, pingul fără bază (503), cu o bază care tace (503 în aproximativ 2 secunde, pe `CMS_DEV_PORT`+11), pe o bază goală și pe una în urma codului, scripturile din linia de comandă (`diagnostic.php`, `migreaza.php`), la urmă versiunea neatinsă (aceleași fișiere, mărimi și date). Își face și își șterge bazele `cms_paths_*`. Raport: `$CMS_DEV_DIR/test-paths-raport.txt`. |
| `test-migrari.php` (+ `migrari/test-baza.php`) | Testele migrărilor ([MIGRARI.md](MIGRARI.md), „Testele”; poarta G-mig, și cu PHP-ul minim; `--runner`: doar runner-ul, poarta G-runner; `--migrari`: doar migrările adevărate). **Runner-ul** pe migrări de jucărie scrise de test: fără bază Server, Amprenta (rândurile din `information_schema` ale MariaDB și ale MySQL dau aceeași amprentă), definițiile și corpul unui CREATE TABLE, Registru; apoi pe MariaDB și pe MySQL 8 detectarea (ce scrie serverul = ce înțelege Amprenta; un tabel sărit de `information_schema` cât altă conexiune îi schimbă structura, 1684, citit din nou), Schema direct (avertisment la altă definiție, index echivalent sub alt nume, cursa cu alt proces, valori repetate numărate fără valori, blocare refuzată; `tabelComplet` și în cursa cu codul vechi care face tabelul cu toate coloanele, dar fără indecși; colația coloanelor, în amprentă doar cea diferită de a tabelului, și CHARACTER SET/COLLATE refuzate într-o migrare) și `scripts/migreaza.php` cu fiecare cod de ieșire: oprit după fiecare operație și reluat (aceeași amprentă, fiecare rând numărat o dată), oprit chiar înainte și chiar după commit-ul fiecărei bucăți (`MIGRARI_TEST_OPRESTE_IN`; pică dacă cursorul ar ieși din tranzacția bucății), bugetul, migrări schimbate, blocante, contract, parametrii site-ului, lacătele (și al sincronizării FAN), starea neatinsă de un runner refuzat (3, 12) și scrisă cu codul final, un tabel ocupat, mărimea tabelelor în plan, un tabel `#sql-*` rămas, `sql_mode`, verificarea, configurarea, pașii site-ului, `--json`, nicio valoare (nici cu ghilimele sau `\` în ea) și nicio parolă în ieșire; aceeași amprentă pe ambele servere (și pentru `tinyint(1) unsigned`, un text implicit `x'y`); o conexiune care primește id-ul de obiect al alteia; linia de comandă fără fișiere de sesiune. **Migrările adevărate**, T1–T11 (WP-04): instalarea nouă (plus G-baza), baseline-ul pe schema fiecărui cod (`migrari/vechi/<cod>.sql`, cu numerele din migrari.md §2.2) și pe o bază veche cu rânduri inventate (aceleași date ca funcțiile codului), pe schema live când vine sonda (T2-live; altfel SĂRIT, cu motivul) și pe o sondă simulată, a doua aplicare, drumul unui site cu `date-demo.php`, opririle după fiecare operație, codul vechi în paralel, migrări schimbate, o migrare blocantă, aceeași amprentă pe ambele servere, funcțiile `ensure*` (ale codului comun și ale Bioscem, fără nicio excepție) pe o bază migrată, cu licența „toate” dintr-un dosar al testului (`CMS_DEV_LICENTA_DOSAR`; licența locală nu contează), verificarea fișierelor pe greșeli puse într-o copie. **Înghețarea setărilor** (secțiunea I, WP-06, în `migrari/test-inghetare-runner.php`): pasul site-ului pe un pachet inventat, cu fiecare cod de ieșire (2, 12, 15), `lipsa`/`lipsa_sau_gol`, valorile private, corectura `-2`, provizoriul (și `--permite-provizoriu` în afara copiei de verificare), `pasi_site` cu pașii așteptați (o rulare care nu-i vede, sau dosarul pachetului lipsă: 2, cheia neatinsă). Își face și își șterge bazele `cms_mig_t_*`; pornește și oprește MySQL 8 dacă nu mergea (`--doar-mariadb`: fără el, rezultat parțial, spus explicit). Raport: `$CMS_DEV_DIR/test-migrari-raport.txt`. |
| `test-instalare.php` | Testele trecerii pe migrări (WP-05; [MIGRARI.md](MIGRARI.md), „Instalarea”, „Garda”, „Pașii de activare”; poarta G-inst, și cu PHP-ul minim): `install.php` și `seed.php` fără server (1, pe stderr), pe o bază goală (schema, seed-ul neutru, setările, adminul, pașii licenței), a doua oară (1, nimic scris), oprit la jumătate și reluat, pe o bază străină, fără licență; garda în fiecare stare, o citire pe conexiune; `/health` (503 în urmă) și bannerul (doar adminul general); pașii de activare la prima cerere, o singură dată, pagina ștearsă rămasă ștearsă, ERP oprit și pornit, nimic scris de pași (nici de cronul ERP) pe o bază în urma codului sau încă neadoptată, apoi rulați după `--aplica`; contorul de instrucțiuni (`CMS_DEV_NUMARA_SQL=1`): 0 DDL pe dashboard, comenzi, produs, o comandă trimisă; o bază ca înainte: peste 100 pe dashboard, după baseline 0; înghețarea setărilor la instalare și la o adoptare oprită înaintea ei (F, WP-06: garda `in_urma` cu `pasi_site`, pragul ERP scris abia după înghețare, și nici după o rulare refuzată fără pachet; `install.php` cu pachetul altui site sau o corectură stricată: 1, motivul pe stderr). Doar MariaDB; își face și își șterge bazele `cms_inst_*`; server pe `CMS_DEV_PORT`+8. Raport: `$CMS_DEV_DIR/test-instalare-raport.txt`. |
| `test-inghetare.php` | Testele înghețării setărilor fără bază (WP-06; [MIGRARI.md](MIGRARI.md), „Înghețarea setărilor”; în G-mig, și cu PHP-ul minim): formatul și fișierul privat, datele private și înlocuitorii, registrul (corecturile, numele greșite), cititorul de cod (`DEFAULTS`, rezervele, `extrage`), generatorul pe un cod vechi inventat (deciziile și legarea lor de valori, determinismul, provizoriu, fișierul închis) și `verifica-inghetare.php` pe pachetele din repo și pe copii stricate. Raport: `$CMS_DEV_DIR/test-inghetare-raport.txt`. |
| `migrari/pe-vechi.php` | O bază făcută ca înainte de migrări (pe o bază goală `cms_*`): `scripts/install.php` și `database/schema.sql` de la commit-ul din capul lui `migrari/vechi/cms.sql` (`fc8a5e1`), apoi fiecare funcție `ensure*`/`asigura*` a codului de acum, o trecere (garda le lasă: baza n-are registru). O folosesc `instaleaza.sh --pe-vechi`, T10 din `test-migrari.php`, `test-instalare.php` și `test-sonde.php` (baza „live” a sondelor). |
| `verifica-migrari.php` | Verificarea fișierelor de migrare și a DDL-ului din cod ([MIGRARI.md](MIGRARI.md), „Verificarea fișierelor de migrare”; în testul de fum și în G-mig): registrul, `migrari.lock` și `ultima.php` la zi, fără DDL scris de mână, `AFTER`/`FIRST`, `IF NOT EXISTS`, `COLLATE` în migrări, ștergeri doar în „contract”; fiecare DDL și fiecare definiție de coloană din codul de pe site-uri în lista `migrari/ddl-din-cod.txt` (lista doar scade: `--actualizeaza-lista`); pe o bază goală `cms_mig_v_*`, `asteptat.json` la zi și fiecare obiect făcut de o migrare al proprietarului ei în catalog (`config/plugins.php` → `tabele`). `--fara-baza`: fără server (spus în rezultat). |
| `mysql8.sh` | `porneste`, `opreste`, `stare` pentru un MySQL 8 despachetat în `CMS_DEV_MYSQL8_DIR` (doar socket, fără port TCP). |
| `test-verificare.php` | Testele site-ului de verificare ([VERIFICARE.md](VERIFICARE.md)): `IpClient`, credențialele Basic, cookie-ul porții, dosarul site-ului (fără server); apoi o copie a codului într-o găzduire simulată (`$CMS_DEV_DIR/verificare/acasa/domains/verificare.exemplu.test/public_html`), servită pe `CMS_DEV_PORT`+7 cu un mediu gol, ca pe Hostinger: fără `.env` 503 la orice; fiecare refuz al `configureaza.php` și configurarea pe o bază goală; poarta (401, 429, limita pe /64 la IPv6, formularul, câmpurile trimise ca liste, sonda, webhookurile); refuzul `configureaza.php` pe baza altui site din cont și perechea de chei a licenței; `stare.php` (ieșirea lui în raport: cele 14 gărzi, „GATA”, fără secrete; apoi fiecare gardă stricată pe rând trebuie să-l facă să spună „NU E GATA”); o copie „încărcată” (`cms_verif_copie`, cu licența `toate` dată de `licenta.php`) pe care se pun setările periculoase ale unui site live și se verifică gărzile (etichetele terților, bannerul (adresa ascunsă pe jumătate pe site și pe `/admin/login`, întreagă doar cu adminul logat), `/uploads/` lipsă → 302, feedul Merchant, Google, Stripe/BT/EuPlătesc/FAN/ERP, comanda cu avertizarea „refrigerat” ale cărei emailuri ajung, prin serverul SMTP de probă, doar la proprietar, plafonul de 100 pe zi); toate ieșirile spre afară trec printr-o „gaură neagră” care numără conexiunile (`lib/gaura-neagra.php`, porturile +12 proxy, +13 SMTP, +14 adresele „interzise”), deci se vede că nu pleacă nimic în afara celor permise; o copie expirată; `SITE_COPIE_VERIFICARE=0` (totul ca pe un site obișnuit); refuzurile `licenta.php` și `sterge.php` și ștergerea însăși (și a unei încărcări oprite la jumătate, cu `_verificare` și `anonimizat = 0`). Își face și își șterge bazele `cms_verif_gol`, `cms_verif_plina`, `cms_verif_copie` și utilizatorul `cms_verif_test`; baza de dezvoltare trebuie să fie deja făcută de `instaleaza.sh` (o copiază în `cms_verif_copie`; fără ea, sau fără `_platforma_stare` și `settings`, se oprește de la început cu acest motiv). Cu un PHP sub minim instalat (`php7.0` … `php8.2`) verifică și mesajul pentru un PHP prea vechi; cu `python3`, `configureaza.php` într-un terminal adevărat (`lib/terminal.py`: Ctrl-C și SIGTERM în mijlocul unei parole lasă terminalul cu ecou). Poarta G-verif îl rulează și cu PHP-ul minim. Raport: `$CMS_DEV_DIR/test-verificare-raport.txt`. |
| `test-incarcare.php` | Testele încărcării copiei de verificare ([VERIFICARE.md](VERIFICARE.md), „Încărcarea copiei”; tot poarta G-verif, și cu PHP-ul minim; `--fara-baza`: doar A). **A**, fără bază: regulile Bioscem (`tools/verificare/reguli/bioscem.php`) acoperă fiecare tabel și fiecare coloană a schemei Bioscem 4254ca4 (`migrari/vechi/bs.sql`) și a codului comun (`asteptat.json`), greșelile din reguli, scanerul, transformările, neutralizarea setărilor și A6, ce se copiază din codul vechi, garda instrucțiunilor sursei, PHP-ul prea vechi; **oracolul testului**, ținut în test, nu luat din reguli (tabelele clienților cu singurele coloane de text sau de dată care pot rămâne cum sunt, clasa fiecărui tabel): orice altă coloană a lor trebuie transformată de reguli, iar o regulă care lasă un text liber cum e pică testul, chiar scrisă consecvent (două chei: o coloană nouă păstrată cere un rând în reguli și unul în test). **B**, o găzduire simulată în `$CMS_DEV_DIR/incarcare/acasa` (site-ul de verificare, configurat cu `configureaza.php` și servit pe `CMS_DEV_PORT`+15, și un Bioscem „live” inventat, cu baza `cms_incarc_sursa` făcută de `migrari/pe-vechi.php`, seed și date demo, plus tabelele avertismentului din `bs.sql` și câte un rând cu marcaj în fiecare tabel al clienților, în fiecare coloană pe care oracolul o cere schimbată): refuzurile (și legătura sursei care nu merge: nimic atins), scanerul, încărcarea (A1–A9; niciun marcaj și nicio valoare personală a sursei pe copie, după oracol; față de `cms_incarc_ref`, aceeași sursă trecută prin aceleași migrări: tabelele copiate cum sunt la fel, coloanele păstrate la fel, iar oracolul găsește pe ea toate valorile pe care copia le-a schimbat), sesiunea sursei doar în citire, `--reincarca`, `--reia` cu `--buget=0`, câte o mutație pe fiecare verificare A1–A9 și pe fiecare text liber lăsat cum e (A1 numește coloana), `--demo` (comparată cu datele demo ale bazei de dezvoltare; vertij fără și cu valori private inventate), aceeași încărcare pe MySQL 8 (pornit și oprit dacă nu mergea). Își face și își șterge bazele `cms_incarc_*` și utilizatorii lor; cere baza de dezvoltare făcută de `instaleaza.sh`. Raport: `$CMS_DEV_DIR/test-incarcare-raport.txt`. |
| `compara-calcule-local.sh` | Poarta G-calc (WP-11; [VERIFICARE.md](VERIFICARE.md), „Comparația calculelor”, „Amprenta paginilor”, „Testele”), cu ambele PHP-uri: scoate codul Bioscem însuși, ca `compara-cu-baza.sh` (commit-ul revizuit din `docs/de-portat.md`, aceeași copie `$CMS_DEV_DIR/baza-Nou-site-bioscem-<commit>`; altul cu `--baza-repo=<clonă>@<commit>`), apoi rulează `test-calcule.php` și `test-amprenta-site.php` față de el. Cod 0 doar dacă trec amândouă. |
| `test-calcule.php` | Testele golden master-ului (`tools/verificare/compara-calcule.php`, G2). **A**, local, pe `cms_calc_t` (o copie a bazei de dezvoltare în care fiecare ramură contează: alt transport și alt prag în București decât în provincie, plafonul sub prețul provinciei, TVA adăugat la preț și altă cotă; cu 35 de comenzi inventate și 6 cupoane, fiecare tip de caz): „TRECE”, „diferite 0”, ieșirea doar cu rânduri de forme știute, cupoanele puse la loc (CHECKSUM TABLE), niciun fișier de sesiune; opțiunile (`--fara-cupoane-valide`, `--prin-fisiere`, `--php-vechi`, `--n`, prețurile fixe ale transportului, cu trei mutații ale lor); 17 mutații într-o copie a codului comun (`$CMS_DEV_DIR/calcule/cod-nou`: totalurile, TVA-ul adăugat și cota, Bucureștiul și provincia, plafonul, transportul din admin, punctele, ERP), fiecare „PICĂ” cu câmpul ei; refuzurile, lucrătorul pornit direct pe altă bază, o rulare omorâtă după cupoane și o eroare fatală de memorie; diferențele declarate (`--declarate`, lista `$diferenteDeclarate` a testului, un bloc pe WP, azi goală): o diferență voită declarată exact trece, orice declarație nepotrivită pică, un fișier greșit e refuzat. **B**, un site de verificare simulat (`$CMS_DEV_DIR/calcule/acasa`, baza `cms_calc_copie` cu `_verificare`, un utilizator cu parolă, codul vechi în `cod-vechi/bioscem/`), cu mediul gol: migrările întâi pe o bază în urma codului, apoi „TRECE”, textul salvat 0600 fără parolă, nume de bază sau cale a contului; fiecare refuz al serverului; un PHP sub minim. Își face și își șterge bazele `cms_calc_*` și utilizatorul. Raport: `$CMS_DEV_DIR/test-calcule-raport.txt`. |
| `test-amprenta-site.php` | Testele amprentei paginilor (`tools/verificare/amprenta-site.php`, G3). **A**, fără server: normalizarea, câmpurile, gazda sursă (`--gazda-sursa`), versiunea pozei de distribuire, adresele interzise, fișierul, comparația (câte o schimbare pe fiecare câmp; doar versiunea pozei alta; alte gazde sursă; o copie de verificare față de un site), refuzurile. **B**, pe `cms_amprenta_t` (cu o poză de produs de test și linkuri absolute spre serverul Bioscem în conținut, ca la bioscem.ro): codul Bioscem pe `CMS_DEV_PORT`+16 (poza cu altă dată), codul comun cu pachetul Bioscem pe +17, o copie de verificare a codului comun pe +18 (cu poarta), fiecare printr-un router care scrie cererile primite: aceeași amprentă de două ori, Bioscem față de codul comun „LA FEL” pe câmpurile structurale (cu `--strict` doar `/robots.txt`, diferența declarată; fără `--gazda-sursa` refuz), politețea văzută de server, licența fără blog „DIFERĂ”, poarta (și copia fără `--gazda-sursa` oprită). Își face și își șterge baza, serverele și fișierele de test (și din copia de bază). Raport: `$CMS_DEV_DIR/test-amprenta-raport.txt`. |
| `test-sonde.php` | Testele verificărilor pe site-urile live ([VERIFICARE.md](VERIFICARE.md), „Verificările pe site-urile live”; poarta G-sonde, și cu PHP-ul minim): garda instrucțiunilor (doar SELECT/SHOW cu LIMIT), tiparele ieșirii, tiparul coloanelor cu date personale, clasele CSS (din HTML, CSS și JavaScript), textele fixe ale erorilor PHP (`tools/verificare/lib/intrare.php`); apoi, într-o găzduire simulată (`$CMS_DEV_DIR/sonde/`, site-ul de verificare și patru site-uri „live” inventate pe `cms_sonde_t`, o bază făcută ca a unui site live încă neadoptat: de codul de dinainte de migrări, `migrari/pe-vechi.php`, cu seed-ul și datele demo, deci cu tabelul vechi `reviews` și fără `_platforma_*`; plus rânduri inventate pentru fiecare verificare): fiecare refuz al locului, fiecare număr al fiecărui set, sesiunea doar de citire (serverul refuză scrierile, 1792; un ALTER din altă conexiune nu așteaptă în mijlocul unui grup; un utilizator doar cu SELECT ajunge), sonda (amprenta ei = a bazei), `migrari/din-sonda.php` pe MariaDB și MySQL 8 (refuzurile și cu căi relative), aceleași rânduri pe MySQL 8, bugetul, textele lungi cu memoria PHP mică (citite pe bucăți, cele prea mari numărate, o eroare fatală de memorie: „OPRIT”, fără să se agațe); fiecare rulare are un termen de 300 s; plus: nicio dată personală, parolă sau nume de bază în ieșiri, baza neschimbată (CHECKSUM TABLE), niciun fișier schimbat în dosarele site-urilor live, în site-ul de verificare doar `storage/verificare/sonde/`. Își face și își șterge bazele `cms_sonde_t`, `cms_sonde_rt` și utilizatorii `cms_sonde_*`; pornește MySQL 8 dacă nu mergea. Raport: `$CMS_DEV_DIR/test-sonde-raport.txt`. |
| `migrari/genereaza-fixture.php` | Schema pe care o face codul unui site pe o bază goală: `scripts/install.php`, apoi fiecare funcție `ensure*Schema`/`asigura*Schema`, prin Reflection, în procese noi, până nu se mai schimbă; pe o copie a codului (arborele de lucru, sau `--repo=<clonă>@<commit>` cu `git archive`), cu pluginurile toate pornite, fără licență, pe o bază `cms_mig_fx_*`. `--cod=<cod>` scrie `<cod>.sql` (doar structură, se încarcă pe MariaDB și pe MySQL 8) și `<cod>-amprenta.json` în `$CMS_DEV_DIR/fixture-migrari/` (`--iesire=`; fixture-urile din git, WP-04: `tools/dev/migrari/vechi/`); `--compara` pune amprentele una lângă alta. `--fixture-uri` scrie fixture-urile din git (`tools/dev/migrari/vechi/`), `--verifica-fixture-uri` verifică că sunt la zi (T2). `--asteptat` scrie `database/migrari/asteptat.json`, `migrari.lock` (o migrare publicată schimbată e refuzată) și `ultima.php`. `--verifica` (poarta G-baza) e acceptanța migrărilor de bază ([MIGRARI.md](MIGRARI.md), „Migrările de bază”), pe ambele servere, inclusiv migrările de date: aceleași rânduri inventate trec prin funcțiile codului și prin migrări și trebuie să iasă la fel. Portat din uneltele analizei migrărilor. |
| `migrari/vechi/` | Fixture-urile testelor migrărilor: `<cod>.sql` (schema pe care o face codul cms, bs, bv, vt, gr, din „ultimul commit revizuit”, doar structură) și `extra-<cod>.txt`, scrise de `genereaza-fixture.php --fixture-uri`; `variante.sql` + `extra-variante.txt` (o bază veche, scrisă de mână, cu rânduri inventate); `privat/` (sonda live, ignorat de git). `migrari/excluderi.php`: ce nu primește o instalare nouă. |
| `migrari/din-sonda.php` | Din sonda unui site live (`tools/sonda/amprenta-schema.php`, fișierul trimis de proprietar): `tools/dev/migrari/vechi/privat/<cod>-live.sql` (doar structură), `extra-<cod>.txt` (ce are site-ul în plus față de `database/migrari/asteptat.json` sau `--fata-de=`) și `<cod>-fata-de-asteptat.txt`. `privat/` e ignorat de git (repo public, OD-49); refuză o intrare sau o ieșire din repo neignorată, și cu căi relative (făcute absolute față de dosarul curent înainte de verificare). |
| `porti.sh` | Porțile unui pachet de lucru din planul Etapei 1 (tabelul WP → porți e în script; `--lista` îl arată), în ordine, oprindu-se la prima care pică; o poartă a cărei unealtă vine într-un WP următor pică, nu se sare. Înainte de prima poartă, dacă una dintre ele citește codurile vechi, verifică clonele (`de-portat.php --verifica-clonele`): G-cmp, G-plug, G-calc doar clona Bioscem; G-smoke, G-php, G-mig, G-vechi toate patru (înghețarea setărilor din testul de fum se reproduce din ele). O clonă fără „ultimul commit revizuit” al ei oprește totul de la început, cu numele clonei și al commit-ului (de exemplu după portarea unui commit vertij încă neadus în clona locală: `git -C ../vertij-tinitus-refacut fetch`, sau `CMS_DEV_REPO_VT` spre o clonă care îl are). Raportul: `$CMS_DEV_DIR/porti-<WP>.txt`; rezumatul lui intră în mesajul ultimului commit al WP-ului. |
| `de-portat.php` | Commit-urile noi de pe ramurile celor patru coduri vechi (`CMS_DEV_REPO_BS`, `_BV`, `_VT`, `_GR`), de după „ultimul commit revizuit” din [de-portat.md](de-portat.md). Doar citește (fără `git fetch`); iese cu 2 dacă sunt commit-uri noi. `--revizuit=<site>` scrie doar commit-ul revizuit (îl folosește `compara-cu-baza.sh`). `--verifica-clonele [<site> …]`: doar dacă fiecare clonă are commit-ul ei revizuit (1 și clona care nu-l are, altfel 0); o cheamă `porti.sh` și `smoke.sh` (prin `verifica_clonele` din `comun.sh`) înainte de orice. |
| `test-reparatii.php` (+ `lib/erp-fals.php`) | Testele reparațiilor WP-12 (din Biovitality și pragul ERP), cu serverul de dezvoltare pe baza cu datele demo (îl pornește dacă nu merge) și cu un ERP fals pe `CMS_DEV_PORT`+19 (`lib/erp-fals.php`, un router pentru `php -S`: stocul, primirea comenzilor, ping-ul; modul `normal`, `500` sau `incet`, fiecare cerere notată): A rezervările unei oferte BBD numărate și după schimbarea prețului ei (C1), salvarea produsului fără câmpul ofertelor (C2); B postarea demo cu categoria doar ca text (nota, re-salvarea, potrivirea după nume, după nume scris altfel și după slug; X1b); C CodeMirror doar în cele patru editoare (C6), pozele selectoarelor leneșe (C7); D JSON-ul gărzii pe `/api/…` cu `error`, cu licența „prezentare” (X6); E stocul din ERP (C3): un SKU necunoscut întrebat o dată la 60 de secunde, un ERP cu eroare 500 lăsat în pace un minut, unul care tace ținut cel mult ~3 secunde; F lacătul sesiunii (C4): heartbeat-ul, sumarul coșului, catalogul și cele mai vândute, lente din cauza ERP-ului, nu țin în loc `/contact` cerută în același timp din același browser (martorul negativ: pagina unui produs o ține); G pragul ERP (E1): cu o dată cronul trimite doar comanda de după prag și spune de ce, contoarele și Admin → Comenzi (eticheta, confirmarea 🔄) spun la fel, 🔄 trimite de mână; cu `''` ca înainte; cu cheia lipsă sau greșită nimic automat. Fiecare reparație scoasă din cod (mutație) face testul să pice. Pune la loc toată baza (CHECKSUM TABLE pe fiecare tabel), licența „toate”, cache-ul stocului și sesiunile; nicio eroare PHP nouă. Rulează și din `smoke.sh` (pachetul „toate”). Raport: `$CMS_DEV_DIR/test-reparatii-raport.txt`. |
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
| `CMS_DEV_PORT` | `8701` | portul serverului; uneltele folosesc și porturile următoare: +1 copia de bază, +2/+3 `test-seo.php`, +4 codul curent în `compara-cu-baza.sh` față de un repo vechi, +7 copia din `test-verificare.php`, +8 `test-instalare.php`, +9/+10 cele două site-uri din `test-paths.php` (+11 „baza care tace” a lui), +12/+13/+14 „gaura neagră” din `test-verificare.php` (proxy, SMTP, adresele interzise), +15 copia din `test-incarcare.php`, +16/+17/+18 codul Bioscem, codul comun și copia de verificare din `test-amprenta-site.php`, +19 ERP-ul fals din `test-reparatii.php`. Poarta G-vechi rulează totul pe `8711` (și următoarele), cu baza `cms_dev_vechi` |
| `CMS_DEV_PHP` | `php` | comanda PHP a tuturor uneltelor (de exemplu `php8.3`, PHP-ul minim) |
| `CMS_DEV_REPO_BS`, `CMS_DEV_REPO_BV`, `CMS_DEV_REPO_VT`, `CMS_DEV_REPO_GR` | `../Nou-site-bioscem`, `../Mutare-Site-Biovitality`, `../vertij-tinitus-refacut`, `../site-nou-grafoanaytis.ro` | clonele codurilor vechi (doar citite), pentru `compara-cu-baza.sh`, `de-portat.php`, înghețarea setărilor (`verifica-inghetare.php`, și în testul de fum) și fixture-urile migrărilor; fiecare trebuie să aibă „ultimul commit revizuit” din [de-portat.md](de-portat.md) (`porti.sh` și `smoke.sh` o verifică de la început) |
| `CMS_DEV_DIR` | `storage/logs/dev` | jurnale, emailuri capturate, parola, raportul testului |
| `CMS_DEV_NUMARA_SQL` | gol | `1` în mediul unui server de dezvoltare: fiecare cerere scrie în `$CMS_DEV_DIR/numara-sql.jsonl` câte instrucțiuni SQL a trimis și câte de schemă (`App\Support\ContorSql`; testul de fum și `test-instalare.php` o pun singure) |
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
- **Doar baze `cms_*`.** `instaleaza.sh` și `date-demo.php` refuză orice alt nume (`date-demo.php` are o singură excepție, copia demonstrativă de pe site-ul de verificare, mai sus).
- **PHP** pornește cu `display_errors=0`, `error_reporting=E_ALL`, toate
  mesajele în `storage/logs/dev/php-erori.log`, `allow_url_fopen=0` și
  `sendmail_path` = `mail-capturat.sh`.
- **Sesiunile** stau, ca pe server, în `storage/sessions/` (ignorat de git; `Paths::stocareSite('sessions')`,
  mai jos). Doar ale paginilor: linia de comandă (cronurile, `tools/dev/php.sh …`) și pingul
  `/_andaxi/ping` nu pornesc sesiuni. Jurnalul PHP rămâne `storage/logs/dev/php-erori.log`: `comun.sh` îl
  alege, deci `Paths::alegeJurnalulPhp()` nu-l schimbă (pe un server, linia de comandă scrie erorile pe stderr,
  mai jos, „Unde scrie codul”).
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
- blog: categoriile instalării („Noutăți”, „Evenimente”) și „Ghiduri demo”, 2
  autori, 9 articole (o ciornă, un eveniment și unul „importat”, „Sfaturi vechi, importate”, cu
  categoria doar ca text, „Sfaturi din arhivă”, fără categorie legată: editorul postării o arată ca
  notă și o păstrează la salvare, WP-12 X1b); newsletter: 2 liste, 30
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

1. instalarea (`install.php`, adică migrările, + `seed.php` + date demo; cu `--pe-vechi`,
   baza ca înainte și baseline-ul), `install.php` fără server (cod ≠ 0) și din nou pe baza
   instalată (1, nimic scris), apoi, fără server, instantaneul rutelor, mecanismul
   diferențelor declarate ale comparației cu baza, verificarea fișierelor de migrare
   (`verifica-migrari.php`: și DDL-ul nou din cod), căile site-ului (`test-paths.php --doar-cai`: o
   cale `storage/`, `uploads/`, `.env` … construită în afara `Paths` pică) și înghețările
   (`verifica-inghetare.php`: o setare nouă în codul comun fără înghețarea ei pe site-urile vechi pică);
2. site-ul public: acasă, magazin, categorie, marcă, produs cu și fără șablon,
   produs cu oferte BBD, produs la precomandă, căutare, adăugare în coș, coș,
   checkout, login, înregistrare, cont fără login (redirect), blog, articol,
   contact, pagină legală, `robots.txt`, 404, `/health` (cu `schema: la_zi`), `/_andaxi/ping` și `/_andaxi/ping/`
   (JSON, `no-store`, fără cookie și fără fișier de sesiune); apoi SEO: `/sitemap.xml`
   (cu produse doar cu catalogul), robots.txt (regulile coșului doar cu magazinul, `Disallow: /_andaxi/`),
   datele structurate (ofertă doar cu magazinul pornit), „/” final → 301, o adresă
   veche din datele site-ului → 301, un POST cu „/” final neatins;
3. o comandă reală, ramburs, plasată ca un client (cu protecția anti-robot):
   redirect la pagina de mulțumire, comanda în bază, emailul capturat; apoi (cu ofertele BBD
   și magazinul pornite) doi cumpărători pe ultimele bucăți ale primei oferte a unui produs
   demo: primul pune în coș toate bucățile rămase, al doilea 2; al doilea plasează comanda
   (în bază, cu cheia ofertei), a primului e refuzată (înapoi în coș, cu „oferta mai are N
   bucăți”, nicio comandă nouă; WP-12 C1b);
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
   local), apoi `scripts/migreaza.php --stare` și `--plan` pe baza de test (făcută prin
   migrări: `--plan` iese cu 0, nimic de făcut); niciunul nu lasă un fișier de sesiune nou;
   pe pachetul „toate”, `test-reparatii.php` (reparațiile WP-12, mai sus, pe serverul pornit
   aici); apoi instrucțiunile SQL ale tuturor cererilor de mai sus (contorul serverului,
   `CMS_DEV_NUMARA_SQL=1`): niciuna nu are voie să trimită DDL;
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

## Unde scrie codul (`App\Support\Paths`)

Din WP-07 (punctul A al analizei infrastructurii), codul nu mai construiește din `__DIR__` căile unui site:
le cere de la `App\Support\Paths`. Așa, pentru așezarea din Etapa 2 (o versiune de cod comună mai multor
site-uri, analiza infra §4.2) nu mai trebuie schimbat nimic în cod, iar un port nou scrie de la început unde
trebuie. În așezarea de azi (și în dezvoltare) fiecare metodă dă exact calea de dinainte, deci Bioscem nu
observă nimic (`compara-cu-baza.sh`).

| Ce | Metoda | Azi (un site = un dosar de cod) | Etapa 2 (stub-ul din `deploy/stub/`) |
|---|---|---|---|
| codul (`config/`, `views/`, `database/migrari/`, `site-uri/`, `scripts/`) | `Paths::radacina()` | dosarul codului | `~/platforma/releases/<versiune>/` (sau `site/releases/<versiune>/`) |
| `.env` | `Paths::env()` | `<cod>/.env` | `~/domains/<d>/site/.env` |
| `storage/` (cache, jurnale, licența, importuri, copii, lacăte) | `Paths::stocare('cache/pages')` | `<cod>/storage/…` | `site/storage/…` |
| un dosar privat care trebuie să existe (sesiuni, jurnale, verificare) | `Paths::stocareSite('sessions')` | la fel, făcut la nevoie (0770) cu gardul `.htaccess` (`Paths::GARD`) | la fel, în `site/storage/` |
| docroot-ul (`assets/`, `.htaccess`-ul de cache) | `Paths::public('assets/css/app.css')` | `<cod>/public/…` | `~/domains/<d>/public_html/…` |
| pozele și fișierele încărcate | `Paths::uploads('gallery')` | `<cod>/public/uploads/…` | `public_html/uploads/…` |
| un sitemap scris de admin | `Paths::sitemap($nume)` | `<cod>/public/<nume>` | `public_html/<nume>` |

Reguli pentru codul nou: un fișier scris de aplicație (cache, jurnal, copie, lacăt, export) se cere de la
`Paths::stocare()`, un dosar care trebuie să existe și să rămână închis de la `Paths::stocareSite()`, ce se
servește vizitatorilor de la `Paths::public()`/`Paths::uploads()`. Fișierele codului rămân față de `__DIR__`
sau `Paths::radacina()`: vin cu versiunea. Căutarea din `tools/dev/test-paths.php` (A3, rulată și de testul de
fum) pică pe orice cale `storage/`, `uploads/`, `sessions/`, `.env` sau `public/` construită altfel (din
`__DIR__`/`dirname()` sau lipită la o altă cale) în `bootstrap.php`, `src/`, `views/`, `public/`, `scripts/`,
`config/`. Ce rulează înaintea autoloader-ului (poarta, fișierele încărcate, poza de distribuire, stratul SEO,
`scripts/diagnostic.php`) încarcă singur `src/Support/Paths.php`.

**Așezarea din Etapa 2.** `~/domains/<d>/public_html/` are stub-ul `deploy/stub/index.php`, același pe fiecare
site, lângă `deploy/stub/.htaccess` (ca `public/.htaccess`, plus: niciun fișier cu punct în față, nicio listă de
dosar) și `deploy/stub/mentenanta.html`; le copiază acolo scripturile de deploy (Etapa 2). Stub-ul:

1. citește `../site/RELEASE` ca text (versiunea, de forma `2026.11.2-e4f5a6b`); lipsă sau altă formă → 503
   static (`Retry-After: 300`);
2. dacă există `../site/MENTENANTA` (mentenanța deploy-ului, pentru o migrare care oprește scrierile) → 503,
   `Retry-After: 120`, `mentenanta.html`, în afară de `/webhook/`, `/api/erp/` și `/_andaxi/` (confirmările de
   plată, ERP-ul și pingul merg mai departe). E alta decât „Revenim în curând” din admin (`Maintenance`) și
   merge și cu baza sau codul nou stricate;
3. ia versiunea din `../site/releases/<versiune>/` dacă există (o găzduire care nu lasă un site să citească în
   afara dosarului lui), altfel din `~/platforma/releases/<versiune>/`; lipsă → 503 static;
4. definește `PLATFORMA_SITE_DIR` (`../site`) și `PLATFORMA_PUBLIC_DIR` (`public_html`), apoi încarcă
   `public/index.php` al versiunii, fără să lase vreo variabilă în urmă.

Din linia de comandă, `bin/platforma --site=<dosar>` (Etapa 2) definește `PLATFORMA_SITE_DIR` înaintea
scriptului; fără `PLATFORMA_PUBLIC_DIR`, docroot-ul e `public_html/` de lângă dosarul site-ului.

**Sesiunile.** `bootstrap.php` pornește sesiunea după autoloader, în `Paths::stocareSite('sessions')`: azi tot
`storage/sessions` (Bioscem își păstrează sesiunile, deci coșurile și logările), în Etapa 2
`site/storage/sessions`, niciodată în versiunea comună: două site-uri pe aceeași versiune nu-și văd și nu-și
curăță sesiunile unul altuia, iar o versiune nouă nu deloghează pe nimeni. Fără sesiune: linia de comandă,
`/feed/google-merchant.xml` și `/_andaxi/ping` (și `/_andaxi/ping/`).

**Jurnalul erorilor PHP.** `Paths::alegeJurnalulPhp()`, primul lucru din `public/index.php` (deci și pentru
erorile de dinaintea bootstrap-ului) și în `bootstrap.php` (linia de comandă din Etapa 2): `storage/logs/php.log`
al site-ului.

- **Paginile:** în Etapa 2 mereu (verificarea de după un deploy caută acolo erorile „PHP Fatal” noi); în așezarea
  de azi doar când serverul nu a ales deja un jurnal (`error_log` gol), deci un jurnal pus de găzduire sau de
  uneltele de dezvoltare rămâne cel de până acum.
- **Linia de comandă** (cronurile, `scripts/migreaza.php`, `install.php`): în așezarea de azi neatinsă. Cu
  php.ini-ul obișnuit (`display_errors` oprit, fără `error_log`) PHP scrie erorile pe stderr, deci un avertisment
  sau o eroare fatală apare în terminal și în ieșirea cronului, ca până acum; un fișier ales de noi le-ar fi ascuns.
  În Etapa 2 (`bin/platforma --site=`) erorile merg și în `site/storage/logs/php.log`, și tot pe stderr
  (`display_errors=stderr` când PHP nu le afișează). `tools/dev/test-paths.php` (A4) le verifică pe amândouă cu
  setările php.ini-ului obișnuit, fiindcă uneltele de dezvoltare dau mereu un `error_log`.

`scripts/diagnostic.php` arată jurnalul procesului (în așezarea de azi: „pe ecran (stderr)” și fișierul paginilor),
`.env`-ul și `storage/`.

**Pingul** (`GET /_andaxi/ping`, `App\Support\Ping`), pentru monitorul CRM-ului (la 5 minute) și verificarea de
după un deploy: `200 {"ok":true,"db":true,"cod":"<versiune>","schema":"<stare>"}` (`cod` din fișierul `VERSION`
al versiunii urcate, `null` fără el, de exemplu aici; `schema` e starea gărzii migrărilor, [MIGRARI.md](MIGRARI.md),
„Garda”, și cu `in_urma` tot 200), `503 {"ok":false,"db":false}` fără bază. `SELECT 1` pe o conexiune a lui, cu
limita de 2 secunde la conectare și la fiecare citire (`PDO::ATTR_TIMEOUT` și `mysqlnd.net_read_timeout`: un
server care acceptă conexiunea și tace ar ține altfel cererea minute întregi). `Cache-Control: no-store`, fără
sesiune și fără cookie; `public/index.php` îl servește imediat după bootstrap, înaintea setărilor, a
pluginurilor, a pașilor de activare și a cache-ului de pagini. La fel `/_andaxi/ping/`, cu „/” la sfârșit
(`Ping::eCererea`): routerul scoate „/”-ul final și redirecționările SEO nu ating `/_andaxi`, deci altfel adresa
ar fi trecut prin toată aplicația, cu o sesiune nouă la fiecare cerere și fără limita de 2 secunde. Ruta e a
nucleului (`src/Http/rute.php`, catalogul: `nucleu.rute_publice` → `/_andaxi/*`); adresele `/_andaxi` sunt scoase
din redirecționările SEO (`SeoRedirects::CAI_EXCLUSE`), din mentenanța din admin (`Maintenance::CAI_PERMISE`) și
din cea a deploy-ului (stub-ul), închise în robots.txt (`Disallow: /_andaxi/`), iar pe site-ul de verificare
`GET`/`HEAD` pe ping (doar adresa exactă) trec fără parolă ([VERIFICARE.md](VERIFICARE.md)). Pentru Bioscem,
rândul din robots.txt și adresa nouă (ambele forme, 404 în Bioscem) sunt diferențe declarate (`$deBioscem`,
`$coduriDeBioscem` în `compara-cu-baza.php`; `site-uri/bioscem/ce-se-schimba.md`).

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
4. **`install.php` și `seed.php` ieșeau cu codul 0 când baza nu era disponibilă**
   (`exit("mesaj")`), deci un script automat ar fi crezut că instalarea a reușit.
   **Reparat** (WP-05, portul GR-02 din grafoanaytis `9e21659`, extins și la fișierul
   care nu se poate citi): mesajul pe stderr și codul 1; `install.php` iese cu 1 și pe o bază
   care are deja administratori, fără să scrie nimic ([MIGRARI.md](MIGRARI.md), „Instalarea”).
   Testul de fum și `test-instalare.php` le verifică.
5. **Fiecare rulare a unui script CLI (inclusiv cronurile) deschidea o sesiune
   PHP** (`bootstrap.php`) și lăsa un fișier gol în `storage/sessions/`. Pe
   Bioscem, 878 din cele 1.711 fișiere de sesiune din clona locală sunt goale.
   Se șterg singure după 30 de zile. **Reparat** (WP-02, odată cu runner-ul
   migrărilor): în linia de comandă `$_SESSION` e un array gol, fără fișier
   (`ANDAXI_CU_SESIUNE` pentru un script care chiar vrea o sesiune); paginile, neschimbate.
   Trecut în `site-uri/bioscem/ce-se-schimba.md` și în capul lui `compara-cu-baza.sh`.
6. **Adminul încărca CodeMirror de pe `cdn.jsdelivr.net` pe fiecare pagină**
   (15 fișiere fără `defer`, deci fiecare pagină de admin aștepta CDN-ul). **Reparat**
   (WP-12, C6 din Biovitality `c64fc2d`): doar cele patru editoare de cod (`admin/design-site`,
   `admin/page-editor`, `admin/product-template-editor`, `admin/blog-template-editor`, după
   `View::vedereaCurenta()`) îl cer; fără CDN, doar ele nu merg. Pozele selectoarelor de imagini
   se încarcă leneș (C7, `4f65fd9`). Declarat în `compara-cu-baza.php` (`$porturiBiovitality`) și
   în `site-uri/*/ce-se-schimba.md`.
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
  finalizate). De la WP-05, pasul de activare al pluginului `erp` scrie pragul
  `erp_trimite_din` (doar dacă cheia lipsește, doar pe o bază la zi cu migrările). De la WP-12 (E1) pragul e
  respectat (`ErpSync::pragTrimitere`, `filtruPrag`): `retryPending` (cronul și „Retrimite”) trimite doar
  comenzile plasate de la prag, contoarele din Setări → ERP ANDAXI le numără la fel (cele de dinainte separat, cu
  o notă), Admin → Comenzi le arată cu „ERP: înainte de legare”, iar 🔄 le trimite de mână, cu confirmare; cu
  cheia goală (`''`, înghețarea Bioscem) totul e ca înainte; cu cheia lipsă sau care nu e o dată, nimic nu
  pleacă automat (pasul de activare încă n-a rulat). Contorul `erp` al `LucruriInCurs` (WP-27, Etapa 2)
  folosește același `filtruPrag`. Setarea nu e în `Settings::DEFAULTS`: lipsa ei are un înțeles. Pe baza
  de dezvoltare pragul e momentul instalării (pasul de activare), deci comenzile demo sunt toate de dinainte;
  `compara-cu-baza.php` rulează cu setările înghețate ale Bioscem (pragul gol).
- `/admin/users/points/history` fără parametri răspunde 400 (nu e în meniu; se
  deschide cu un client ales).
- O cerere `HEAD` pe o adresă corectă răspunde 404: routerul are doar rute GET și
  POST (moștenit din Bioscem, neschimbat). Stratul SEO tratează HEAD ca GET doar
  când are ceva de reparat (de exemplu „/” final → 301).
