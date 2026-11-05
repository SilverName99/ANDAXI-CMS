# Deploy-ul din Etapa 2 (Hostinger)

Cum trece un site live de pe codul lui vechi pe codul comun, cum primește apoi versiuni noi și cum se întoarce, în
câteva minute, dacă ceva nu merge. Primul site e Biovitality (mai jos, „Noaptea comutării Biovitality”).

Totul se face din SSH, cu patru scripturi din `deploy/` și unul din `bin/`. Fiecare arată întâi **planul** (ce ar face,
fără nicio schimbare) și face ceva doar cu `--aplica`.

| Script | Ce face | Atinge un site? |
|---|---|---|
| `deploy/release.sh` | o versiune de cod (`~/platforma/releases/<versiune>/`) dintr-un commit, verificată | nu |
| `deploy/adopta.sh` | prima trecere a unui site pe codul comun: copii, mentenanță, comutare, migrări, verificări, deschidere | da |
| `deploy/actualizeaza.sh` | un site adoptat trece pe o versiune nouă; se întoarce singur dacă versiunea nouă pică verificările | da |
| `deploy/revino.sh` | site-ul înapoi pe codul vechi: codul exact cum era, baza din copia de dinaintea migrărilor | da |
| `bin/platforma` | rulează un script al codului (migrările, diagnosticul, un cron) pentru un site | doar cât face scriptul |

## Așezarea

```
~/platforma/
  repo/                       clona repo-ului (o faci o dată, mai jos): de aici rulezi scripturile și se iau pachetele
  releases/2026.11.05-e4f5a6b/  o versiune de cod, doar de citit (VERSION, COMMIT, SHA256SUMS)
  bin/platforma               lansatorul cronurilor (o copie a lui bin/platforma: citește RELEASE-ul site-ului)
~/domains/biovitality.ro/
  public_html/                ce servește site-ul: stub-ul (index.php, .htaccess, mentenanta.html), assets/, tema/,
                              uploads/ (pozele), sitemap-urile scrise de admin
  public_html.vechi/          codul vechi, neatins, după comutare (de aici îl pune la loc revino.sh)
  site/                       al site-ului, în afara docroot-ului:
    .env                      cel vechi, plus SITE_SLUG, SITE_DATE_DOSAR, LICENTA_SITE, LICENTA_CHEIE_PUBLICA (0600)
    RELEASE                   versiunea pe care merge site-ul (o schimbă doar actualizeaza.sh)
    MENTENANTA                doar în timpul unui deploy: vizitatorii văd „Revenim în câteva minute”
    pachet/                   pachetul site-ului (site-uri/<site>/ din repo, la commit-ul versiunii) + privat/
    privat/                   valorile private ale înghețării (le pui tu, o dată; deploy-ul nu le șterge niciodată)
    storage/                  sesiunile, jurnalele (logs/php.log, logs/fatal.log), cache-ul, licența (licenta/)
    deploy/                   starea deploy-ului, jurnalele lui, copiile (copii/<data>/), cron-nou.txt
```

Stub-ul (`deploy/stub/index.php`) e același pe fiecare site: citește `site/RELEASE` și încarcă versiunea aceea (din
`site/releases/<v>/` dacă există, altfel din `~/platforma/releases/<v>/`). Cu `site/MENTENANTA` răspunde 503 cu pagina
„Revenim în câteva minute”, afară de plăți (`/webhook/`), ERP (`/api/erp/`) și ping (`/_andaxi/`). Dacă fișierul
conține jetonul deploy-ului, o cerere cu același jeton trece: așa se verifică site-ul nou înainte să-l vadă oricine, iar
tu îl vezi în browser cu adresa `https://<domeniu>/?andaxi_deploy=<jeton>` (scriptul ți-o scrie când e cazul).

## O dată, pe server (aproximativ 10 minute)

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
mkdir -p ~/platforma
git clone https://github.com/SilverName99/ANDAXI-CMS.git ~/platforma/repo
# PHP-ul liniei de comandă (Hostinger nu încarcă singur sodium); îl scrii în fiecare sesiune SSH:
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
```

Scripturile își aleg singure PHP-ul (variabila `PHP`, altfel `/opt/alt/php83/usr/bin/php`, cu sodium adăugat dacă
lipsește). Ele rulează din `~/platforma/repo/deploy/`.

## O versiune de cod

```sh
cd ~/platforma/repo
git fetch origin && git checkout --detach origin/claude/etapa1-cod-comun
deploy/release.sh --ref=origin/claude/etapa1-cod-comun            # planul
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica   # o face; ultimul rând spune versiunea
deploy/release.sh --lista                                         # versiunile și cine le folosește
```

Versiunea are forma `<an>.<lună>.<zi>-<7 caractere>` (data și începutul commit-ului), deci același commit dă mereu
aceeași versiune. `release.sh` scoate commit-ul cu `git archive` (fără `site-uri/` și `tools/`), scrie `VERSION` și
`COMMIT`, trece fiecare fișier PHP prin `php -l`, scrie `SHA256SUMS` și face totul doar de citit. Un commit cu o eroare
de sintaxă e refuzat. Nu schimbă niciun site. `--verifica=<v>` spune dacă o versiune urcată a rămas neatinsă;
`--sterge=<v> --aplica` șterge una pe care n-o mai folosește niciun site.

## Adoptarea unui site

Înainte (o dată pe site, cu zile înainte):

1. hPanel → site-ul → **PHP Configuration**: PHP 8.3, în **PHP extensions** bifat **sodium** (codul vechi nu-l cerea).
2. hPanel → site-ul → **GIT**: dacă are „Auto deployment”, oprește-l (după comutare `public_html` nu mai e un repo).
3. Valorile private ale înghețării, primite de la noi (nu sunt în git):
   `~/domains/<domeniu>/site/privat/setari-inghetate.json` (`mkdir -p ~/domains/<domeniu>/site/privat` întâi).
4. Licența din CRM: `~/domains/<domeniu>/site/storage/licenta/licenta.json`, și cheia publică a CRM-ului la îndemână
   (`--cheie-licenta=`).
5. Rândurile de cron ale site-ului, copiate din hPanel → Advanced → **Cron Jobs** într-un fișier, de exemplu
   `~/cron-<site>.txt`, câte unul pe rând, cu programul în față (`*/10 * * * * /usr/bin/php …/public_html/scripts/erp-sync.php`).
   Dacă `crontab -l` le arată, nu trebuie.
6. Dacă site-ul are un CDN în față: `IP_PROXY_DE_INCREDERE=<rețelele lui>` în `.env`-ul vechi (codul vechi nu-l citește;
   adoptarea îl copiază) — [MIGRARI.md](MIGRARI.md), „Pe server, la adoptare”, pasul 5.

Apoi:

```sh
deploy/adopta.sh --site=<site> --domeniu=<domeniu> --versiune=<v> --commit-vechi=<commit> \
    --cheie-licenta=<cheia CRM-ului> --cron-vechi=$HOME/cron-<site>.txt            # planul: nu schimbă nimic
deploy/adopta.sh … aceleași … --aplica                                            # adoptarea
```

`--commit-vechi` e commit-ul codului vechi pe care l-am revizuit (îl compară cu `git log -1` din `public_html`).
Planul rulează toate verificările și planul migrărilor pe baza live (doar citire); singurul lucru scris e, pentru o
clipă, o „sondă” PHP în docroot, care spune versiunea PHP-ului web, dacă are sodium și dacă site-ul poate citi în afara
dosarului lui.

Fazele lui `--aplica` (fiecare ține minte că s-a terminat, în `site/deploy/adopta.stare`):

| Faza | Ce face | Vizitatorii |
|---|---|---|
| `verificari` | sodium (web și linia de comandă), PHP-ul, uneltele, spațiul, baza (și drepturile), pachetul, `privat/`, licența, codul vechi curat și la commit-ul revizuit, site-ul răspunde | nu văd nimic |
| `pregatire` | `site/` (`.env`, `RELEASE`, pachetul, `storage/`), `public_html.nou/` (stub-ul, `assets/`, `tema/`); `diagnostic.php --pachet` și `--licenta`, `migreaza.php --plan` | nu văd nimic |
| `copie` | copia codului vechi (`cod-vechi.tar.gz`, fără poze) și o copie a bazei | nu văd nimic |
| `mentenanta` | `MENTENANTA`; `public_html` → `public_html.vechi`, `public_html.nou` → `public_html`; pozele mutate în `public_html/uploads/`; fișierele site-ului (sitemap-uri, `assets/.htaccess`, `.well-known/`…) aduse; **copia bazei de revenire** | „Revenim în câteva minute” |
| `migrari` | `migreaza.php --plan` (arătat), apoi `--aplica --site=<site>`, reluat cât cere bugetul de timp | „Revenim…” |
| `diagnostic` | `diagnostic.php --pachet` și `--licenta` | „Revenim…” |
| `verificare` | pingul (versiunea, schema `la_zi`), `/health` (și pachetul), prima pagină, un produs, `/admin/login`, `/cos`, un fișier din `assets/`, o poză, jurnalele PHP fără erori fatale noi | „Revenim…” |
| `deschidere` | `MENTENANTA` ștearsă; prima pagină verificată fără jeton | site-ul nou |

La sfârșit scrie rândurile de cron (mai jos) și unde a pus copiile.

**La prima problemă se oprește**, cu motivul și ce poți face:

- înainte de comutare (`verificari`, `pregatire`, `copie`): site-ul a rămas pe codul vechi, neatins. Repari și rulezi din
  nou aceeași comandă: continuă de la faza care a picat.
- după comutare: site-ul rămâne în mentenanță. **Drumul 1**: repari și rulezi din nou aceeași comandă (continuă de la
  faza care a picat; migrările se pot relua oricând). **Drumul 2**: `deploy/revino.sh --site=<site> --aplica`.
  `--reia=<faza>` rulează din nou o fază terminată (de la `migrari` încolo).

## Revenirea

```sh
deploy/revino.sh --site=<site>            # planul: ce ar face și ce date noi s-ar pierde
deploy/revino.sh --site=<site> --aplica   # 1–3 minute (cât încărcarea copiei bazei)
```

1. `MENTENANTA` (nimic nou nu se scrie cât se pune baza la loc);
2. dacă migrările au pornit: baza din **copia de revenire** (făcută în mentenanță, chiar înainte de migrări): tabelele
   făcute de migrări se șterg, copia se încarcă, apoi fiecare tabel (structura și `CHECKSUM TABLE`) și fiecare
   declanșator se compară cu cele de la copie: „exact ca la copie”;
3. pozele înapoi în codul vechi; `public_html` → `public_html.esuat-<data>`, `public_html.vechi` → `public_html`;
4. codul vechi comparat cu amprenta lui de la comutare (fiecare fișier, octet cu octet; la poze mărimea și data);
5. prima pagină a site-ului vechi verificată.

**Datele scrise pe codul nou** (comenzi, conturi, abonați) s-ar pierde odată cu baza. Planul le numără, iar `--aplica`
refuză dacă există. Atunci ai două feluri:

- `--fara-baza`: doar codul se întoarce, baza rămâne cum e (migrările doar adaugă coloane și tabele, deci codul vechi
  merge pe ea). Așa nu se pierde nicio comandă. E alegerea obișnuită după ce site-ul nou a mers câteva ore.
- `--pierde-datele-noi`: baza exact cea de dinainte; comenzile noi le notezi întâi din admin.

După revenire pui înapoi în hPanel cronurile vechi (dacă le-ai schimbat). `site/` rămâne; o adoptare nouă pornește de
la început.

## O versiune nouă

```sh
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica         # versiunea nouă, <v>
deploy/actualizeaza.sh --site=<site> --versiune=<v>                     # planul (licența și migrările cu codul nou)
deploy/actualizeaza.sh --site=<site> --versiune=<v> --aplica
```

Fazele: verificări, pregătire (pachetul, `assets/` și `tema/` noi alături), copia bazei, mentenanța, comutarea
(pachetul, `assets/`, `tema/`, stub-ul, `RELEASE`, cache-ul paginilor golit), migrările cu codul nou, diagnosticul,
testul de fum, deschiderea. **Dacă ceva pică după comutare, se întoarce singur**: `RELEASE`, pachetul, `assets/`,
`tema/` și stub-ul de dinainte, verificarea versiunii vechi și, dacă trece, site-ul deschis (codul de ieșire rămâne 1).
Baza rămâne cum au lăsat-o migrările (doar adăugări); copia ei e în `site/deploy/copii/`. Celelalte site-uri de pe
versiunea veche nu se schimbă. Înapoi pe versiunea de dinainte, de mână: aceeași comandă cu `--versiune=<veche>`
(și `--inapoi`, dacă baza are deja migrări pe care versiunea veche nu le știe).

## Cronurile

Rândurile noi pornesc prin lansator și citesc `RELEASE`, deci după o actualizare rulează singure codul nou:

```
*/10 * * * *  /opt/alt/php83/usr/bin/php -d extension=sodium.so /home/uXXXXXXXXX/platforma/bin/platforma --site=/home/uXXXXXXXXX/domains/biovitality.ro/site scripts/erp-sync.php
```

`adopta.sh` le scrie din cele vechi (același program, același script, aceleași argumente) în
`site/deploy/cron-nou.txt` și pe ecran: **ȘTERGI** rândul vechi, **ADAUGI** pe cel nou; un script care nu mai există
în codul comun (de exemplu o unealtă de depanare) doar se șterge; un rând care nu rulează codul vechi (un `curl` spre
`/health`) **RĂMÂNE**. Le schimbi în hPanel → Advanced → Cron Jobs, imediat după `GATA`. Până atunci cronurile vechi
pică inofensiv (fișierul lor nu mai e în `public_html`).

Orice script, de mână: `$PHP ~/platforma/bin/platforma --site=<domeniu> scripts/diagnostic.php --licenta`.

## Noaptea comutării Biovitality

### Înainte (zilele dinainte)

- [ ] Repetiția pe site-ul de verificare, cu o copie a bazei Biovitality ([VERIFICARE.md](VERIFICARE.md); [MIGRARI.md](MIGRARI.md),
      „Pe server, la adoptare”, pasul 2): migrările trec, paginile și calculele arată ca pe live.
- [ ] Înghețarea setărilor Biovitality confirmată (`provizoriu` scos, fișierul `inchis`, commit; o facem noi). Cu ea încă
      provizorie, `adopta.sh` se oprește la verificări.
- [ ] Commit-ul live al Biovitality: `git -C ~/domains/biovitality.ro/public_html log -1 --format=%H` și
      `git -C ~/domains/biovitality.ro/public_html status --short` (trebuie gol). Ni-l trimiți; noi confirmăm că e
      cel revizuit (azi `779e0d8`, [de-portat.md](de-portat.md)).
- [ ] hPanel → biovitality.ro: PHP 8.3, **sodium** bifat, Auto deployment (GIT) oprit; o copie Hostinger recentă
      (hPanel → Backups), ca plasă în plus.
- [ ] `~/domains/biovitality.ro/site/privat/setari-inghetate.json` (de la noi) și
      `~/domains/biovitality.ro/site/storage/licenta/licenta.json` (din CRM) puse.
- [ ] Cronurile Biovitality copiate din hPanel în `~/cron-bv.txt`.

### Seara, înainte (aproximativ 10 minute, fără nicio schimbare)

```sh
ssh -p 65002 uXXXXXXXXX@<IP>
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
cd ~/platforma/repo && git fetch origin && git checkout --detach origin/claude/etapa1-cod-comun
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica        # notezi versiunea: V=2026.11.…-…
V=<versiunea>
deploy/adopta.sh --site=biovitality --domeniu=biovitality.ro --versiune=$V --commit-vechi=<commit-ul confirmat> \
    --cheie-licenta=<cheia CRM-ului> --cron-vechi=$HOME/cron-bv.txt
```

Ultimul rând trebuie să fie `PLAN: site-ul n-a fost schimbat …`, fiecare verificare `OK`, iar planul migrărilor cu
codul 10 („de aplicat, toate fără blocare”). Orice `NU` sau alt cod: ne trimiți ieșirea și nu mergi mai departe.

### Noaptea (la o oră cu puține comenzi, de exemplu 23:30)

```sh
deploy/adopta.sh --site=biovitality --domeniu=biovitality.ro --versiune=$V --commit-vechi=<commit-ul confirmat> \
    --cheie-licenta=<cheia CRM-ului> --cron-vechi=$HOME/cron-bv.txt --aplica
```

Cât durează: verificările, pregătirea și copiile 2–5 minute (site-ul merge); mentenanța 3–10 minute (comutarea e
instantanee; copia bazei și migrările cer cel mai mult). Dacă SSH-ul cade la jumătate, te reconectezi, dai din nou
`export PHP=…` și aceeași comandă: continuă de unde a rămas.

Când apare `GATA`:

1. hPanel → Cron Jobs: ștergi rândurile vechi, adaugi pe cele noi din `~/domains/biovitality.ro/site/deploy/cron-nou.txt`.
2. În browser (fereastră privată): prima pagină, o categorie, un produs, coșul, finalizarea comenzii până la pagina de
   plată, logarea în admin, lista comenzilor, Setări → ERP ANDAXI. În admin nu trebuie să apară „Licența lipsește” sau
   bannerul roșu al schemei.
3. `$PHP ~/platforma/bin/platforma --site=biovitality.ro scripts/diagnostic.php` (ultimul rând `Gata.`).

### Dacă ceva nu merge

- Scriptul s-a oprit după comutare: site-ul e în mentenanță. Dacă motivul e clar și mic, repari și rulezi aceeași
  comandă. Altfel, fără să mai cauți: `deploy/revino.sh --site=biovitality --aplica` (1–3 minute), apoi ne trimiți
  jurnalul (`~/domains/biovitality.ro/site/deploy/jurnale/`).
- Site-ul e deschis, dar găsești o problemă gravă (comenzile nu se pot plasa): `deploy/revino.sh --site=biovitality`
  (planul spune câte comenzi noi există), apoi `--aplica --fara-baza` (comenzile noi rămân) și cronurile vechi înapoi
  în hPanel.

### A doua zi dimineața

- `~/domains/biovitality.ro/site/storage/logs/fatal.log` gol, `php.log` fără `PHP Fatal`.
- Comenzile din noapte și de dimineață în admin, cu plata confirmată (Stripe → Developers → Webhooks: livrările
  reușite) și trimise în ERP (cronul `erp-sync.php`, Setări → ERP ANDAXI).
- Emailurile: `$PHP ~/platforma/bin/platforma --site=biovitality.ro scripts/diagnostic.php --email`.
- Cronurile noi au rulat (hPanel → Cron Jobs, ultima rulare) și FAN-ul își actualizează AWB-urile.
- Monitorul CRM-ului (pingul `/_andaxi/ping`) verde; în admin, Jurnalul 404 (adresele vechi căutate).
- Clienții logați aseară trebuie să se logheze din nou (sesiunile codului vechi nu trec); coșurile vechi se pierd.
- După 7 zile fără probleme: `public_html.vechi/` și copiile din `site/deploy/copii/` se pot șterge (ne întrebi întâi).

## Ce nu face (încă)

- **Sesiunile codului vechi nu trec**: clienții și adminii se loghează din nou, coșurile deschise se pierd.
- **Webhook-urile din timpul mentenanței** (plăți, ERP) ajung la codul nou chiar în timpul migrărilor, ca să nu se
  piardă; o revenire cu baza le șterge odată cu restul datelor de după copie (de aceea `--fara-baza`).
- **Cronurile** nu se schimbă singure: hPanel nu se poate scrie din SSH. Dacă `crontab -l` nu le arată, le dai cu
  `--cron-vechi=`.
- **`.htaccess`-ul din `public_html`** e al stub-ului: rândurile puse acolo de altcineva (hPanel, un plugin de cache)
  nu trec. Dacă `.htaccess`-ul vechi diferă de git, verificările se opresc („fișiere schimbate”) și ne trimiți diferența.
- **Spațiul**: `df` nu arată limita contului Hostinger; verifici în hPanel → Disk usage că încap două copii ale bazei.
- **O versiune mai veche decât baza** (după o actualizare cu migrări noi) merge doar cu `--inapoi` (fără migrări).

## Pentru dezvoltare

- Codul: `deploy/lib/deploy.php` (ce au în comun: ieșirea, comenzile, baza, docroot-ul, testul de fum),
  `deploy/lib/{release,adopta,actualizeaza,revino}.php`, `deploy/lib/php.sh` (alegerea PHP-ului), `bin/platforma`.
  Scripturile nu încarcă aplicația: codul unui site îl rulează doar prin `bin/platforma` al versiunii.
- Testul: `tools/dev/php.sh tools/dev/test-deploy.php` (poarta **G-deploy**, și cu PHP-ul minim): o găzduire simulată
  cu două site-uri vechi (checkout git, baze făcute ca a codului Biovitality), trei versiuni (una cu prima pagină
  stricată), o migrare care pică, un test de fum care pică, open_basedir, actualizări cu întoarcerea singură și
  revenirile, cu codul și baza comparate octet cu octet. `CMS_DEV_PASTREAZA=1` lasă găzduirea pentru cercetat.
- Stub-ul și `bin/platforma` le verifică și `tools/dev/test-paths.php` (B).
