# Site-ul de verificare

Site-ul de verificare e un site separat, pe găzduirea Hostinger, pe care se încearcă codul comun
înainte să ajungă la un client: aceleași pagini, aceeași bază de date (o copie anonimizată), plăți
doar de test, emailuri doar spre proprietar. **E închis publicului din prima cerere după deploy.**

Ce există acum: poarta, configurarea și verificarea serverului (WP-08a), cele **14 gărzi ale copiei**
(emailuri doar spre proprietar, plăți doar de test, ERP, FAN și EuPlătesc oprite, fără etichetele
terților, pozele de pe site-ul sursă, bannerul), licența de verificare și ștergerea copiei (WP-08), și
**încărcarea copiei** (WP-09, `incarca.php`): copia anonimizată a bazei Bioscem (datele personale sunt
schimbate în timp ce se citesc, deci nu ajung niciodată în baza copiei) sau o copie demonstrativă, fără
date personale, pentru repetițiile personalului. Până la prima încărcare site-ul arată, după parolă, doar
„Copia de verificare lipsește”, iar `stare.php` se termină cu „GATA”: site-ul e pregătit pentru date.

Tot de aici, din WP-10, rulează **verificările pe site-urile live** (pasul 5): citesc baza celor patru
site-uri doar în citire și scriu numai numere, DA/NU și nume de structură.

Din WP-11b, aceleași pentru **Biovitality** (pasul 6b): încărcarea anonimizată a bazei biovitality.ro
(`incarca.php --din=biovitality`, regulile Bioscem plus ale Biovitality), comparația calculelor cu profilul Biovitality
(`compara-calcule.php --profil=biovitality`, coșurile cu pachete excluse și numărate) și amprenta biovitality.ro. O singură
sursă odată: copia Bioscem se șterge înainte (`sterge.php`).

Din WP-11, după încărcare (pasul 6, e și f): **comparația calculelor** (golden master-ul: codul vechi al site-ului,
copiat de încărcare, și codul comun calculează pe aceeași copie, pentru aceleași comenzi, coșul, transportul, punctele și
ce pleacă în ERP; pe ecran doar numere) și **amprenta paginilor** (ce vede un vizitator pe fiecare adresă publică, pe
site-ul live și pe copie, apoi cele două comparate câmp cu câmp).

| Fișier | Ce e |
|---|---|
| `src/Support/Verificare.php` | Poarta (prima în `public/index.php`), ajutoarele ei și cele 14 gărzi ale copiei (WP-08) |
| `src/Support/IpClient.php` | Adresa vizitatorului (`REMOTE_ADDR`, sau `X-Forwarded-For` doar de la un proxy de încredere), din WP-12b pentru tot site-ul |
| `src/Support/ParolaAdmin.php` | Felul parolei salvate a unui admin (hash, bcrypt vechi, hash stricat, goală, text clar) și când se reface după logare: aceeași regulă la logare și în numărătoarea `admini` a seturilor (WP-12b) |
| `tools/verificare/configureaza.php` | Scrie `.env`-ul site-ului și face cheia licenței de verificare (o dată, pe server, prin SSH) |
| `tools/verificare/stare.php` | Ce e configurat, ce știe găzduirea, dacă site-ul e închis și fiecare gardă; doar DA/NU și versiuni; ultimul rând „GATA” |
| `tools/verificare/licenta.php` | Pune pe copie un pachet de pluginuri (`bioscem`, `magazin`, `toate`, …), semnat cu cheia de pe server |
| `tools/verificare/sterge.php` | Șterge copia (baza, emailurile ca fișiere, cache-ul, sesiunile, codul vechi), după fiecare sesiune |
| `tools/verificare/incarca.php` (+ `lib/incarcare.php`) | Încarcă copia: `--din=bioscem` (anonimizată în drum) sau `--demo` (date inventate), noaptea, prin SSH (WP-09, mai jos) |
| `tools/verificare/reguli/bioscem.php` | Regulile anonimizării Bioscem: pentru fiecare tabel și fiecare coloană, păstrată, schimbată (cum) sau doar structura |
| `tools/verificare/reguli/biovitality.php` | Regulile anonimizării Biovitality (WP-11b): cele Bioscem plus tabelele și coloanele codului Biovitality (jetoanele aplicației Windows, dosarele și jurnalele fișierelor din cloud doar ca structură; concurența, etichetele blogului, pachetele copiate) și cheile fișierelor din cloud (`bunny_*`) golite |
| `tools/verificare/lib/licenta-copie.php`, `lib/intrebari.php` | Licența copiei (folosită de `licenta.php` și de încărcare) și întrebările din terminal (de `configureaza.php` și de încărcare) |
| `tools/verificare/lib/cont.php` | Contul SSH e comun cu site-urile live: o bază scrisă în `.env`-ul altui site e refuzată de toate uneltele de mai sus |
| `tools/verificare/lib/php-cli.php` | PHP-ul liniei de comandă și extensiile lui: o extensie cu modulul pe disc, dar neîncărcată (sodium în `/opt/alt/php83` pe Hostinger), primește reparația exactă (`PHP="… -d extension=sodium.so"`); „Pasul următor” în forma cu `$PHP`; procesele copil primesc aceleași `-d` (mai jos) |
| `tools/verificare/lib/roboti-gazda.php` | robots.txt-ul CDN-ului Hostinger pe domeniul temporar: recunoscut exact, rând INFO în `stare.php` (mai jos) |
| `tools/verificare/agregate.php` (+ `seturi/`, `lib/`) | Verificările agregate pe un site live (WP-10, mai jos) |
| `tools/sonda/amprenta-schema.php` (+ `lib/`) | Sonda schemei unui site live (WP-10, MIG-08) |
| `tools/verificare/compara-calcule.php` (+ `lucrator-calcule.php`, `lib/calcule.php`) | Comparația calculelor (golden master, WP-11): codul vechi din `cod-vechi/` și codul comun, pe copie, pentru aceleași comenzi; doar numere (mai jos) |
| `tools/verificare/amprenta-site.php` (+ `lib/amprenta.php`, `tools/dev/lib/normalizeaza-html.php`) | Amprenta paginilor unui site, live sau de verificare (WP-11): pentru fiecare adresă publică codul, unde trimite, titlul, robots, canonical, H1, JSON-LD…; două amprente se compară (mai jos) |
| `tools/dev/test-calcule.php`, `tools/dev/test-amprenta-site.php`, `tools/dev/test-calcule-bv.php` (prin `tools/dev/compara-calcule-local.sh`) | Testele lor (poarta G-calc): local, față de codul Bioscem însuși și (WP-11b) față de codul Biovitality, și pe un site de verificare simulat |
| `tools/dev/test-verificare.php` (+ `lib/gaura-neagra.php`, `lib/cdn-simulat.php`, `lib/php-fara-extensii.php`) | Testele (poarta G-verif), pe o găzduire simulată local, cu o plasă de siguranță care numără orice cerere spre afară, un CDN Hostinger simulat și un PHP care nu încarcă singur o extensie |
| `tools/dev/test-sonde.php` | Testele verificărilor pe site-urile live (poarta G-sonde), tot pe o găzduire simulată |
| `tools/dev/test-incarcare.php` | Testele încărcării copiei (tot poarta G-verif), pe o găzduire simulată cu un Bioscem „live” inventat și (WP-11b) un Biovitality „live” făcut ca baza codului Biovitality, pe MariaDB (și MySQL 8, fără `--doar-mariadb`) |

## Pașii proprietarului (copiat și lipit)

### 1. O dată, în hPanel (aproximativ 10 minute)

1. Websites → site-ul nou → **SSL**: instalează certificatul gratuit.
2. Advanced → **PHP Configuration**: **PHP 8.3**, aceeași versiune ca la cele patru site-uri live
   (decizia OD-48: `php_min` = 8.3); în fila **PHP extensions** bifezi **sodium** (licența copiei îl cere pe site), Save.
3. Advanced → **SSH Access**: pornește accesul și notează rândul afișat (`ssh -p 65002 u…@…`).
4. **Databases**: creează o bază de date nouă, goală, cu utilizatorul ei. Notează numele bazei și al
   utilizatorului (de forma `u123456789_verif`). Parola o păstrezi doar tu: o scrii direct pe server, la
   pasul 3.
5. (Opțional) **Emails**: creează căsuța `verificare@<domeniul-verificare>` și păstrează-i parola. Fără
   ea, emailurile site-ului de verificare vor rămâne fișiere pe server.
6. Files → **File Manager** → `public_html` al site-ului nou: trebuie să fie gol. Șterge ce a pus
   Hostinger acolo (`default.php`, `index.php`).

Site-ul trebuie să fie un **site separat** (Websites → Add website), cu dosarul lui
`~/domains/<domeniul-verificare>/public_html`. Un subdomeniu pus de hPanel în dosarul altui site
(`~/domains/<alt-site>/public_html/<sub>`) nu e acceptat de `configureaza.php`. Poate rămâne pe
domeniul temporar dat de Hostinger (`….hostingersite.com`), cum e acum. Pe domeniul temporar
**CDN-ul Hostinger nu se poate opri** și răspunde el la `/robots.txt` (vezi „robots.txt pe domeniul
temporar”, mai jos): `stare.php` știe asta și îți arată un rând INFO, nu NU.

**Contul SSH e același cu al site-urilor live.** Comenzile de mai jos se dau doar din dosarul
site-ului de verificare (`cd ~/domains/<domeniul-verificare>/public_html`), niciodată din dosarul
unui site live; `configureaza.php` refuză oricum alt dosar decât `public_html`-ul site-ului lui, iar
o bază de date cu tabele care nu e o copie de verificare.

Ne trimiți: domeniul, versiunea PHP, rândul SSH și versiunea PHP a fiecăruia dintre cele 4 site-uri live
(hPanel → PHP Configuration). **Fără parole.**

### 2. Codul, cu hPanel → GIT sau prin SSH (după ce îți scriem că poarta e pe GitHub)

Două feluri, oricare (rezultatul e același: codul din git în `public_html`).

**a) Prin SSH** (așa l-ai adus pe 21 oct 2026), în `public_html`-ul gol de la pasul 1.6:

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
cd ~/domains/<domeniul-verificare>/public_html
ls -A                                # trebuie să nu arate nimic (pasul 1.6)
git clone --branch claude/etapa1-cod-comun --single-branch https://github.com/SilverName99/ANDAXI-CMS.git .
#   punctul de la sfârșit: codul vine chiar în public_html, nu într-un subdosar
```

Codul nou, mai târziu: `git pull --ff-only` din același dosar (mai jos, „Codul nou, mai târziu”).

**b) Cu hPanel → GIT:** Websites → site-ul nou → Advanced → **GIT** → Create a New Repository:

- **Repository**: `https://github.com/SilverName99/ANDAXI-CMS.git` (nu trebuie nicio cheie). Decizia
  OD-49: repo-urile rămân **publice** deocamdată, deci în ele nu intră nicio dată personală a
  clienților și nimic scos din datele site-urilor live.
  - Când îl faci **privat** (după ce e gata noul backend): `git@github.com:SilverName99/ANDAXI-CMS.git`.
    hPanel îți arată atunci o cheie SSH a site-ului (Generate SSH key / SSH key). O adaugi în GitHub →
    `ANDAXI-CMS` → Settings → Deploy keys → Add deploy key, **fără** „Allow write access” (doar citire).
- **Branch**: `claude/etapa1-cod-comun`
- **Directory**: gol (adică `public_html`)
- **Create**, apoi **Deploy**.

Din prima cerere după Deploy, site-ul răspunde oricui cu „Site neconfigurat” (503): fără `.env` nu arată
nimic din aplicație, nici textele implicite din cod.

### 3. Prin SSH, imediat după Deploy (aproximativ 5 minute)

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
cd ~/domains/<domeniul-verificare>/public_html
ls /opt/alt | grep php              # versiunile PHP; alege-o pe cea de la pasul 1.2, de exemplu php83
PHP=/opt/alt/php83/usr/bin/php
$PHP tools/verificare/stare.php
#   pe Hostinger, prima dată ultimul rând e „NU E GATA: 2 verificări au ieșit NU …”, cele două fiind
#   „NU    extensia sodium (CLI)” (cu dedesubt „INFO  Lipsește extensia PHP sodium … PHP="…"”) și
#   „NU    .env există (îl scrie configureaza.php)” (încă nu e configurat; cu alte rânduri NU, trimite-ne textul).
#   PHP-ul acesta nu încarcă singur sodium, deși îl are pe disc (așa e pe Hostinger). Scrii rândul din INFO:
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
$PHP tools/verificare/stare.php
#   acum „DA    extensia sodium (CLI)” și ultimul rând „NECONFIGURAT: site-ul e închis publicului (503) …”
#   (singurul NU rămas e .env-ul); de aici încolo toate comenzile cu acest $PHP (și la pașii 5–8)
$PHP tools/verificare/configureaza.php
#   întreabă: numele bazei, utilizatorul bazei, parola bazei (nu se vede când scrii),
#   emailul tău (emailurile de test merg doar acolo; adresa stă doar în .env-ul de pe server,
#   niciodată în git), căsuța de trimitere și parola ei (Enter = fără căsuță),
#   parola porții (de două ori, cel puțin 10 caractere, alta decât parola bazei; nu trebuie semne sau cifre);
#   Ctrl-C oprește oricând, fără să scrie nimic
#   pe ecran doar DA/NU: legătura cu baza, „baza e goală”, „baza nu e a altui site din cont”,
#   „.env scris, doar pentru tine (0600)”, „cheia licenței de verificare făcută acum”, apoi „Gata.”
#   „Pasul următor” îți arată comanda; cu sodium prin -d, în forma PHP="…"; $PHP tools/verificare/stare.php
$PHP tools/verificare/stare.php
#   ultimul rând trebuie să fie: „GATA: site-ul de verificare e pregătit (toate verificările DA)”
#   pe domeniul temporar, un rând „INFO  /robots.txt îl dă CDN-ul Hostinger (server: hcdn) …”: așa trebuie (mai jos)
```

**Sodium pentru site (web):** dacă `stare.php` arată „NU    extensia sodium (web)”: hPanel → Advanced → **PHP
Configuration** → **PHP extensions** → bifezi **sodium** → Save, apoi `$PHP tools/verificare/stare.php` din nou. Bifa
schimbă doar PHP-ul site-ului; pentru linia de comandă rămâne `$PHP` cu `-d extension=sodium.so` de mai sus.

**robots.txt pe domeniul temporar.** Pe `….hostingersite.com` CDN-ul Hostinger (`server: hcdn`) răspunde singur la
`/robots.txt`, fără să ajungă la site: „User-agent: Googlebot / Disallow: /”, apoi „User-agent: * / Allow: /”. Pe un
domeniu temporar CDN-ul nu se poate opri. Site-ul rămâne închis: Googlebot e oprit chiar de găzduire, iar orice alt robot
primește 401 la poartă (fără parolă nu vede nimic), cu `noindex` pe fiecare răspuns. `stare.php` recunoaște exact acest
răspuns (textul CDN-ului, `server: hcdn`, fără antetele site-ului, domeniu temporar, poarta 401, `noindex` și `no-store`,
robots.txt-ul site-ului tot „Disallow: /”) și îl arată ca rând INFO; garda 4 rămâne DA. Orice alt răspuns e tot NU, cu
motivul în paranteză: alt text, alt server, site-ul care ar răspunde altceva, sau un **domeniu propriu** (acolo CDN-ul se
oprește din hPanel; după ce îl oprești, `stare.php` din nou).

`configureaza.php` face și **cheia licenței de verificare**: o pereche de chei pe care o are doar acest
server (cheia privată în `storage/licenta/`, doar pentru tine; cea publică în `.env`). Cu ea se pune pe
copie pachetul de pluginuri (mai jos, „Licența copiei”); niciun site adevărat nu o recunoaște.

Ne trimiți **tot textul** afișat de ultimul `stare.php`. Nu conține parole, chei, numele bazei sau adrese
de email: doar DA/NU, versiuni și setări PHP. Din el aflăm ce nu știm despre găzduire (versiunea PHP a
site-ului, dacă antetul parolei ajunge la PHP, ce adresă vede PHP-ul pentru vizitator, ce funcții sunt
oprite, ce server de baze de date are contul) și că fiecare dintre cele 14 gărzi ale copiei e la locul ei
(secțiunea „Gărzile copiei”, rândurile 1–14, toate DA). Abia cu „GATA” se poate încărca o copie (pasul 6).

### 4. În browser

Deschide `https://<domeniul-verificare>/`. Browserul cere utilizatorul `verificare` și parola porții.
După ea vezi „Copia de verificare lipsește”: **așa trebuie**, până la prima încărcare. Dacă fereastra
browserului nu primește parola (apare din nou și din nou), apasă „Anulează”: pagina are un formular cu
aceleași două câmpuri.

### 5. Verificările pe site-urile live (noaptea, după ce îți scriem că sunt pe GitHub)

Ce fac: citesc baza fiecăruia dintre cele patru site-uri live **doar în citire** și scriu numere, DA/NU
și nume de structură (tabele, coloane, chei de setări, clase CSS, adresele paginilor publicate, id-uri
de produse): **nicio dată a unui client, nicio parolă**. Din ele aflăm ce folosește fiecare site
(pluginurile, plățile, ERP-ul), ce schemă are baza lui și ce s-ar schimba la trecerea pe codul comun.

Rulează din dosarul site-ului de verificare. Datele de acces la baza fiecărui site live le citesc din
`.env`-ul acelui site, fără să le afișeze. Pe site-urile live **nu scriu nimic** (nici fișiere, nici
sesiuni) și nu le schimbă baza: sesiunea bazei e doar de citire, fiecare interogare e verificată
înainte și are cel mult 15 secunde.

**Când:** noaptea, între 23:00 și 02:00 sau după 03:30 (la 02:45 rulează copia de noapte). O comandă
durează sub un minut.

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
cd ~/domains/<domeniul-verificare>/public_html
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # ca la pasul 3 (fără -d dacă sodium (CLI) e DA și așa)
#   întâi codul nou: hPanel → GIT → Deploy (sau git pull --ff-only, ca la „Codul nou, mai târziu”)
$PHP tools/verificare/agregate.php --set=bioscem
$PHP tools/verificare/agregate.php --set=grafoanaytis
$PHP tools/verificare/agregate.php --set=biovitality
$PHP tools/verificare/agregate.php --set=vertij
$PHP tools/sonda/amprenta-schema.php --site=bioscem
$PHP tools/sonda/amprenta-schema.php --site=grafoanaytis
$PHP tools/sonda/amprenta-schema.php --site=biovitality
$PHP tools/sonda/amprenta-schema.php --site=vertij
ls -l storage/verificare/sonde/
#   8 fișiere: agregate-<site>-<data>.txt și amprenta-<site>-<data>.json
```

Fiecare comandă găsește singură dosarul site-ului live (`~/domains/<domeniu>/public_html` sau
`~/public_html`) după domeniul din `.env`-ul lui și îl scrie în primele rânduri. Ultimul rând trebuie
să fie „GATA: …”. „PARȚIAL: …” înseamnă că unele verificări au căzut (rândurile EROARE sau SĂRIT);
restul e bun, deci ne trimiți oricum fișierul. „OPRIT: …” înseamnă că PHP-ul a rămas fără memorie sau
timp: ce s-a strâns până acolo e tot scris (pe ecran și în fișier), iar comanda următoare merge la fel.

**Ne trimiți cele 8 fișiere:** hPanel → Files → File Manager → `domains/<domeniul-verificare>/public_html/
storage/verificare/sonde/` → le selectezi pe toate → Download. Apoi le ștergi de pe server:

```sh
rm storage/verificare/sonde/*
```

Fișierele au numere scoase din datele site-urilor (câte comenzi, câte conturi…), deci **nu le pune în
git și nu le publica**: repo-urile sunt publice (decizia OD-49). La noi stau tot în afara git-ului
(`tools/dev/migrari/vechi/privat/`). În browser nu se văd: `storage/` nu e servit.

### 6. Încărcarea copiei Bioscem (noaptea, după ce îți scriem că e pe GitHub)

Ce face: copiază baza Bioscem pe site-ul de verificare și **schimbă datele personale în timp ce le
citește** (numele, adresele și etichetele lor, telefoanele, emailurile, parolele, IP-urile, textele scrise de
clienți, adresele de email din recenziile publice, codurile unice de cupon, linkurile de plată), deci în baza copiei
nu ajunge nicio dată a unui client. Pe site-ul Bioscem
**nu scrie nimic**: îi citește baza doar în citire, fără să oprească sau să încetinească comenzile, și îi copiază
codul (fără `.env`, poze, sesiuni, jurnale, copii de baze) lângă site-ul de verificare, în
`~/domains/<domeniul-verificare>/cod-vechi/bioscem/`, în afara `public_html` (nu se vede din browser). Apoi
rulează pe copie migrările codului comun (repetiția adoptării Bioscem), verifică singură copia (verificările
A1–A9: doar numere), îți face contul de admin și pune licența Bioscem. Dacă o verificare pică, **șterge singură
copia**.

**Când:** noaptea, între 23:00 și 02:00 sau după 03:30 (la 02:45 rulează copia de noapte). Durează câteva
minute.

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
cd ~/domains/<domeniul-verificare>/public_html
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # ca la pasul 3 (fără -d dacă sodium (CLI) e DA și așa)
#   întâi codul nou: hPanel → GIT → Deploy (sau git pull --ff-only, ca la „Codul nou, mai târziu”)

# a) doar dacă .env-ul e de dinainte de licența de verificare (din WP-08a): îl scrie din nou, cu cheia licenței
$PHP tools/verificare/configureaza.php --rescrie
#   cere din nou totul, ca la pasul 3 (baza, utilizatorul, parola bazei, emailul tău, căsuța, parola porții)

# b) site-ul e pregătit?
$PHP tools/verificare/stare.php
#   ultimul rând trebuie să fie: „GATA: site-ul de verificare e pregătit (toate verificările DA)”

# c) încărcarea
$PHP tools/verificare/incarca.php --din=bioscem
#   găsește singur dosarul Bioscem (după domeniul din .env-ul lui) și îl scrie în primele rânduri
#   întreabă: „da” ca să înceapă; emailul contului tău de admin pe copie (Enter = adresa ta din .env);
#   parola acestui cont, de două ori (cel puțin 10 caractere, poate fi aceeași cu parola porții; nu se vede)
#   pe ecran doar DA/NU, numere, nume de tabele și durate; ultimul rând trebuie să fie:
#   „GATA: copia e anonimizată, toate verificările au trecut”

# d) din nou
$PHP tools/verificare/stare.php
#   ultimul rând din nou „GATA: …”, acum și cu „DA    copia e încheiată și anonimizată”

# e) comparația calculelor: codul vechi Bioscem (copiat la c, în cod-vechi/bioscem/) față de codul comun, pe copie
$PHP tools/verificare/compara-calcule.php
#   câteva minute; pe ecran doar numere și nume de câmpuri; ultimul rând trebuie să fie: „REZULTAT: TRECE”
#   textul e salvat și în storage/verificare/sonde/calcule-bioscem-<data>.txt
```

**Amprenta paginilor** (f), tot noaptea, după (e): ce vede un vizitator pe fiecare adresă publică a bioscem.ro (codul
vechi) și a copiei (codul comun), câte o cerere pe secundă, doar GET (cam o oră pentru tot site-ul; pe bioscem.ro nu
schimbă nimic, e ca un vizitator care deschide paginile pe rând), apoi cele două comparate:

```sh
# f) amprenta paginilor
$PHP tools/verificare/amprenta-site.php --baza=https://bioscem.ro --iesire=storage/verificare/sonde/amprenta-bioscem.tsv
read -rs VERIFICARE_POARTA_PAROLA        # scrii parola porții (nu se vede), apoi Enter
export VERIFICARE_POARTA_PAROLA
$PHP tools/verificare/amprenta-site.php --baza=https://<domeniul-verificare> --poarta=verificare --gazda-sursa=bioscem.ro --iesire=storage/verificare/sonde/amprenta-copie.tsv
unset VERIFICARE_POARTA_PAROLA
$PHP tools/verificare/amprenta-site.php --compara storage/verificare/sonde/amprenta-bioscem.tsv storage/verificare/sonde/amprenta-copie.tsv
#   ultimul rând: „REZULTAT: LA FEL” (sau „DIFERĂ”, cu adresele și câmpurile care diferă)
```

Parola porții se dă doar așa (`read -rs`), niciodată pe linia comenzii; unealta o trimite doar copiei, doar pe https, și
se oprește după o singură încercare greșită (nu ajunge la cele 10 care închid poarta). `--gazda-sursa=bioscem.ro` spune că
pe copie conținutul vine de pe bioscem.ro: descrierile și paginile importate din WordPress au linkuri scrise întreg spre
`https://bioscem.ro/…`, care pe bioscem.ro sunt linkuri ale site-ului și trebuie să fie la fel pe copie (fără opțiune,
amprenta copiei se oprește după prima pagină și îți spune s-o dai). În fișierele `.tsv` nu e numele niciunui site, doar
adrese publice (`{GAZDA}/…`), titluri și amprente.

Dacă `configureaza.php --rescrie` (a) nu e nevoie, `stare.php` (b) spune „GATA” direct; dacă e nevoie, încărcarea
(c) îți spune singură „REFUZAT: … configureaza.php --rescrie” și nu atinge nimic.

Apoi, în browser: `https://<domeniul-verificare>/admin/login` (după parola porții), cu emailul și parola
contului tău de la (c). Conturile de admin ale site-ului Bioscem sunt pe copie cu adrese `admin<n>@verificare.invalid`
și parole pe care nu le știe nimeni, deci nu se pot loga; contul tău e administrator general.

**Ne trimiți tot textul** afișat (nu are date ale clienților, parole sau numele bazei). E salvat și în
`storage/verificare/sonde/incarcare-bioscem-<data>.txt`, cel al comparației în `calcule-bioscem-<data>.txt`, iar
amprentele în `amprenta-bioscem.tsv` și `amprenta-copie.tsv`; le descarci la fel ca la pasul 5 și le ștergi apoi.

Comparația calculelor rulează doar pe copie (pe un site live refuză: ambele coduri ar schimba schema bazei chiar în
calculele verificate). Pe durata ei, cupoanele comenzilor comparate devin valabile (altfel un cupon expirat de mult nu s-ar
mai calcula în niciun cod) și se pun la loc la final; o comparație oprită le pune la loc la începutul celei următoare.

**Dacă se oprește:**

- „ÎNTRERUPT: … Continuă cu: … --reia” (legătura cu baza s-a pierdut, sau timpul dat cu `--buget`): rulezi
  comanda din rând, `$PHP tools/verificare/incarca.php --din=bioscem --reia`. Întreabă la fel și continuă de la
  grupul de tabele la care a rămas, cu aceleași pseudonime.
- Terminalul s-a închis sau găzduirea a oprit comanda (ultimul rând nu e „GATA”, „ÎNTRERUPT” sau „OPRIT”): tot
  `--reia`. Până atunci copia rămâne închisă („Copia de verificare lipsește”).
- „OPRIT: încărcarea nu s-a terminat, iar copia a fost ștearsă singură”: o verificare a picat sau Bioscem are o
  coloană nouă, fără regulă de anonimizare (rândul „NU” de deasupra îi spune numele; la A1, tabelul și coloana în
  care a rămas o adresă de email, fără adresă). Nu faci nimic; ne trimiți textul, iar după ce îți scriem, rulezi
  încărcarea din nou.
- „REFUZAT: …” (de exemplu „legătura cu baza site-ului nu merge”): nu s-a atins nimic, nici copia de dinainte, nici
  `.env`-ul; ne trimiți rândul.
- Dacă găzduirea oprește comenzile lungi: `$PHP tools/verificare/incarca.php --din=bioscem --buget=600` (după 10
  minute nu mai începe niciun grup nou și se oprește cu „ÎNTRERUPT”), apoi `--reia --buget=600` până la „GATA”.

O încărcare făcută din nou peste copia de dinainte: `--reincarca`. Copia se închide singură după 7 zile
(„Copia de verificare a expirat”); oricum o ștergi la final cu `sterge.php` (pasul 8).

**Copia demonstrativă** (pentru repetițiile personalului unui client, decizia OD-39): aceeași comandă, cu
`--demo` în loc de `--din=bioscem`. Nu deschide nicio bază a unui site: copia e făcută din migrări și din
datele inventate ale codului, cu pachetul site-ului clientului.

```sh
$PHP tools/verificare/sterge.php                                # întâi, dacă pe copie e altceva
$PHP tools/verificare/incarca.php --demo --pachet=biovitality   # sau bioscem, vertij, grafoanaytis
#   întreabă: „da”; emailul și parola contului tău (ca mai sus); apoi parola personalului, de două ori
#   (alta decât a ta): o primesc cei 3 admini cu rol parțial (adresele lor sunt pe ecran, …@verificare.invalid)
#   și clienții inventați; o dai tu personalului, pentru sesiunea lor
#   ultimul rând: „GATA: copia e anonimizată, toate verificările au trecut”
```

`--licenta=<pachet>` alege alt pachet de pluginuri decât al site-ului (numele: `licenta.php presetari`). Pentru
fiecare pachet (din WP-16 și pentru Bioscem: destinatarii formularului de contact; din WP-18 și datele de contact de pe
acordul GDPR) trebuie pe server, lângă pachet, valorile private ale înghețării setărilor
(`site-uri/<site>/privat/setari-inghetate.json`, pe care ți-l trimitem noi; nu e în git): fără el încărcarea refuză
de la început (codul 2, „… codul 15 …”), înainte de orice schimbare.

### 6b. Biovitality: încărcarea, comparația calculelor și amprenta (noaptea, după ce îți scriem că e pe GitHub; WP-11b)

Ca la pasul 6, pentru biovitality.ro (decizia OD-46: baza Biovitality se citește noaptea, doar în citire, anonimizată în
drum, ștearsă după). **O singură sursă odată**: dacă pe copie e încărcat Bioscem (sau copia demonstrativă), întâi se
șterge; încărcarea Biovitality refuză altfel, fără să atingă nimic. Înainte, valorile private ale înghețării
Biovitality, `site-uri/biovitality/privat/setari-inghetate.json` (nu e în git; fără el încărcarea refuză de la început,
codul 2, „… codul 15 …”): patru valori, toate date de contact ale firmei, nicio parolă sau cheie — destinatarii
formularului de contact, adresa de email și telefonul operatorului de pe acordul GDPR și numele reprezentantului lui (o
persoană, deci date personale). Nu ți-l trimitem: îl faci chiar pe server, din codul biovitality.ro (citit doar, din
dosarul lui), cu generatorul înghețării, care scrie **doar** acest fișier și numai dacă codul de pe site dă exact
înghețarea din pachet (`Mutare-Site-Biovitality@779e0d8`); altfel nu scrie nimic (cod 1, „nu e codul revizuit”) și ne
trimiți textul.

```sh
ssh -p 65002 uXXXXXXXXX@<IP-ul din hPanel>
cd ~/domains/<domeniul-verificare>/public_html
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # ca la pasul 3 (fără -d dacă sodium (CLI) e DA și așa)
git pull --ff-only                                       # codul nou (sau hPanel → GIT → Deploy)
$PHP tools/inghetare/genereaza-setari.php --site=biovitality --cod-vechi="$HOME/domains/biovitality.ro/public_html"
#   un rând: „OK    biovitality: înghețarea din codul site-ului = cea din pachet (Mutare-Site-Biovitality@779e0d8); scris
#   site-uri/biovitality/privat/setari-inghetate.json (0600; 4 valori private: contact_form_recipients, gdpr_operator_email,
#   gdpr_operator_reprezentant, gdpr_operator_telefon); pachetul neatins”
#   (dacă dosarul biovitality.ro e altul, dosarul care are src/ și .env-ul site-ului)

# a) doar dacă pe copie e altă încărcare (Bioscem sau demonstrativa): o ștergi
$PHP tools/verificare/sterge.php
#   arată ce șterge și întreabă; scrii „da”; ultimul rând: „GATA: copia a fost ștearsă”

# b) site-ul e pregătit?
$PHP tools/verificare/stare.php
#   ultimul rând: „GATA: site-ul de verificare e pregătit (toate verificările DA)”

# c) încărcarea Biovitality
$PHP tools/verificare/incarca.php --din=biovitality
#   găsește singur dosarul biovitality.ro (după APP_URL din .env-ul lui) și îl scrie în primele rânduri; întrebările ca la
#   pasul 6 („da”, emailul și parola contului tău de admin pe copie, de două ori)
#   ultimul rând trebuie să fie: „GATA: copia e anonimizată, toate verificările au trecut”
#   (A1–A9 DA; „licența copiei: pachetul biovitality”; migrările cu --site=biovitality)

# d) din nou
$PHP tools/verificare/stare.php
#   ultimul rând „GATA: …”, cu „DA    copia e încheiată și anonimizată”

# e) comparația calculelor: codul vechi Biovitality (copiat la c, în cod-vechi/biovitality/) față de codul comun
$PHP tools/verificare/compara-calcule.php --profil=biovitality
#   pe ecran doar numere și nume de câmpuri; rânduri de citit:
#   „excluse: pachete N” (coșurile cu un produs-pachet: numărate, necomparate, până la Etapa 4),
#   „excluse: epuizate N” (coșurile doar cu produse epuizate acum: goale în ambele coduri, deci necomparate),
#   „produse epuizate scoase din coș, în ambele coduri: …” și „payload ERP: chei noi ale codului comun …” (diferențele
#   declarate pe profil, mai jos);
#   ultimul rând trebuie să fie: „REZULTAT: TRECE” (cu „diferite 0”); textul e salvat în
#   storage/verificare/sonde/calcule-biovitality-<data>.txt
```

**Amprenta paginilor** (f), tot noaptea, după (e), ca la pasul 6: biovitality.ro (codul vechi) și copia (codul comun):

```sh
# f) amprenta paginilor
$PHP tools/verificare/amprenta-site.php --baza=https://biovitality.ro --iesire=storage/verificare/sonde/amprenta-biovitality.tsv
read -rs VERIFICARE_POARTA_PAROLA        # scrii parola porții (nu se vede), apoi Enter
export VERIFICARE_POARTA_PAROLA
$PHP tools/verificare/amprenta-site.php --baza=https://<domeniul-verificare> --poarta=verificare --gazda-sursa=biovitality.ro --iesire=storage/verificare/sonde/amprenta-copie-bv.tsv
unset VERIFICARE_POARTA_PAROLA
$PHP tools/verificare/amprenta-site.php --compara storage/verificare/sonde/amprenta-biovitality.tsv storage/verificare/sonde/amprenta-copie-bv.tsv
#   ultimul rând: „REZULTAT: DIFERĂ”, cu adresele și câmpurile (așteptat: Biovitality primește la adoptare livrările SEO,
#   harta site-ului, fluxul Google Merchant, adresele /categorie/… și celelalte schimbări din ce-se-schimba.md); lista o
#   trecem în ce-se-schimba.md, deci ne-o trimiți întreagă

# g) la final, ștergerea copiei (pasul 8)
$PHP tools/verificare/sterge.php
```

**Ne trimiți tot textul** (c, e, f), ca la pasul 6 (`incarcare-biovitality-<data>.txt`, `calcule-biovitality-<data>.txt`,
cele două amprente), apoi ștergi fișierele și `site-uri/biovitality/privat/` de pe server (`rm -r
site-uri/biovitality/privat`), dacă nu urmează imediat altă încărcare Biovitality. Dacă se oprește, aceleași rânduri ca la
pasul 6 („Dacă se oprește”), cu `--din=biovitality`. Dacă (c) se oprește cu „NU … coloană fără regulă” sau „NU
settings.… cheie necunoscută codului”, Biovitality are o coloană sau o setare nouă: ne trimiți textul (doar numele ei),
iar după ce scriem regula rulezi din nou. Dacă generatorul de la început spune „EȘEC … nu e codul revizuit”, codul de pe
biovitality.ro nu e commit-ul revizuit: ne trimiți rândul și nu încarci (înghețarea se reface întâi).

### 7. Licența copiei (pachetul de pluginuri)

Ce pluginuri are copia (de exemplu cele ale Bioscem, sau un pachet cu blogul oprit, ca să vezi ce
dispare) se alege tot din dosarul site-ului de verificare. Încărcarea copiei (pasul 6) pune singură
pachetul site-ului copiat; comanda de mai jos e pentru încercări:

```sh
cd ~/domains/<domeniul-verificare>/public_html
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # ca la pasul 3 (fără -d dacă sodium (CLI) e DA și așa)
$PHP tools/verificare/licenta.php presetari          # numele pachetelor: toate, bioscem, magazin, …
$PHP tools/verificare/licenta.php bioscem            # pluginurile Bioscem
$PHP tools/verificare/licenta.php bioscem blog=oprit # la fel, cu blogul oprit (pornit / se_opreste / oprit)
$PHP tools/verificare/licenta.php arata              # ce e pornit acum
```

Orice pachet poate urma oricărui alt pachet. Licența e semnată cu cheia de pe acest server, deci merge
doar aici: `licenta.php` refuză să ruleze din alt dosar sau pe un site care nu e cel de verificare.

### 8. După fiecare sesiune de verificare: ștergerea copiei

```sh
cd ~/domains/<domeniul-verificare>/public_html
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # ca la pasul 3 (fără -d dacă sodium (CLI) e DA și așa)
$PHP tools/verificare/sterge.php
#   arată ce șterge și întreabă; scrii „da”
#   ultimul rând: „GATA: copia a fost ștearsă”
```

Șterge tabelele bazei copiei, emailurile păstrate ca fișiere, cache-ul, sesiunile, pozele copiei demonstrative
și copia codului vechi (`~/domains/<domeniul-verificare>/cod-vechi/`). Rămân `.env`, licența, cheia ei și
rezultatele de la pașii 5 și 6 (pe care le ștergi tu, după ce ni le trimiți). Șterge la fel și o încărcare
oprită la jumătate.
Refuză să șteargă o bază care nu e o copie de verificare sau pe care o folosește alt site din cont. Dacă
uiți, copia se închide singură după 7 zile (decizia D1): site-ul arată „Copia de verificare a expirat”.

### Datele de pe copie și cine le vede

- **Datele sunt anonimizate la încărcare** (pasul 6): numele, adresele, telefoanele devin inventate, iar
  emailurile clienților devin adrese `…@verificare.invalid`, un domeniu rezervat care nu poate primi
  niciun email. Oricum, pe copie **orice email pleacă doar la adresa ta** (`VERIFICARE_EMAIL`), cu
  „[VERIFICARE]” în subiect, cel mult 100 pe zi; în fiecare email un rând arată cui i-ar fi plecat pe
  site.
- **Paginile publice** (cu parola porții) le vedem și noi, ca să verificăm cum arată (decizia D8);
  bannerul roșu „SITE DE VERIFICARE” de sus arată acolo adresa ta doar pe jumătate, și la fel pe pagina de
  logare a adminului. **Adminul copiei e al tău**: conturile de admin le face încărcarea, pentru tine; abia
  după logare bannerul arată adresa întreagă.
- **Personalul clienților** (decizia OD-39): repetă noul admin doar pe o copie demonstrativă, cu pachetul
  site-ului lor și fără date personale (`incarca.php --demo --pachet=<site>`, pasul 6), sau pe copia
  anonimizată a propriului lor site, într-o sesiune separată; **niciodată pe datele altui client**. Pe
  copie e mereu un singur client (`sterge.php` înainte de altul).

### Codul nou, mai târziu

Prin SSH (dacă ai adus codul cu `git clone`, pasul 2a, sau dacă `stare.php` arată „git pe server: DA”):

```sh
cd ~/domains/<domeniul-verificare>/public_html
git pull --ff-only
PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"   # sau PHP=/opt/alt/php83/usr/bin/php, dacă sodium (CLI) e DA fără -d
$PHP tools/verificare/stare.php
```

Sau hPanel → GIT → **Deploy** (pasul 2b), apoi `$PHP tools/verificare/stare.php`. `.env` și `storage/` nu sunt în git,
deci rămân neatinse. Dacă `git pull --ff-only` spune că nu poate („Not possible to fast-forward”), nu forța nimic:
trimite-ne rândul.

### Dacă ceva nu merge

| Ce vezi | Ce faci |
|---|---|
| `configureaza.php`: „codul nu e în ~/domains/<domeniu>/public_html” | Site-ul e un subdomeniu în dosarul altui site, sau ești în alt dosar. Fă-l în hPanel ca site separat (Websites → Add website) |
| „acces refuzat: utilizatorul sau parola nu sunt bune” | Verifică în hPanel → Databases numele bazei, utilizatorul și că utilizatorul e legat de bază |
| „baza … are N tabele și nu e o copie de verificare” | Ai ales o bază folosită de un site. Creează una nouă, goală |
| „.env există deja” | Site-ul e deja configurat. Pentru altă parolă a porții sau altă bază: `$PHP tools/verificare/configureaza.php --rescrie` (cere din nou totul) |
| `stare.php`: „antetul HTTP Basic ajunge la PHP: NU” | Intri din formularul paginii. Ca browserul să nu mai ceară și fereastra lui: `nano .env` → `VERIFICARE_POARTA_MOD=formular` |
| „Prea multe încercări” (429) | 10 parole greșite în 10 minute de la aceeași adresă: aștepți 10 minute. După o parolă nouă a porții, închide browserul (ține minte parola veche și o trimite singur) |
| Ai uitat parola porții | `$PHP tools/verificare/configureaza.php --rescrie` |
| „nu pot ascunde ce scrii (comanda stty nu merge …)” | Trimite-ne textul. Nu scrie parolele în altă parte |
| După o oprire în mijlocul unei parole nu mai vezi ce scrii (sau Ctrl-C nu mai merge) | Scrie `stty sane` și Enter (literele nu apar cât scrii, dar comanda merge). Ctrl-C la o parolă pune singur terminalul înapoi; asta se întâmplă doar dacă procesul a fost oprit cu forța (de exemplu de găzduire) |
| `stare.php`: „PHP CLI ≥ minimul codului: NU” | Folosești PHP-ul implicit al contului; pune `PHP=/opt/alt/php83/usr/bin/php` (sau versiunea aleasă) |
| `stare.php`: „NU    extensia sodium (CLI)” și „INFO  Lipsește extensia PHP sodium … deși modulul ei e pe server” (sau `configureaza.php` / `incarca.php` / `licenta.php`: „EROARE: lipsește extensia PHP sodium …”) | PHP-ul liniei de comandă nu încarcă singur sodium (așa e `/opt/alt/php83` pe Hostinger). Scrii exact rândul din mesaj, `PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"`, și dai din nou aceeași comandă cu `$PHP`. Procesele pornite de unelte (migrările, comparația calculelor) primesc singure același `-d` |
| „lipsește extensia PHP … (hPanel → PHP Configuration → PHP extensions; sau alt PHP din /opt/alt)” | Modulul nu e nici pe disc: hPanel → Advanced → PHP Configuration → PHP extensions, sau alt PHP din `/opt/alt`; trimite-ne rândul |
| `stare.php`: „NU    extensia sodium (web)” | hPanel → Advanced → PHP Configuration → PHP extensions → bifezi sodium → Save; apoi `stare.php` din nou |
| `stare.php`: „INFO  /robots.txt îl dă CDN-ul Hostinger (server: hcdn), nu site-ul …” | Nimic: pe domeniul temporar CDN-ul nu se poate opri; site-ul rămâne închis (poarta 401, `noindex`). Nu e NU (mai sus, „robots.txt pe domeniul temporar”) |
| `stare.php`: „NU    /robots.txt: „Disallow: /” (îl dă CDN-ul Hostinger (hcdn), dar domeniul nu e unul temporar …)” | Pe un domeniu propriu CDN-ul se poate opri: îl oprești din setările CDN ale site-ului în hPanel, apoi `stare.php` din nou. Alt motiv în paranteză: trimite-ne textul |
| `configureaza.php` sau `stare.php`: „baza nu e a altui site din cont: NU (o folosește …)” | Ai scris numele bazei unui site live. Creează în hPanel → Databases o bază nouă, doar pentru verificare, și `$PHP tools/verificare/configureaza.php --rescrie` |
| `stare.php`: „cheia privată a licenței de verificare …: NU” (sau „LICENTA_CHEIE_PUBLICA … NU”) | `$PHP tools/verificare/configureaza.php --rescrie` (face cheia; restul îl ceri din nou) |
| `stare.php`: un rând NU în „Gărzile copiei” | Nu încărca nicio copie; trimite-ne tot textul (rândul spune unde e problema) |
| `stare.php`: „13. pozele lipsă … NU (VERIFICARE_UPLOADS_DIN lipsește …)” | `nano .env` → `VERIFICARE_UPLOADS_DIN=https://bioscem.ro` (adresa site-ului copiat) |
| Un email al copiei nu vine | `stare.php` arată „emailuri azi: N plecate din 100”: peste 100 pe zi nu mai pleacă niciunul (până mâine). Fără căsuță de trimitere, emailurile sunt fișiere în `storage/verificare/mail/` |
| `licenta.php`: „… rulează doar din dosarul site-ului de verificare” | Ești în alt dosar: `cd ~/domains/<domeniul-verificare>/public_html` |
| `sterge.php`: „baza are N tabele și nu e o copie de verificare” | `.env`-ul arată spre altă bază decât copia. Nu șterge nimic; trimite-ne textul |
| `agregate.php` / `amprenta-schema.php`: „REFUZAT: rulează doar din dosarul site-ului de verificare” | Ești în alt dosar: `cd ~/domains/<domeniul-verificare>/public_html`. Uneltele nu rulează niciodată din dosarul unui site live |
| „REFUZAT: nu găsesc site-ul live …” | Site-ul stă în alt dosar decât cel după domeniu: `ls ~/domains`, apoi aceeași comandă cu `--dosar=~/domains/<domeniu>/public_html` |
| „REFUZAT: găsesc mai multe dosare cu …” | Două dosare au același domeniu în `.env`; alegi dosarul site-ului live cu `--dosar=…` |
| „REFUZAT: … APP_URL are domeniul …, nu …” | Dosarul dat cu `--dosar` e al altui site; verifică domeniul |
| „REFUZAT: legătura cu baza site-ului nu merge (eroarea 1045 …)” | Datele din `.env`-ul site-ului live nu deschid baza de pe acest cont; trimite-ne rândul (nu are parole). La `incarca.php` refuzul vine înaintea oricărei schimbări: copia de dinainte, `.env`-ul și restul rămân cum erau |
| „REFUZAT: … .env nu se poate citi” | PHP-ul nu are voie în dosarul celuilalt site; trimite-ne rândul |
| „PARȚIAL” sau rânduri EROARE / SĂRIT | Trimite-ne fișierul așa cum e; o verificare căzută nu le strică pe celelalte |
| „OPRIT: memoria PHP s-a terminat (memory_limit) …” | Trimite-ne fișierul (are verificările de până acolo). Dacă vrei și restul: aceeași comandă cu `$PHP -d memory_limit=512M tools/verificare/agregate.php --set=…` |
| Un rând „AVERTISMENT PHP: …” | Trimite-ne rândul; verificările merg mai departe (rândul are doar felul și locul erorii în codul uneltei) |
| `incarca.php`: „REFUZAT: lipsește cheia licenței de verificare …” sau „… .env nu are un VERIFICARE_EMAIL bun” | `.env`-ul e de dinainte de WP-08: `$PHP tools/verificare/configureaza.php --rescrie`, apoi `stare.php` și din nou încărcarea |
| `incarca.php`: „REFUZAT: pe copie e deja o încărcare (sursa bioscem, terminată)” | O faci din nou cu `--reincarca`, sau o ștergi întâi cu `sterge.php` |
| `incarca.php`: „REFUZAT: pe copie e deja o încărcare (… oprită la pasul …)” | `--reia` o continuă; `--reincarca` o face de la capăt; `sterge.php` o șterge |
| `incarca.php`: „REFUZAT: regulile de anonimizare s-au schimbat …” sau „… sarea încărcării oprite lipsește” | Codul nou a venit între timp (sau `sterge.php` a șters sarea): `--reincarca`, de la capăt |
| `incarca.php`: „REFUZAT: pe copie e altă sursă (demo) …” | Pe copie e copia demonstrativă: `sterge.php`, apoi încărcarea |
| `incarca.php`: „NU    <tabel>.<coloană> … coloană fără regulă” și „OPRIT: … copia a fost ștearsă singură” | Bioscem are o coloană nouă: trimite-ne textul (are doar numele ei); după ce o trecem în reguli, încarci din nou |
| `incarca.php`: „NU    A1 … adresele sunt în: <tabel>.<coloană> (N)” și „OPRIT: … copia a fost ștearsă singură” | O adresă de email a rămas într-o coloană pe care regulile o lasă cum e (de exemplu un text scris de cineva): trimite-ne textul (are doar numele coloanei și câte adrese); după ce schimbăm regula, încarci din nou |
| `incarca.php`: „ÎNTRERUPT: …” | Rulezi comanda din rând (`--reia`); continuă de unde a rămas |
| `incarca.php`: „OPRIT: … copia NU s-a putut șterge singură” | `$PHP tools/verificare/sterge.php`, apoi trimite-ne textul |
| `incarca.php`: „… privat/setari-inghetate.json lipsește … codul 15 …” | Lipsesc valorile private ale înghețării pachetului (`site-uri/<site>/privat/`): pentru Biovitality le faci pe server (pasul 6b, `genereaza-setari.php --cod-vechi`), pentru celelalte ți le trimitem noi; nimic nu s-a schimbat |
| `compara-calcule.php`: „REFUZAT: copia de verificare lipsește”, „… a expirat”, „copia nu e încheiată” sau „copia e din altă sursă” | Încarci întâi copia Bioscem (pasul 6, c; `sterge.php` înainte dacă pe copie e altceva), apoi comparația |
| `compara-calcule.php`: „REFUZAT: codul vechi lipsește din cod-vechi/bioscem/” sau „.env-ul codului vechi … nu e spre baza copiei” | Încărcarea l-a sărit (`--fara-cod-vechi`), `sterge.php` l-a șters sau a rămas de la altă copie: încarci din nou (`--reincarca`) |
| `compara-calcule.php`: „REFUZAT: pe codul comun nu sunt pornite toate pluginurile site-ului: …” | Ai schimbat pachetul copiei (pasul 7): `$PHP tools/verificare/licenta.php bioscem`, apoi din nou comparația |
| `compara-calcule.php`: „REZULTAT: PICĂ” | Ne trimiți tot textul (spune doar ce câmpuri și ce tipuri de comenzi diferă, fără date). Copia rămâne cum era, cupoanele puse la loc |
| `compara-calcule.php`: „OPRIT: …” | Ne trimiți textul. Dacă spune că cupoanele NU s-au pus la loc, rulezi comanda din nou: întâi le pune la loc |
| `compara-calcule.php`: „… baza copiei era „in_urma”, iar scripts/migreaza.php --aplica … a ieșit cu codul …” | Codul nou are migrări pe care copia nu le are încă și ele n-au mers: `$PHP scripts/migreaza.php --aplica --site=bioscem` și ne trimiți textul lui |
| `amprenta-site.php`: „OPRIT: site-ul cere parola porții” sau „parola porții nu e bună” | `read -rs VERIFICARE_POARTA_PAROLA`, `export VERIFICARE_POARTA_PAROLA` și `--poarta=verificare` (ca la pasul 6, f); după o parolă greșită unealta se oprește singură |
| `amprenta-site.php`: „OPRIT: e o copie de verificare … --gazda-sursa=bioscem.ro” | Comanda copiei din pasul 6, f are și `--gazda-sursa=bioscem.ro`: o rulezi din nou așa |
| `amprenta-site.php --compara`: „REFUZAT: amprentele au alte gazde sursă …” | Una dintre amprente e făcută fără `--gazda-sursa` (sau cu altă gazdă): faci din nou amprenta copiei exact ca la pasul 6, f |
| `amprenta-site.php --compara`: „REFUZAT: … e de altă versiune a amprentei …” | Amprenta e făcută cu o versiune mai veche a uneltei: le faci din nou pe amândouă (pasul 6, f) |
| `amprenta-site.php --compara`: „REZULTAT: DIFERĂ” | Ne trimiți textul și cele două fișiere `.tsv` |
| O comandă (`configureaza.php`, `stare.php`, `migreaza.php`, `install.php`) se oprește cu „PHP Fatal error” sau „PHP Warning” | Trimite-ne rândurile din terminal: erorile PHP ale comenzilor apar acolo (stderr), nu într-un fișier. Ale paginilor sunt în `storage/logs/php.log` al site-ului de verificare, dacă serverul nu are alt jurnal (`$PHP scripts/diagnostic.php` arată unde; WP-07) |

## Cum funcționează (pentru programatori)

### Poarta

`App\Support\Verificare::poarta()` rulează prima în `public/index.php` (înaintea ei doar `App\Support\Paths` alege
jurnalul erorilor PHP), înaintea fișierelor încărcate, a stratului SEO și a sesiunii. Citește `.env`-ul site-ului
(`Paths::env()`; ca `Env::load`, deci `.env` are prioritate față de mediu):

1. **Fără bază configurată** (`DB_DATABASE` gol: nici `.env`, nici variabila în mediu) → **503 „Site
   neconfigurat”**, pentru orice cerere, oricare ar fi restul. Regula e a oricărui site: fără ea, un deploy
   proaspăt ar merge fără bază, cu `Settings::DEFAULTS` (textele Bioscem). Pe un site adevărat `.env` există
   mereu, iar în dezvoltare baza vine din mediu (`tools/dev/comun.sh`), deci acolo nu se schimbă nimic.
2. **`SITE_COPIE_VERIFICARE` diferit de `1`** → nu face nimic (niciun antet, nicio ieșire). Așa e pe orice
   site obișnuit, deci și pe Bioscem: `compara-cu-baza.sh` rămâne fără diferențe.
3. Pe copia de verificare, **pe fiecare răspuns**: `X-Robots-Tag: noindex, nofollow, noarchive`,
   `Cache-Control: no-store, private` și `X-LiteSpeed-Cache-Control: no-cache`. Sunt puse la început și
   din nou chiar înainte de trimitere (`header_register_callback`), peste ce pune aplicația: o pagină văzută
   cu parola nu are voie să rămână în cache-ul CDN-ului, unde ar vedea-o oricine. Cache-ul de pagini al
   aplicației rulează după poartă, deci nici o pagină păstrată de el nu ajunge la cineva fără parolă.
   Fișierele statice din `public/assets` (CSS, JS, icoane din git, fără date) le servește direct serverul
   web, fără antet; `robots.txt` le închide oricum.
4. Fără parolă: `GET /robots.txt` → `User-agent: *` / `Disallow: /`; `GET /health` → `ok` (fără bază, fără
   sesiune) cât nu e încărcată nicio copie (sau copia a expirat). Cu o copie încărcată, `GET /health` îl dă
   aplicația, tot fără parolă, ca pe un site adevărat: `ok`, `schema: <stare>` și `pachet: <amprenta>` (WP-16), iar cu baza în urma
   codului (migrări neaplicate) **503** `schema: in_urma` ([MIGRARI.md](MIGRARI.md), „Garda”). Așa o
   repetiție a deploy-ului pe verificare vede exact ce ar vedea pe site (o verificare după deploy care
   cere 200 întoarce codul la loc dacă `migreaza.php --aplica` n-a rulat). Tot fără parolă, `GET`/`HEAD
   /_andaxi/ping` (pingul monitorizării, `App\Support\Ping`, WP-07): îl dă mereu aplicația, care la el face doar
   `SELECT 1` și citește garda, înaintea oricărui alt cod, deci și pe baza goală dinaintea primei încărcări
   (`"schema":"fara_registru"`, niciun tabel făcut). Un POST pe ping și orice altă adresă `/_andaxi/…` cer parola
   (și `/_andaxi/ping/`, cu „/” la sfârșit: fără parolă trece doar adresa exactă; cu parola, o servește tot pingul).
5. Poarta are nevoie de `VERIFICARE_POARTA_UTILIZATOR`, de un hash valid în `VERIFICARE_POARTA_HASH` și de
   dosarul de lucru; altfel tot 503 „neconfigurat” (motivul îl arată `stare.php`). Excepția: cu
   `VERIFICARE_WEBHOOKURI_DESCHISE=1` (modul de test al plăților, Etapa 2, când copia are doar chei de test)
   un `POST /webhook/<nume>` trece fără parolă, doar cu o copie încărcată și neexpirată (altfel 503); `GET`
   și orice altă cale cer parola. Implicit `0`.
6. Parola: **HTTP Basic**, citit din `PHP_AUTH_USER`/`PHP_AUTH_PW`, din antetul `Authorization`
   (`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`) sau din `getallheaders()`; sau **formularul**
   din pagina 401 (`POST /_verificare/intrare` → cookie `verificare_poarta`, semnat HMAC cu o cheie din
   hash-ul parolei, 12 ore; o parolă nouă anulează cookie-urile). `/_verificare/iesire` șterge cookie-ul.
   În modul `formular` (`VERIFICARE_POARTA_MOD`) pagina 401 nu mai cere și fereastra Basic. Un câmp
   trimis ca listă (`parola[]=…`, un cookie `verificare_poarta[a]`) contează ca gol: tot 401, nimic în
   jurnalul PHP.
7. **10 încercări greșite** de la aceeași adresă (`IpClient::adresa()`) în 10 minute → **429** cu
   `Retry-After`, și pentru parola bună, și pentru formular. O adresă IPv6 contează cu toată rețeaua ei
   /64 (`IpClient::cheieLimita()`): un abonat IPv6 are de obicei un /64 întreg și altfel ar schimba
   adresa la fiecare încercare. Pe disc (`poarta-esecuri.json`) stau doar amprente HMAC, nu adresele.
8. Cu parola bună: dacă `storage/verificare/incarcat-la` lipsește → 503 „Copia de verificare lipsește”;
   dacă e mai veche de `VERIFICARE_ZILE_PASTRARE` zile (cel mult 7, decizia D1), de necitit sau din viitor →
   503 „a expirat”. Așa aplicația nu rulează niciodată pe baza goală (nici `ensure*`, nici erori), iar o
   copie uitată se închide singură. Abia apoi cererea ajunge la aplicație.

### robots.txt pe domeniul temporar (`lib/roboti-gazda.php`)

Pe un domeniu temporar Hostinger (`<nume>.hostingersite.com`) CDN-ul găzduirii răspunde singur la `/robots.txt`, fără
să ajungă la PHP (văzut pe 21 oct 2026, pe site-ul proprietarului): `server: hcdn`, niciun antet al porții, textul
„User-agent: Googlebot / Disallow: /” și „User-agent: * / Allow: /”. Pe domeniul temporar CDN-ul nu se poate opri, deci
`stare.php` nu poate vedea niciodată „Disallow: /” al porții. `RobotiGazda::cdnHostinger()` (pur, fără cereri) spune dacă
e exact acest caz, cu tot ce ține site-ul închis; atunci `stare.php` tipărește un rând INFO (Googlebot oprit de găzduire,
ceilalți roboți 401 la poartă; neconfigurat, 503) în locul rândului robots.txt și garda 4 rămâne DA (cu nota „din afară
/robots.txt îl dă CDN-ul Hostinger”). Condițiile, toate:

1. răspunsul nu e al site-ului: n-are `X-Robots-Tag` și nici `Cache-Control` cu `no-store` (poarta le pune pe **orice**
   răspuns, și pe `/robots.txt` al ei, înaintea aplicației: `forteazaAntete()`);
2. e al CDN-ului Hostinger: `server: hcdn`, codul 200 și exact textul de mai sus (`\r\n` și spațiile de la capăt de rând
   nu contează, orice alt rând da);
3. domeniul site-ului (dosarul `~/domains/<gazda>/public_html`, același cu `APP_URL`) e unul temporar: un singur nume
   înaintea lui `hostingersite.com`;
4. fără parolă, `/` răspunde 401 (neconfigurat: 503), cu `X-Robots-Tag: noindex` și `Cache-Control: no-store`;
5. în cod robots.txt-ul rămâne „User-agent: * / Disallow: /”: al porții (ramura `/robots.txt` din
   `src/Support/Verificare.php`, `RobotiGazda::textPoarta()`; nicio cerere spre `/robots.txt` nu ajunge la ea, deci
   `stare.php` îi citește codul, ca la celelalte gărzi) și al aplicației (`Robots::text()`, garda 4).

Orice altceva rămâne NU, cu motivul în paranteză: site-ul care răspunde cu antetele lui și alt text, alt text al
CDN-ului, alt server, un domeniu propriu (acolo CDN-ul se oprește), poarta deschisă, fără noindex, robots.txt-ul porții
schimbat în cod, `Robots::text()` greșit.

### PHP-ul liniei de comandă și extensiile (`lib/php-cli.php`)

Pe Hostinger `/opt/alt/php83/usr/bin/php` nu încarcă sodium, deși `/opt/alt/php83/usr/lib64/php/modules/sodium.so`
există; `-d extension=sodium.so` îl încarcă (21 oct 2026). Bifa din hPanel → PHP extensions schimbă doar PHP-ul site-ului.
`PhpCli`:

- **refuzul** (`lipsa()`): `configureaza.php`, `incarca.php`, `licenta.php`, `sterge.php`, `agregate.php`,
  `amprenta-site.php`, `compara-calcule.php` și `tools/sonda/amprenta-schema.php`, cărora le lipsește o extensie cerută
  cu modulul în `extension_dir`, spun reparația exactă: `PHP="<PHP_BINARY> -d extension=sodium.so"`, apoi aceeași
  comandă cu `$PHP`; fără modul, hPanel. `stare.php` arată rândul NU și dedesubt același text ca INFO;
- **comanda de dat mai departe** (`comanda()`: „Pasul următor”, „Continuă cu”, „rulează …”): dacă procesul de acum are o
  extensie doar prin `-d` (PHP_BINARY pornit simplu n-o are; o sondă a lui), `PHP="<PHP_BINARY> -d extension=…"; $PHP
  <unealta>`, altfel `PHP_BINARY <unealta>` ca până acum; `stare.php` o spune și ca INFO;
- **procesele copil** (`pentruCopil()`, în `lib/calcule.php`: lucrătorii comparației și `scripts/migreaza.php`): o sondă
  a PHP-ului copilului (o dată pe PHP, cu mediul și felul lucrătorilor: țevi sau fișiere), apoi `-d extension=<nume>.so`
  pentru fiecare extensie din `PhpCli::EXTENSII` (mysqlnd, pdo, pdo_mysql, sodium, mbstring, intl, gd, curl, openssl,
  zip) pe care o are comanda, iar copilul pornit simplu nu, cu modulul în `extension_dir`-ul lui (mysqlnd și pdo
  înaintea lui pdo_mysql); o a doua sondă verifică, iar textul comparației spune într-un rând ce s-a adăugat (fără calea
  PHP-ului). O extensie al cărei modul lipsește din `extension_dir`-ul copilului (de exemplu un `--php-vechi` din
  `/opt/alt` fără pdo_mysql) nu se poate aduce așa: un rând „… în procesele copil: fără pdo_mysql (modulul nu e în
  extension_dir-ul lui, nici cu -d)”, apoi lucrătorul pică (alt PHP, sau extensia bifată pentru el). Altfel `$PHP` (cu
  `-d`) n-ar ajunge la copii: PHP_BINARY e doar executabilul. `tools/inghetare/`
  (generatorul înghețării) pornește și el PHP_BINARY, dar rulează doar local, cu `tools/dev/php.sh`.

### Sonda (`/_verificare/sonda`)

`stare.php` scrie în `storage/verificare/sonda.json` amprenta unui jeton aleator (expiră în 2 minute) și
cere `/_verificare/sonda?sonda=<jeton>` cu acel jeton și ca parolă Basic, cookie și antet propriu. Poarta
răspunde, o singură dată, cu ce vede PHP-ul web: versiunea și SAPI, pe unde a sosit Basic (sau deloc), dacă
au sosit cookie-ul și antetul, clasa lui `REMOTE_ADDR` (loopback, privată, CGNAT, publică), dacă sosesc
`X-Forwarded-For`/`X-Real-IP`/`CF-Connecting-IP`/`Forwarded` (pentru ultima adresă din `X-Forwarded-For` clasa ei, fără port
doar la `a.b.c.d:port` și `[v6]:port`, ca în `IpClient`; ea și `X-Real-IP` față de `REMOTE_ADDR` doar ca DA/NU), HTTPS, `max_execution_time`, `memory_limit`,
funcțiile oprite, extensiile, `imagewebp` și cele 14 gărzi ale copiei, cum le vede PHP-ul site-ului (doar DA/NU,
`Verificare::garzi()`). Fără jetonul bun, adresa e tratată ca oricare alta (401).

### Gărzile copiei (WP-08)

Al doilea strat, după neutralizarea setărilor la încărcare (WP-09, planul §3.5.2): chiar dacă în baza copiei a
rămas o setare a site-ului adevărat (o cheie Stripe live, adresa ERP-ului, SMTP-ul, un ID Google Analytics), codul
nu trimite nimic în afară. Fiecare gardă e un rând la locul din cod prin care trece tot (planul Etapei 1, §4.1:
„one-line guard calls at chokepoints”) și, fără `SITE_COPIE_VERIFICARE=1`, întoarce exact ce era și înainte, deci
un site obișnuit (și Bioscem: `compara-cu-baza.sh`) nu observă nimic. Merg la fel pentru pagini și pentru linia
de comandă (cronurile citesc același `.env`).

| # | Garda | Locul din cod | Pe copie |
|---|---|---|---|
| 1 | Poarta | `Verificare::poarta()`, prima în `public/index.php` | HTTP Basic sau formularul; 10 parole greșite → 429 (mai sus) |
| 2 | noindex | tot poarta | `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: no-store, private` pe fiecare răspuns |
| 3 | Expirarea | tot poarta (`stareDinRand`) | copia mai veche de `VERIFICARE_ZILE_PASTRARE` zile (cel mult 7, D1) → 503 |
| 4 | robots.txt | poarta și `Robots::text()` | `User-agent: *` / `Disallow: /`, fără `Allow:` pentru fluxul de produse și fără `Sitemap:`; pe domeniul temporar Hostinger, din afară îl dă CDN-ul (rândul INFO al lui `stare.php`, „robots.txt pe domeniul temporar”) |
| 5 | Emailurile | `OrderMailer::sendHtmlEmail` (singurul drum: comenzi, conturi, newsletter, coșuri abandonate, BT, contact) → `Verificare::trimiteEmail()` | doar la `VERIFICARE_EMAIL`, subiectul cu „[VERIFICARE] ”, la început un rând „pe site, acest email ar fi plecat la …” (adresa doar dacă e `@verificare.invalid`, altfel „o adresă din afara copiei”); prin căsuța copiei (`VERIFICARE_SMTP_*`; SMTP-ul, SendGrid-ul și expeditorul din setări nu se folosesc) sau, fără ea, fișier `.eml` 0600 în `storage/verificare/mail/`; cel mult 100 pe zi (`emailuri-azi.json`), peste ele nu pleacă (excepție: „eșuat, sărit” în istoricul emailurilor) |
| 6 | ERP | `ErpClient::fromSettings` și `ErpClient::request` | null (oprit), afară de o copie de verificare a ERP-ului: `VERIFICARE_ERP_URL` https pe `*-verif.erp.andaxi.ro` cu `VERIFICARE_ERP_CHEIE` (D5); adresa și cheia din setări nu se folosesc niciodată |
| 7 | FAN Courier | `FanCourierGateway::createInternalAwb` și `rawRequest` | niciun AWB și nicio cerere (tarif live, urmărire, lockere, nomenclator), afară de `VERIFICARE_FAN_TEST=1` (un cont FAN de test, D6); checkout-ul cu tariful live pornit refuză comanda cu motivul |
| 8 | Stripe | `StripeGateway::request` | doar o cheie `sk_test_…`; altfel plata nu pornește (înapoi la checkout, cu motivul) |
| 9 | Banca Transilvania | `BtIpayGateway::mod()`, `credentiale()`, `apel()` | mereu modul de test; datele `BT_IPAY_LIVE_*` nu se citesc, un apel „live” nu pleacă; BT nu apare în checkout (doar „Plată de test 1 leu” din admin) |
| 10 | EuPlătesc | `EuPlatescGateway::isEnabled` și `buildRequest` | oprit, afară de `VERIFICARE_EUPLATESC_TEST=1` (un cont de test, D4) |
| 11 | Etichetele terților | `views/layout.php` (`$acceptaUrmarire`, `google-site-verification`) și `ChatLive::activ` | fără Google Analytics, Tag Manager, Google Ads, Clarity, tawk.to și verificarea Google, orice ar scrie în setări și chiar cu „Accept toate” |
| 12 | Login cu Google | `SiteController::googleOAuthSettings` | oprit (`/auth/google` → `/login`) |
| 13 | Pozele | `public/index.php` (fișierele încărcate) și `ImagineDistribuire` (`/og/…`) → `Verificare::redirectUploadLipsa()` | pozele nu se copiază: una care lipsește → 302 spre `VERIFICARE_UPLOADS_DIN` + aceeași cale (doar căi `/uploads/…`, `/og/…` fără „..”, „//”, „\\”, caractere de control); paginile arată la fel, fără inoduri folosite și fără fișiere cu date personale pe copie |
| 14 | Bannerul | `views/layout.php` și `views/admin/layout.php` → `Verificare::banner()` | primul în pagină: „SITE DE VERIFICARE — date anonimizate, plăți de test, emailurile ajung doar la …” (pe site și pe paginile adminului de dinaintea logării, ca `/admin/login`, adresa ascunsă pe jumătate, D8; întreagă doar cu adminul logat: layout-ul adminului dă `banner('admin')` doar după `Auth::check()`); `id="verificare-banner"`, ca o comparație a paginilor cu site-ul adevărat (WP-11) să-l scoată |

Din fluxurile aduse din Bioscem în WP-R: fluxul Google Merchant (`/feed/google-merchant.xml`) e în spatele porții
(Google primește 401, robots.txt nu-l mai lasă), iar blocul avertismentului pentru produsele refrigerate din
emailurile comenzii pleacă doar la proprietar, ca orice email (garda 5).

Verificarea lor pe server e în `stare.php`, secțiunea „Gărzile copiei”: fiecare gardă de trei ori, cum o vede
linia de comandă (`Verificare::garzi()`), cum o vede PHP-ul site-ului (sonda) și la locul ei din cod, chemat cu
date inventate (un email simulat prin drumul obișnuit al aplicației, cu SendGrid și un SMTP inventat în setări;
`ErpClient` spre o adresă inventată; un AWB și un tarif FAN; o cheie Stripe `sk_live_` inventată; BT cu
`BT_IPAY_MODE=live`; EuPlătesc pornit; tawk.to pornit). Pentru gărzile din șabloane și din controller (11–14 și
redirecționarea pozelor) se verifică și că fișierul deployat are chemarea gărzii. Cât le cheamă, procesul are
proxy-ul spre `127.0.0.1:9`: chiar o gardă lipsă nu poate trimite nimic în afară. Licența (check-in-ul la CRM)
vine cu sincronizarea licenței (WP-27, Etapa 2), care pe copie rulează doar cu `LICENTA_CRM_URL` completat.

### Licența de verificare

`configureaza.php` face pe server o pereche de chei Ed25519: cheia privată în
`storage/licenta/verificare-cheie-privata.txt` (0600, prin fișier temporar și `rename`; cu gardul dosarului), cea
publică în `.env` (`LICENTA_CHEIE_PUBLICA`), cu `LICENTA_SITE=verificare`. La `--rescrie` rămâne cheia de dinainte
(o licență pusă cu ea rămâne bună). `tools/verificare/licenta.php <pachet> [plugin=stare …]` construiește pachetul
din catalogul codului deployat (`config/plugins.php`: `licente_dev` — `bioscem`, `biovitality`, `vertij`,
`grafoanaytis` —, `pachete` — `prezentare`, `catalog`, `magazin`, `asociatie` — și `toate`), exact ca
`tools/dev/licenta.php`, deci nu rămâne în urma catalogului. Înainte să pună licența șterge `licenta-buna.json` și
`stare-aplicata.json`, ca orice pachet să poată urma oricărui alt pachet; apoi, pe o copie încărcată și la zi cu
migrările, rulează pașii de activare ai pluginurilor (ca o licență nouă de la CRM). Refuză în afara dosarului
site-ului de verificare, fără `SITE_COPIE_VERIFICARE=1`, cu un `LICENTA_SITE` care nu începe cu „verificare” sau cu
o cheie care nu e perechea celei din `.env`. Niciun site adevărat nu are cheia publică de aici, iar verificarea
`site` respinge licențele ei oriunde altundeva (riscul 9 al planului de verificare). În Etapa 2 copia primește un
rând în CRM și sincronizări adevărate.

### Ștergerea (`sterge.php`)

`tools/verificare/sterge.php` (cu `--da`, fără întrebare) șterge din baza copiei toate tabelele, vederile,
rutinele și evenimentele (`_verificare` ultimul: o ștergere oprită la jumătate lasă tot o copie de verificare,
pe care o termină rularea următoare), apoi `incarcat-la`, `storage/verificare/mail/` și `tmp/`, `storage/cache/`
(paginile, fluxul de produse, pozele de distribuire), sesiunile (`sess_*`) și `~/domains/<verificare>/cod-vechi/`
(fără să urmeze o legătură: o legătură se șterge ea însăși), pozele copiei demonstrative (`public/uploads/demo/`) și,
din `tmp/`, sarea unei încărcări neterminate. Păstrează `.gitkeep`, `.gitignore` și gardurile
`.htaccess` (sunt ale codului), `.env`, licența și cheia, contorul emailurilor zilei, jurnalele și
`storage/verificare/sonde/` (pe care le amintește). Tipărește doar numere. Refuză, fără să șteargă nimic: alt dosar
decât `~/domains/<gazdă>/public_html`, un `.env` fără `SITE_COPIE_VERIFICARE=1` sau fără `DB_DATABASE`, o bază cu tabele fără
`_verificare` sau scrisă în `.env`-ul altui site din cont (`tools/verificare/lib/cont.php`, ca la `configureaza.php`,
`stare.php` și `licenta.php`), un răspuns care nu e „da”.

**Înțelegerea cu încărcarea (WP-09).** `sterge.php` recunoaște copia doar după tabelul `_verificare`, deci
încărcarea îl scrie **primul**: imediat după verificarea că baza țintă e goală (sau are deja `_verificare`) și după
întrebări, înaintea oricărui `CREATE TABLE` sau `INSERT` cu date, cu `anonimizat = 0` (și pasul la care a ajuns,
pentru `--reia`); abia la sfârșit, după aserțiuni și licență, pune `anonimizat = 1` și scrie `incarcat-la`
(`stare.php` arată „NU” la „copia e încheiată și anonimizată” până atunci). Așa o încărcare oprită la jumătate (procesul
omorât de găzduire, o eroare înaintea ștergerii automate) rămâne o copie de verificare: poarta arată tot „Copia de
verificare lipsește” (fără `incarcat-la`), iar `sterge.php`, `incarca.php --reincarca` sau `--reia` o pot lua de la
capăt fără phpMyAdmin. `sterge.php` nu se uită la `anonimizat`: orice bază cu `_verificare` se șterge.

### Încărcarea copiei (`incarca.php`, WP-09)

`tools/verificare/incarca.php` verifică doar PHP-ul (sintaxă 7.0: versiunea, `pdo_mysql`, `sodium`) și încarcă
`tools/verificare/lib/incarcare.php` (`Reguli`, `Transformari`, `SursaCopie`, `Copiere`, `Incarcare`). Locul și
refuzurile sunt ale celorlalte unelte (`Locul::verificare`: dosarul `~/domains/<gazdă>/public_html`, `HOME`,
`SITE_COPIE_VERIFICARE=1`); în plus `.env` trebuie să aibă `DB_DATABASE`, un `VERIFICARE_EMAIL` bun, `LICENTA_SITE`
„verificare…” și cheia publică a perechii din `storage/licenta/`. Baza copiei nu e niciodată baza sursei sau a altui
site din cont (verificat înainte de conexiune) și e goală sau are `_verificare`.

**`--din=<site>`** (`bioscem` și, din WP-11b, `biovitality`: `tools/verificare/reguli/<site>.php`), în ordine:

1. **Sursa**: `SiteLive::gaseste` (ca `agregate.php`; `--dosar=` pentru alt dosar). Din `.env`-ul ei se iau doar
   `APP_URL` și `DB_*` (și, decizia D3, cele trei chei `BT_IPAY_TEST_*`, copiate în `.env`-ul copiei doar dacă acolo
   sunt goale; `BT_IPAY_LIVE_*` nu se citesc). Commit-ul codului ei (din `.git`, fără comanda git) intră în
   `_verificare.cod_sursa_commit`.
2. **Întrebările** (înaintea oricărei schimbări): „da” (`--da` o sare), adresa și parola contului de admin al
   proprietarului (cel puțin 10 caractere, poate fi aceeași cu parola porții, de două ori).
3. **Sesiunea sursei**, deschisă tot înaintea oricărei schimbări (după întrebări, ca o legătură să nu stea deschisă cât
   scrie proprietarul; nu citește niciun rând): `SET SESSION TRANSACTION READ ONLY` (verificat cu
   `@@transaction_read_only`, altfel nu se citește nimic), REPEATABLE READ, `time_zone = '+00:00'` (pe ambele baze:
   TIMESTAMP-urile trec neschimbate), așteptarea după un lacăt 5 s, fără limita pe instrucțiune. O legătură care nu
   merge sau o sesiune care nu trece în modul doar de citire e un refuz (cod 2): copia de dinainte, `.env`-ul și sarea
   rămân cum erau (și la `--reincarca`). Doar SELECT și SHOW (o singură instrucțiune, fără comentarii și fără cuvintele
   unei scrieri sau ale unui lacăt; `SHOW CREATE TABLE` doar în forma exactă). Vederile, declanșatoarele, rutinele și
   evenimentele opresc încărcarea (o copie nu le ia).
4. **`_verificare` primul** (`anonimizat = 0`, `pas`, versiunea regulilor) și sarea încărcării (32 de octeți aleatori,
   `storage/verificare/tmp/sare-incarcare`, 0600, cât încărcarea nu e terminată: `--reia` dă aceleași pseudonime);
   `.env`: `SITE_SLUG`, `SITE_DATE_DOSAR` (pachetul site-ului), `VERIFICARE_SURSA`, `VERIFICARE_UPLOADS_DIN` (pozele
   lipsă spre site-ul copiat), cheile de test BT (D3), atomic.
5. **Scanerul** (fail-closed), înaintea oricărui rând: fiecare coloană a fiecărui tabel copiat trebuie să fie în
   reguli (`pastreaza` sau `transforma`), oricare i-ar fi numele; una nouă oprește totul, cu numele ei pe ecran (și
   „nume de date personale” dacă se potrivește `CitireLive::TIPAR_PERSONAL`). Un tabel fără reguli primește doar
   structura (și e numit pe ecran), la fel cele `doar_structura` (jurnalele, resetările de parolă, acordurile GDPR,
   coșurile abandonate, linkurile de confirmare a adresei și evenimentele Stripe primite, WP-25; trimiterile cererilor
   de ofertă, WP-26). Cererile de ofertă (`quote_requests`, WP-26) se anonimizează ca mesajele de contact. Tabelele de lucru FAN (`fan_*__sync`, `__vechi`) iau regulile tabelului lor.
6. **Structura**: `SHOW CREATE TABLE` al sursei, cu `sql_mode`-ul ei.
7. **Datele, grup cu grup** (`grupuri` din reguli: setări, conținut, catalog, blog, FAN, comenzi, recenzii,
   newsletter, mesaje, admini): un grup = un instantaneu al sursei (`START TRANSACTION WITH CONSISTENT SNAPSHOT,
   READ ONLY`, fără lacăte pe rândurile site-ului) și o tranzacție a copiei, în care intră și marcajul (grupul gata,
   numerele). Rândurile se citesc fără buffer (memoria ține un rând) și se scriu în loturi de cel mult 500 de rânduri,
   4 MB și 60.000 de parametri; datele personale se schimbă în PHP, între citire și scriere. În același instantaneu:
   numărătoarea sursei = rândurile citite = numărătoarea copiei, pe fiecare tabel (A7). Un grup oprit nu lasă nimic;
   `--reia` îl ia de la capăt (îi golește întâi tabelele). `--buget=<secunde>`: după el nu mai începe niciun grup.
8. **Setările** neutralizate (lista `setari` din reguli, planul de verificare §3.5.2, plus adresele IP ale
   mentenanței, contul colector al rambursurilor, persoana și telefonul expeditorului FAN și, din WP-18, reprezentantul și
   telefonul operatorului de pe acordul GDPR, valori private ale înghețării; emailul lui devine adresa proprietarului): în drum și încă o dată
   peste tot tabelul (cheile lipsă se scriu). Golite: cheile, parolele, id-urile conturilor externe (și orice cheie cu
   `password`, `secret`, `api_key`, `_token`, `client_secret`); `0`: plățile, ERP-ul, AWB-ul automat, tariful FAN live,
   login-ul Google, etichetele terților; adresa proprietarului: destinatarii emailurilor; căsuța copiei: expeditorul;
   „VERIFICARE ” în fața numelui expeditorului; o cheie de mentenanță nouă. Lista albă (fail-closed, ca la coloane): o
   cheie a sursei pe care nu o neutralizează lista și nu o știe codul (cel comun și al site-ului: `Settings::DEFAULTS` și
   restul cheilor folosite, în `tools/verificare/reguli/setari-cunoscute.php`, generat de
   `tools/dev/genereaza-setari-cunoscute.php`) oprește încărcarea înaintea oricărui rând (cod 1, doar numele ei), și încă o
   dată pe rândul ei, dacă a apărut între scaner și copiere. O cheie știută care arată a secret trebuie să fie neutralizată
   (altfel regulile se refuză).
9. **Contul proprietarului**: administrator general, bcrypt. Adminii site-ului au deja `admin<id>@verificare.invalid`
   și o parolă bcrypt aleatoare (din reguli).
10. **Codul vechi** în `~/domains/<verificare>/cod-vechi/<site>/` (0700, fișierele 0600, în afara `public_html`, fără
    cron): doar extensiile de cod, fără `.env*`, `.git`, `storage/` (dosarele rămân, goale), `public/uploads/`,
    sitemap-uri, jurnale, arhive, exporturi și copii de baze (`*.sql` doar sub `database/`, sub 2 MB; `.json`, `.xml`,
    `.txt` doar din dosarele codului, `src/`, `views/`, `config/`, `database/`, `scripts/`, `vendor/`, și cele știute ale
    rădăcinii și ale `public/`, ca `composer.json` sau `robots.txt`: un `export.xml` WordPress sau un export JSON al
    utilizatorilor lăsat în dosar nu pleacă); legăturile nu se urmează. Lângă el, un `.env` doar cu baza copiei și BT în test (pentru comparația calculelor, WP-11).
    `--fara-cod-vechi` sare pasul.
11. **Migrările**, în proces, ca `scripts/migreaza.php --plan`, `--aplica`, `--verifica` cu `--site=<pachet>`
    (`--permite-provizoriu`, care are efect doar aici): repetiția adoptării; textul runner-ului intră în raport (fără
    numele bazei). Garda trebuie să spună `la_zi`.
12. **Verificările A1–A9** (doar numere; oricare pică: copia se șterge singură, cod 1). A1: emailurile pseudonimizate
    toate `@verificare.invalid` și, în orice coloană de text a tabelelor care nu sunt publice, nicio adresă din altă
    parte (contul proprietarului nu se numără); când pică, numește fiecare `tabel.coloană` cu câte adrese (nu adresele),
    ca textul trimis de proprietar să arate unde e. Tabelele publice (`emailuri_publice`, cu motivul: paginile, produsele,
    articolele, care au adresa magazinului) nu se caută; recenziile aprobate se caută, iar adresele din textul lor
    (scrise de clienți) devin pseudonime la încărcare, deci A1 rămâne strictă și acolo; A2: tabelele „doar structura”, cele fără reguli și cele cu date
    personale ale planului (`Incarcare::FARA_RANDURI`, oricare ar fi regulile) fără niciun rând; A3: telefoanele (după
    reguli și după nume) `07` + 8 cifre; A4: IP-urile, browserele, `google_id`, răspunsurile băncii și celelalte coloane
    golite: NULL; A5: parolele clienților și ale adminilor bcrypt; A6: setările neutralizate (socotite din listă, nu cu
    transformarea); A7: numărătorile; A8: niciun jeton al linkurilor de plată egal cu unul al sursei (ținute minte la
    citire, ca sha256), toate 64 hex diferite; A9 (în plus față de plan): niciun cod unic de cupon al sursei rămas, nici
    în cupoane, nici în comenzi.
13. **Licența** pachetului din reguli (`licenta`, sau `--licenta=`), prin `LicentaCopie` (ca `licenta.php`), apoi
    pașii de activare ai pluginurilor.
14. **Final**: `anonimizat = 1`, `incarcat_la`, `storage/verificare/incarcat-la`, sarea ștearsă; ultimul rând „GATA”.

**Pseudonimele** (H = HMAC-SHA256 cu sarea încărcării): emailul `u` + 12 hex din H(adresa cu litere mici) +
`@verificare.invalid` (aceeași adresă, același pseudonim în orice tabel); telefonul `07` + 8 cifre din H(cifre);
numele de familie 6 hex din H(email); „Client …”/„Abonat …”/„Firma … SRL” + 6 hex din H(valoare) (așa migrarea
recenziilor vechi potrivește `reviews` cu `product_reviews` ca pe site-ul adevărat); adresa „Strada Verificare N”,
codul poștal `000000`; textele libere „[text anonimizat, N caractere]”, și eticheta adresei din contul clientului
(„Acasă”, dar și „La mama, …”); recenziile aprobate, publice, rămân, cu fiecare adresă de email din text schimbată cu
pseudonimul ei;
referințele plăților `anon-<id>`; jetoanele 64 hex noi; codurile unice de cupon `VERIF-<id>` (și în comenzi); AWB-urile
`DEMO<id>` (și în lista coletelor, cu adresa de urmărire refăcută). Orașele, județele, sumele, stările, datele și
id-urile rămân. Fiecare regulă e în capul `tools/verificare/reguli/bioscem.php`; `versiune` crește la orice schimbare
(o încărcare începută cu altă versiune nu se continuă).

**Newsletterul** (reguli v11, WP-R din 30 oct, Bioscem `e2e2d77` … `739f58c`): lista „nu trimite” (`newsletter_suprimate`, doar
amprenta SHA-256 a adresei unui abonat șters: o adresă cunoscută se poate verifica împotriva ei, deci e o dată personală) și
jurnalul cererilor de confirmare (`newsletter_cereri_confirmare`: amprenta adresei, IP-ul) **doar structura**, și în
`Incarcare::FARA_RANDURI` (fail-closed); `pending_at`, `confirmed_at` și `attempts` copiate, motivul opririi unei campanii
(`last_error`, poate fi răspunsul serverului de email, cu o adresă) anonimizat. Setările: cheia de semnare a linkurilor
(`newsletter_cheie_semnare`) golită — copia își face alta la prima semnare (`Settings::secretGenerat`), deci linkurile din
newsletterele site-ului adevărat nu merg pe copie —, pragul cheii vechi (`newsletter_cheie_limita`) „0” (niciun link vechi
crezut), nota limitei globale și ora ultimei rulări a cronului golite.

**Biovitality** (WP-11b, `tools/verificare/reguli/biovitality.php`): regulile Bioscem (fișierul îl include, deci o regulă
Bioscem schimbată se aplică și aici; `versiune` = cea Bioscem × 100 + a Biovitality, deci orice schimbare oprește un `--reia`),
plus ce are în plus codul Biovitality la `779e0d8` (`tools/dev/migrari/vechi/bv.sql`): **doar structura** pentru
`admin_app_tokens` (jetoanele aplicației Windows), `bunny_protected_folders` (parolele dosarelor din cloud), `bunny_folder_unlocks`,
`file_locks` și `file_versions` (jurnalele fișierelor din cloud: emailul adminului, nota, căile documentelor); **copiate** cum
sunt concurenții și prețurile lor (`competition_partners`, `competition_prices`), etichetele blogului (`blog_tags`,
`blog_post_tags`), `products.bundle_items_json`, `blog_posts.references_html` și `featured_image_width`; id-urile Stripe ale
comenzilor `anon-<id>`, procesatorul linkurilor de plată păstrat (vechile linkuri primesc la migrare procesatorul Stripe din
`site-uri/biovitality/migrari.json`), clienții importați din WordPress cu parola înlocuită, ca toate parolele. Setările: în plus
**fișierele din cloud oprite** (`bunny_enabled` = 0) și fără nicio cheie de acces: zona, parola ei, regiunea, adresa CDN,
dosarul de bază, cheia API și orice cheie `bunny_*` nouă (tiparul golește toate cheile `bunny_*` în afara celor de configurare
știute: `timeout`, `upload_target`, dosarele, `lock_expire_hours`, `versions_keep`, `app_download_path`), ca o copie să nu
atingă niciodată fișierele adevărate. Licența copiei: `licente_dev.biovitality`; migrările cu `--site=biovitality` (înghețarea
Biovitality, provizorie: pe copie merge cu `--permite-provizoriu`, pe care încărcarea îl dă). O singură sursă odată: pe o copie
cu altă sursă, `--din=biovitality` (și `--reincarca`) refuză până la `sterge.php`. Copia demonstrativă `--demo
--pachet=biovitality` neutralizează setările după aceleași reguli.

**`--demo [--pachet=<site>] [--licenta=<pachet>]`** (G-M9, OD-39): nicio bază sursă nu se deschide. Pe baza goală (doar
`_verificare`, sursa `demo`; `Runner::paznic` și `Amprenta::ignorat` nu-l numără) rulează migrările cu pașii
pachetului, seed-ul neutru, setările implicite, `database/seed.sql` și `tools/dev/date-demo.php` (care pe un site îi
cere copiei de verificare `_verificare` cu sursa `demo` și `anonimizat = 0`, constanta `ANDAXI_DEMO_PE_VERIFICARE`);
adresele `@exemplu.ro` devin `@verificare.invalid` în orice coloană de text, personalul (3 admini cu rol parțial) și
clienții inventați primesc parola personalului (întrebată, alta decât a proprietarului), setările se neutralizează ca
mai sus, apoi contul proprietarului, A1, A3, A5, A6, licența (implicit `licente_dev` al pachetului, altfel `toate`).
Nu se continuă cu `--reia` (se face din nou cu `--reincarca`).

**Pe ecran** doar DA/NU, numere, nume de tabele și de coloane, durate; numele bazei copiei și al utilizatorului ei se
înlocuiesc. Textul se salvează și în `storage/verificare/sonde/incarcare-<sursa>-<data>.txt` (0600), înainte de
ultimul rând. **Cod de ieșire**: 0 „GATA”; 1 „OPRIT”, copia ștearsă singură (baza, `incarcat-la`, codul vechi,
sarea, fișierele copiei); 2 refuzat, nimic atins (toate refuzurile vin înaintea primei schimbări; un refuz venit totuși
după ea devine „OPRIT”, cod 1, cu ștergerea copiei); 3 „ÎNTRERUPT” (bugetul, legătura pierdută, o eroare fatală PHP),
copia neterminată rămâne închisă pentru `--reia` sau `sterge.php`.

### Comparația calculelor (`compara-calcule.php`, WP-11)

Golden master-ul din planul de verificare (§2.3, G2): pentru aceleași comenzi și aceeași bază, **codul vechi al site-ului
și codul comun trebuie să calculeze aceleași sume**. Nu e o comparație cu totalurile salvate: sumarul coșului citește
prețurile, perioadele de reducere și cupoanele de acum, deci un total de acum o lună poate diferi pe drept (potrivirea cu
totalul salvat e doar informativă).

- **Unde rulează.** Pe copia de verificare: `Locul::verificare` (dosarul `~/domains/<gazdă>/public_html`, `HOME`,
  `SITE_COPIE_VERIFICARE=1`), baza din `.env` nescrisă în `.env`-ul altui site din cont (`Cont::bazaAltuiSite`), `SITE_SLUG`
  = profilul, `storage/verificare/incarcat-la` neexpirat și `_verificare` cu `anonimizat = 1`, sursa profilului și
  `incarcat_la` în ultimele `VERIFICARE_ZILE_PASTRARE` zile. Codul vechi e `~/domains/<verificare>/cod-vechi/<profil>/`
  (copiat de încărcare, pasul 10; nu o legătură), cu `.env`-ul lui spre aceeași bază. În dezvoltare, doar cu `--local`
  (fără `.env` în niciun cod, `CMS_DEV_DIR` în mediu, o bază `cms_*` pe calculator, fără `SITE_COPIE_VERIFICARE=1`; codul vechi
  dat cu `--cod-vechi`, codul comun cel de aici sau `--cod-nou`, pentru mutațiile testului). **Niciodată pe o bază live,
  oricum ar fi pornit:** ambele coduri trimit DDL chiar în calculul verificat (`CheckoutCalculator::fetchProducts` →
  `ensureProductVatSchema`, `Precomanda::ensureSchema`; pe codul vechi și `ErpSync::ensureSchema`), deci nu există nicio
  opțiune pentru așa ceva. Profilul (`--profil=`, implicit `bioscem`; din WP-11b și `biovitality`) e site-ul copiat: alege
  copia, codul vechi și pluginurile de pornit; ce are Biovitality altfel (Stripe ca plată cu cardul, numerele „BV…”, transportul
  gratuit pe produs, codurile de cupon cerute cu spații, coletele neîmpărțite după greutate din înghețare) vine din baza copiei
  și din codul fiecăruia, deci se compară ca orice caz.
- **Migrările.** Cu baza în urma codului comun (cod nou urcat după încărcare: garda spune `in_urma`), comanda rulează
  întâi `scripts/migreaza.php --aplica --site=<profil>`, iar după el garda trebuie să spună `la_zi`; altă stare (fără
  registru, o eroare a gărzii: nu e o copie lăsată de încărcare) și, local, orice stare în afară de `la_zi` e refuz.
- **Cazurile** (o interogare, pe baza copiei): ultimele `--n` comenzi (implicit 500; fără cele șterse și cele `failed`),
  plus fiecare comandă din ultimele 12 luni cu cupon, puncte, o linie cu ofertă BBD sau FANbox (cel mult 300). Fiecare are
  un tip, din ce are: `cupon_procent`, `cupon_fix`, `cupon_inexistent`, `puncte`, `bbd`, `fanbox`, `firma`, `judet_bucuresti`
  (sau `simplu`). Cu profilul `biovitality`, comenzile cu un produs-pachet (`products.bundle_items_json` nevid) se scot dintre
  cazuri și se numără (`excluse: pachete N`): pluginul `pachete_produse` vine în Etapa 4, deci acolo s-ar compara două coduri
  care tratează pachetul diferit prin construcție; ele se compară în repetiția din Etapa 4. Pe o bază fără coloană, profilul
  refuză (nu e o copie Biovitality).
- **Diferențele declarate pe profil** (`ComparaCalcule::DECLARATE_PROFIL`, aceleași local și pe server, pe clase, nu pe
  comandă; fiecare îngustă, numărată pe ecran și scrisă în `site-uri/<profil>/ce-se-schimba.md`). Biovitality: (1) **produsele
  marcate epuizate** se scot din coșul cazului, în ambele coduri: codul Biovitality nu le pune în sumar (`fetchProducts` cu
  `out_of_stock = 0`), codul comun (din Bioscem) le păstrează și oprește finalizarea, deci aceeași comandă veche, socotită cu fișa
  de acum, ar diferi prin construcție (`produse epuizate scoase din coș, în ambele coduri: N linii în M cazuri`); (2) **cheile ERP
  noi** ale codului comun (`facturareAdresa`, `facturareOras`, `facturareJudet`, `facturareCodPostal`, `observatiiInterne`)
  nu se compară doar când lipsesc din payload-ul vechi și sunt în cel nou (`payload ERP: chei noi ale codului comun …`); o cheie
  pe care o trimit amândouă se compară mereu. În plus, lucrătorul face pentru codul Biovitality ce face adminul lui la recalculul
  transportului: codul Biovitality pune transportul gratuit al produsului în `AdminController`, după `adminRecalcShipping`
  (`comandaAreTransportGratuit`), codul comun în funcție (al șaptelea argument), deci `transport_admin` se compară ca rezultat
  al adminului, nu al funcției.
- **Lucrătorii** (`lucrator-calcule.php --cod=<rădăcina>`): câte un proces pentru fiecare cod, pe rând; codul vechi pe PHP-ul
  dat cu `--php-vechi` (de exemplu PHP-ul lui live, `/opt/alt/php81/usr/bin/php`; implicit același), codul comun pe PHP-ul
  comenzii (cel puțin `php_min`). Întâi doar „verifica”: garda, versiunea, pluginurile codului comun (cele din
  `licente_dev.<profil>` trebuie să fie toate pornite, altfel refuz: comparația ar număra ca diferență ce oprește licența) și
  amprentele setărilor din care se fac calculele (informativ: aceleași valori în ambele coduri?). Lucrătorii și migrările
  primesc aceleași extensii ca comanda: unde PHP-ul lor pornit simplu nu încarcă una (pe Hostinger, sodium; comanda pornită
  cu `$PHP`, adică cu `-d extension=sodium.so`), `-d extension=<nume>.so`, după o sondă pe PHP (`lib/php-cli.php`, „PHP-ul
  liniei de comandă și extensiile”), cu un rând „extensiile PHP-ului … în procesele copil: … doar cu -d …” (sau „… fără …”,
  când modulul lipsește din `extension_dir`-ul copilului). Fiecare lucrător verifică,
  după ce codul lui s-a legat la bază și înaintea oricărui cod al aplicației, că e chiar copia așteptată (același nume de
  bază, același rând `_verificare`; local, același nume `cms_*`). Mediul: pe server doar `PATH`, `HOME`, `LANG`; cererile spre
  internet spre `127.0.0.1:9`, `allow_url_fopen=0`; pe copie gărzile (`SITE_COPIE_VERIFICARE=1`) și setările neutralizate la
  încărcare opresc oricum ERP-ul, FAN-ul și plățile. Sesiunea: un handler de sesiune gol, pornit înainte de `bootstrap.php`
  (al Bioscem ar porni altfel o sesiune și în linia de comandă), deci niciun fișier de sesiune; comanda verifică după fiecare
  rulare dosarele `storage/sessions` ale ambelor coduri. Datele: prin țevi (`proc_open`) sau, fără el (ori cu
  `--prin-fisiere`), prin fișiere 0600 în `storage/verificare/tmp/`, șterse la final; numele bazei nu apare în linia de comandă.
- **Ce se compară**, pentru fiecare caz, prin API-ul public al codului, ca un client în checkout (`Cart::clear()`, `Cart::add`
  pentru fiecare linie, cu cheia BBD, `Cart::applyCoupon`, `CustomerAuth::login` și `Cart::requestPoints`, județul și
  localitatea de facturare, `CheckoutCalculator::alegeFanbox`), rotunjit la 2 zecimale, cu toleranța 0,005:
  `CheckoutCalculator::buildSummary` (subtotalul, fără TVA, reducerea, punctele, TVA-ul și cel adăugat, transportul,
  totalul, dacă cuponul a mers, punctele aplicate; pe fiecare linie prețul, totalul, TVA-ul, reducerea, totalul după
  reducere, cheia BBD), `adminRecalcShipping` (cu transportul salvat și cu 0, ca la trecerea pe FANbox din admin),
  `LoyaltyService::estimateEarnPoints` (din sumar și din comanda salvată) și `ErpSync::buildPayload` pe comanda salvată
  (sha1 pe JSON-ul canonic; la o diferență, numele cheilor de sus care diferă).
- **Cupoanele** (`--cupoane-valide`, implicit; `--fara-cupoane-valide` le lasă): cele folosite de cazuri devin valabile pe
  durata comparației (`is_active = 1`, fără `starts_at`, `ends_at`, `max_uses_total`, `used_at`), altfel cupoanele vechi ar
  fi expirate în ambele coduri și reducerea nu s-ar mai calcula. Valorile de dinainte se scriu întâi într-un fișier 0600 din
  `tmp/` și se pun la loc la final (și după o eroare fatală PHP), verificat rând cu rând; o rulare omorâtă între ele le pune
  la loc la începutul celei următoare.
- **Ieșirea** (doar numere, nume de câmpuri, tipuri de caz, chei ERP și locuri din cod; niciun id, număr de comandă, nume,
  sumă sau produs, decizia D10; o diferență se reproduce pe datele demo, după tipul cazului):

  ```
  cazuri 512 | identice 512 | diferite 0 | erori vechi 0 | erori nou 0
  cazuri pe tip: bbd 40, cupon_fix 12, cupon_procent 85, fanbox 130, firma 61, judet_bucuresti 140, puncte 22, simplu 190
  cupoane aplicate fără eroare: vechi 90, nou 90 | puncte folosite: vechi 20, nou 20
  diferențe pe câmp: —
  diferențe pe tip de caz: —
  payload ERP: identice 512 | chei diferite: —
  transportul din admin: identice 512 | punctele promise: identice 512
  informativ: total nou = total salvat la 430/512 (diferențele vin din prețurile, cupoanele și transportul de acum, nu din cod)
  avertismente PHP: vechi 0, nou 0
  fișiere de sesiune lăsate de lucrători: 0
  REZULTAT: TRECE
  ```

  Cod de ieșire: 0 „TRECE” (niciun caz diferit și nicio eroare în niciun cod); 1 „PICĂ”, sau „OPRIT” după ce cupoanele au
  fost schimbate (puse la loc); 2 refuzat, nimic schimbat. Textul se salvează în `storage/verificare/sonde/` (local:
  `$CMS_DEV_DIR/calcule-raport.txt`).
- **Diferențele voite** (doar local, `--declarate=<fișier.json>`; planul de verificare, §2.3: „diferite 0, afară de
  schimbările voite, scrise explicit”, ca `$porturiBioscem` la comparația paginilor). O schimbare făcută intenționat în codul
  comun (de exemplu transportul gratuit pe produs din WP-20, pe o comandă demo cu un astfel de produs) se declară pe comanda
  ei: `[{"comanda": <id>, "campuri": ["shipping", "total"], "motiv": "WP-20, C14a: …"}]`. Câmpurile sunt toate cele care
  diferă la acea comandă, exact (numele din raport: `subtotal`, `shipping`, `linie.price`, `transport_admin_0`,
  `puncte_estimate` …; din payload-ul ERP cheia, `erp.<cheie>`, nu `erp` singur), cu motivul. Un caz care diferă exact la
  câmpurile declarate se numără „declarat”, nu „diferit” (rândul `diferențe declarate (--declarate): N potrivite exact |
  nepotrivite M`); o declarație nepotrivită (o diferență în plus sau în minus, nicio diferență, o comandă care nu e caz, o
  eroare în cod) pică, cu declarația numită după numărul ei (`declarații nepotrivite: #1 (diferă la: …; declarat: …)`). Un
  fișier greșit (fără motiv, un câmp necunoscut, aceeași comandă de două ori …) e refuz. Pe site-ul de verificare opțiunea e
  refuzată: acolo orice diferență pică. În poarta G-calc lista e `$diferenteDeclarate` din `tools/dev/test-calcule.php`, un
  bloc pe WP, cu numele comenzii inventate (azi goală); pe site-ul de verificare simulat al testului, comenzile declarate nu
  sunt cazuri.

### Amprenta paginilor (`amprenta-site.php`, WP-11)

Amprenta din planul de verificare (§2.4, G3): ce vede din afară un vizitator (și Google) pe fiecare adresă publică a unui
site adevărat. Două amprente se compară câmp cu câmp: (1) în Etapa 1, bioscem.ro (codul vechi) față de copia de verificare
(codul comun pe copia de azi-noapte), doar câmpurile structurale; e și acceptanța părții B a SEO (WP-37…WP-40) pe copie;
(2) în repetiția din Etapa 4, pe copie, codul vechi față de codul comun pe aceeași bază, cu `--strict`; (3) în noaptea
adoptării, site-ul live înainte și după. Nu atinge nicio bază și nu citește niciun `.env`; rulează de pe server sau de pe
orice calculator.

- **Ce cere**, doar GET, fără cookie-uri, `User-Agent: ANDAXI-audit/1.0`, fără să urmeze redirecționările (se scrie
  `Location`), cu `--pauza` între cereri (implicit 1 s, cel puțin 1 s pe un site care nu e pe calculatorul tău), cel mult
  `--max` adrese (3000): lista fixă (`/`, `/magazin`, `/blog`, `/contact`, `/cos`, `/checkout`, `/robots.txt`, `/sitemap.xml`,
  o adresă inexistentă aleasă la întâmplare, plus paginile aduse din Bioscem în WP-R: fluxul `/feed/google-merchant.xml` și
  casetele avertismentelor coșului, `/api/cart/avertismente`, singura adresă `/api/` cerută), fiecare `<loc>` din
  `/sitemap.xml` și din hărțile unui index (de pe aceeași gazdă, cu sau fără `www.`), linkurile de pe aceeași gazdă din
  prima pagină, adresele vechi ale categoriilor (`/magazin?category=<slug>` pentru fiecare `/categorie/<slug>`, plus una
  inexistentă: 301 într-un singur salt, livrarea 2 SEO) și primele 20 de poze de distribuire `/og/…` din `og:image` (aceleași
  pe ambele site-uri). Niciodată `/admin`, `/api/` (afară de cea de mai sus), `/webhook`, `/plata`, `/contul-meu`,
  `/newsletter/`, `/auth`, `/checkout/…`, `/gdpr-agreements`, `/_verificare`, `/_andaxi`, `/uploads/`, `/logout`
  (`AmprentaSite::permisa`). Fiecare cerere fără cookie pornește pe site o sesiune nouă, goală, ca orice vizitator nou.
- **Poarta copiei:** `--poarta=<utilizator>`, parola doar din `VERIFICARE_POARTA_PAROLA` (niciodată din linia de comandă) și
  doar pe https (local și pe http); un 401 la prima cerere oprește totul („cere parola porții” sau „parola nu e bună”, după o
  singură încercare), un 429 la fel.
- **Gazda sursă** (`--gazda-sursa=<gazdă>`): un site pornit pe conținutul altuia, cum e copia de verificare a bioscem.ro, are
  în conținutul copiat linkuri absolute spre site-ul copiat: descrierile produselor și paginile importate din WordPress
  (`https://bioscem.ro/produs/…`, `…/wp-content/uploads/…`), canonical-urile puse de mână în `seo_pages`. Pe bioscem.ro ele
  sunt ale site-ului (linkuri interne, cerute, `{GAZDA}` în canonical); cu opțiunea sunt la fel pe copie: gazda sursă e
  tratată ca a site-ului amprentat la căile hărții, linkuri, canonical, formulare, poză și în corpul normalizat. O copie de
  verificare (bannerul în prima pagină) fără opțiune se oprește după prima cerere („OPRIT: e o copie de verificare …”). În
  cap se scrie amprenta gazdei sursă (12 hex din sha1, aceeași cu și fără `www.`; fără opțiune, a gazdei amprentate), nu
  gazda; două amprente cu alte gazde sursă nu se compară (refuz, cod 2): linkurile conținutului ar diferi oricare ar fi
  codul. În repetiția din Etapa 4 (codul vechi și codul comun, ambele pe gazda copiei) ambele amprente se fac cu
  `--gazda-sursa`.
- **Ce scrie** (TSV, 0600, fără gazdă: `{GAZDA}` în adrese; capul spune versiunea (2), dacă e o copie de verificare, câte
  adrese, amprenta gazdei sursă și data), pe fiecare adresă: calea (partea aleatoare → `{ALEATOR}`, versiunea pozei →
  `/og/{V}/`), de unde a venit, codul, `Location`, felul răspunsului, sha1 pe corpul normalizat, titlul, meta robots,
  canonical, H1 (câte și amprenta textului), tipurile JSON-LD (sortate, de la orice adâncime), câte linkuri interne,
  formularele (câte și amprenta metodelor și acțiunilor), `X-Robots-Tag`, poza de distribuire. Normalizarea corpului e cea a comparației locale (`tools/dev/lib/normalizeaza-html.php`, aceeași cu
  `compara-cu-baza.php`): tokenurile, momentele, `?v=`, identificatorii aleatori, gazda (și gazda sursă), **bannerul copiei
  scos** (`id="verificare-banner"`, garda 14), cele două câmpuri ascunse ale formularului de recenzie, exact cum le scrie
  codul comun (`AmprentaSite::CAMPURI_RECENZIE`, WP-21: declarate; orice altă schimbare a formularului rămâne în corp),
  plus versiunea pozei de distribuire (`/og/<12 hex>/` → `/og/{V}/`). Versiunea
  depinde de data și mărimea fișierului (`ImagineDistribuire::versiune`), deci se scoate și din calea rândurilor `/og/…`, iar
  cele 20 de poze se aleg după calea fără ea: același site înainte și după, cu pozele copiate fără datele lor (noaptea
  adoptării), are aceleași rânduri. Copia de verificare se recunoaște după banner.
- **`--compara A.tsv B.tsv`**: câte adrese sunt doar într-una (și care), câte diferențe pe fiecare câmp structural (codul,
  `Location`, felul, canonical, robots, titlul, H1, JSON-LD, linkurile, formularele, `X-Robots-Tag`, poza de distribuire) și
  adresele, cel mult 50 pe câmp; cu `--strict` și corpul. Cod 0 „LA FEL”, 1 „DIFERĂ”, 2 un fișier care nu e o amprentă (sau
  de altă versiune) sau două amprente cu alte gazde sursă. Când doar una dintre amprente e a unei copii de verificare, nu se
  compară ce pun gărzile copiei, și se spune: `X-Robots-Tag` (poarta îl pune pe fiecare răspuns), poza de distribuire și
  adresele `/og/`, `/uploads/` (garda 13: pozele nu se copiază); `--strict` cere atunci două amprente de același fel (pe copie
  gărzile schimbă corpul: bannerul, etichetele terților, robots.txt).

### Adresa vizitatorului (`IpClient`)

`IpClient::adresa()` întoarce `REMOTE_ADDR`. Doar când `REMOTE_ADDR` e într-una din rețelele din
`IP_PROXY_DE_INCREDERE` (adrese sau CIDR, separate prin virgulă; implicit nimic) ia, de la dreapta la
stânga, prima adresă din `X-Forwarded-For` care nu e de încredere. Ce scrie vizitatorul în antet stă în
stânga și nu se ajunge la el. Dacă `stare.php` arată că PHP-ul vede un proxy (o rețea privată sau a CDN-ului
în loc de adresa vizitatorului), toți vizitatorii împart aceeași limită de 10 încercări până se trece
rețeaua lui în `IP_PROXY_DE_INCREDERE`. O limită numără pe `IpClient::cheieLimita()`: IPv4 adresa
întreagă, IPv6 rețeaua /64.

**Din WP-12b, tot codul site-ului ia adresa vizitatorului doar de aici:** limitele de încercări ale checkout-ului (10
în 15 minute) și ale înregistrării (8), jurnalul adminului, acordurile GDPR, mesajele de contact, deschiderile și
clicurile newsletterului, IP-urile cu acces din mentenanță (și „IP-ul tău curent” de lângă ele, în Setări magazin;
adresa e în forma canonică, iar lista se compară ca adrese, `IpClient::aceeasiAdresa`, deci `2001:DB8:0:0::1` sau
`::ffff:1.2.3.4` scrise acolo se potrivesc în continuare, o rețea nu).
Codul vechi lua primul `X-Forwarded-For` (sau `X-Real-IP`), deci oricine ocolea limitele scriind alt antet la fiecare
încercare. Limitele numără în SQL pe `IpClient::conditieLimita()`: o adresă IPv4 exact, ca înainte, una IPv6 cu toată
rețeaua ei /64 (primii 8 octeți, `INET6_ATON`); rândurile păstrează adresa întreagă. Singura altă citire a antetelor e
sonda de mai sus (`Verificare::raspundeSonda`), care spune doar ce sosește; `tools/dev/test-securitate.php` caută în cod
orice altă citire și pică pe ea. Pe un site live, înainte de trecerea pe codul comun (vezi
[MIGRARI.md](MIGRARI.md), „Pe server, la adoptare”): dacă site-ul are un CDN sau un proxy în față (CDN-ul Hostinger,
Cloudflare), `REMOTE_ADDR` e adresa lui, deci rețelele lui intră în `IP_PROXY_DE_INCREDERE` din `.env`; altfel toți
vizitatorii ar împărți aceeași limită. Ce vede PHP-ul arată `stare.php` pe site-ul de verificare, pus în față cu același
CDN ca site-ul live.

### Cheile din `.env` (scrise de `configureaza.php`)

| Cheie | Valoare | Folosită de |
|---|---|---|
| `APP_URL` | `https://<domeniul dosarului>` | aplicația |
| `APP_ENV`, `APP_DEBUG`, `APP_NAME` | `verificare`, `false`, `VERIFICARE` | aplicația |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `localhost`, `3306`, ce scrie proprietarul | aplicația, `stare.php` |
| `SITE_COPIE_VERIFICARE` | `1` | poarta și cele 14 gărzi |
| `VERIFICARE_POARTA_UTILIZATOR`, `VERIFICARE_POARTA_HASH`, `VERIFICARE_POARTA_MOD` | `verificare`, `password_hash` al parolei porții, `basic` | poarta |
| `VERIFICARE_ZILE_PASTRARE` | `7` (cel mult 7) | poarta (expirarea copiei) |
| `IP_PROXY_DE_INCREDERE` | gol | `IpClient` |
| `VERIFICARE_SURSA`, `VERIFICARE_UPLOADS_DIN`, `VERIFICARE_WEBHOOKURI_DESCHISE` | `bioscem`, `https://bioscem.ro`, `0` | sursa copiei și pozele (garda 13; pe amândouă le scrie și încărcarea: site-ul copiat, sau `demo`), webhook-urile (poarta) |
| `VERIFICARE_EMAIL`, `VERIFICARE_SMTP_HOST`, `_PORT`, `_UTILIZATOR`, `_PAROLA`, `_CRIPTARE` | adresa proprietarului; căsuța lui (`smtp.hostinger.com`, `465`, ea, parola ei, `ssl`) sau gazda goală | garda 5 (toate emailurile spre proprietar; fără gazdă, fișiere `.eml`) |
| `VERIFICARE_ERP_URL`, `VERIFICARE_ERP_CHEIE` | lipsesc (ERP oprit, D5) | garda 6 (doar o copie `*-verif.erp.andaxi.ro`, mai târziu) |
| `VERIFICARE_FAN_TEST`, `VERIFICARE_EUPLATESC_TEST` | lipsesc | gărzile 7 și 10 (`1` = un cont de test al FAN, al EuPlătesc) |
| `BT_IPAY_MODE`, `BT_IPAY_TEST_*`, `BT_IPAY_LIVE_*` | `test`, gol, gol | aplicația (garda 9: mereu modul de test; `stare.php` cere `BT_IPAY_LIVE_*` goale). `incarca.php --din` copiază cele trei chei `BT_IPAY_TEST_*` ale site-ului copiat, dacă aici sunt goale (decizia D3) |
| `LICENTA_SITE`, `LICENTA_CHEIE_PUBLICA` | `verificare`, cheia publică a perechii făcute de `configureaza.php` | licența (`licenta.php`) |
| `SITE_DATE_DOSAR` | `<dosarul codului>/site-uri/bioscem` | harta adreselor vechi și regulile SEO |
| `SITE_SLUG` | `bioscem` | `scripts/migreaza.php` (pașii site-ului: înghețarea setărilor din pachet; fără el, cu pachetul Bioscem, migrările se opresc cu codul 2). Un `.env` scris înainte (fără cheie) o primește de mână sau cu `--rescrie` ([MIGRARI.md](MIGRARI.md), „Înghețarea setărilor”). Încărcarea (`incarca.php`) le scrie pe **amândouă**, `SITE_SLUG=<site>` și `SITE_DATE_DOSAR=<dosarul codului>/site-uri/<site>`, pentru pachetul pe care îl încarcă (`--din=bioscem`, `--demo --pachet=<site>`); pentru Biovitality, vertij sau grafoanaytis trebuie copiat și `site-uri/<site>/privat/` din dezvoltare lângă pachet (fără el, codul 15). De mână, cu doar una schimbată, migrările se opresc cu codul 2. `--permite-provizoriu` (înghețările BV, VT, GR sunt provizorii) merge doar aici, cu `SITE_COPIE_VERIFICARE=1`; pentru Bioscem nu schimbă nimic |
| `ADMIN_DEFAULT_PASSWORD` | gol | nefolosit (adminul îl face încărcarea) |

`configureaza.php` scrie fișierul atomic (temporar 0600, apoi `rename`) și verifică înainte că fiecare
valoare se citește înapoi exact cum o citește `Env::load` (care taie spațiile și ghilimelele de la capete).
Refuză fără să scrie nimic: un dosar care nu e `~/domains/<gazdă>/public_html`, un `.env` existent (cu
`--rescrie`, doar unul cu `SITE_COPIE_VERIFICARE=1`), o bază care nu se deschide, are tabele fără
`_verificare` sau e scrisă în `.env`-ul altui site din cont (`~/public_html`, `~/domains/<d>/public_html`,
`~/domains/<d>/site`; doar `DB_DATABASE`/`DB_HOST` citite, în memorie, și pe ecran doar domeniul), parole ale
porții diferite, scurte (sub 10) sau egale cu parola bazei sau a căsuței, un terminal în care parola nu se poate
ascunde. Cheia privată nouă se scrie înainte de `.env` și se șterge la loc dacă `.env` nu se poate scrie. Nu
creează niciun tabel.

O parolă se citește tastă cu tastă, cu terminalul fără ecou și cu Ctrl-C citit ca o tastă
(`stty -echo -icanon -isig`, doar cât se scrie parola): la Ctrl-C scriptul pune singur terminalul
înapoi și abia apoi se oprește (cod 130). Oprit de semnal în acel mod, PHP-ul ar lăsa terminalul fără
ecou, iar proprietarul n-ar mai vedea ce scrie. SIGTERM și SIGHUP (găzduirea care oprește un proces)
sunt prinse la fel, dacă PHP-ul are pcntl; altfel rămâne `stty sane` din tabelul de mai sus. La
celelalte întrebări semnalele rămân cele obișnuite: Ctrl-C oprește pe loc.

`configureaza.php`, `stare.php`, `licenta.php` și `sterge.php` folosesc doar sintaxa PHP 7.0 (codul aplicației îl
încarcă abia după verificarea versiunii), ca un PHP prea vechi să primească un mesaj, nu o eroare de sintaxă
(`stare.php` îi arată și versiunea, cod 1; decizia G-M6). Nu pornesc sesiuni: primele două nu încarcă
`bootstrap.php`, iar `licenta.php` îl încarcă doar ca linie de comandă (fără sesiune).

### Dosarul de lucru

`Verificare::dosar()` = `Paths::stocareSite('verificare')`: azi `storage/verificare/` din rădăcina codului, în
așezarea din Etapa 2 `site/storage/verificare/` ([DEZVOLTARE.md](DEZVOLTARE.md), „Unde scrie codul”), făcut la
nevoie cu un `.htaccess` „Require all denied” (rădăcina trimite oricum totul în `public/`). `.env` e
`Paths::env()`; uneltele de pe server (`configureaza.php`, `stare.php`, verificările pe site-urile live) le iau tot
de acolo:

| Fișier | Ce e |
|---|---|
| `poarta-esecuri.json` | amprentele adreselor cu parole greșite, din ultimele 10 minute |
| `sonda.json` | amprenta jetonului sondei (2 minute, o singură folosire) |
| `incarcat-la` | data încărcării copiei (ISO 8601 sau secunde Unix), scrisă de `incarca.php` la final (după verificări), ștearsă de `sterge.php` și de o încărcare nouă |
| `emailuri-azi.json` | ziua, câte emailuri au plecat și câte au fost sărite (limita de 100 pe zi), 0600 |
| `mail/` | emailurile copiei ca fișiere `.eml` (0600), când copia n-are căsuță de trimitere; le șterge `sterge.php` |
| `tmp/` | fișierele temporare ale copiei, sarea unei încărcări neterminate (`sare-incarcare`, 0600, pentru `--reia`; ștearsă la final) și ale comparației calculelor (cazurile și rezultatele lucrătorilor, când `proc_open` lipsește; valorile de dinainte ale cupoanelor, `calcule-cupoane-<amprentă>.json`, cât durează comparația), 0600, șterse la final; le șterge și `sterge.php` |
| `sonde/` | rezultatele verificărilor pe site-urile live (pasul 5), textele încărcărilor (`incarcare-<sursa>-<data>.txt`, pasul 6), ale comparațiilor calculelor (`calcule-<profil>-<data>.txt`) și amprentele paginilor (`--iesire=…`, pasul 6, f), 0600 într-un dosar 0700; le trimite proprietarul, apoi le șterge |

### Verificările pe site-urile live (WP-10)

Două unelte, rulate de proprietar din dosarul site-ului de verificare (pasul 5), contra bazei fiecărui
site live (planul Etapei 1, §5 WP-10; OD-04):

- **`tools/verificare/agregate.php --set=<bioscem|grafoanaytis|biovitality|vertij>`** (G4): verificările
  agregate ale unui site, un rând pe număr: `  STARE    cheie: valoare  — descriere`, cu STARE = `OK` /
  `ATENȚIE` (o valoare care are un răspuns așteptat: o parolă de admin în text clar, două coduri de
  cupon care se ciocnesc), `INFO`, `LIPSĂ` (site-ul nu are tabelul sau coloana), `EROARE`, `SĂRIT`.
  Rezultatul: pe ecran și în `storage/verificare/sonde/agregate-<set>-<data>.txt`. Cod 0 = totul a
  rulat, 1 = unele verificări au căzut (restul e bun), 2 = refuzat.
- **`tools/sonda/amprenta-schema.php --site=<…>`** (MIG-08 din planul Etapei 1): structura bazei, cu
  rândurile din `information_schema` în forma lui `Amprenta::citesteBrut()` (plus poziția și colația
  coloanelor), valorile serverului care contează pentru migrări (`sql_mode`, timpii de așteptare, motorul
  implicit…), drepturile utilizatorului bazei (doar numele drepturilor, globale și pe bază), numele cheilor
  din `settings`, rândurile exacte ale fiecărui tabel (sub 200.000 estimate), vederi / declanșatoare /
  rutine, rămășițele vechi pe care le citesc migrările de date (`reviews` lipsă din `product_reviews`,
  galeriile vechi, adminii fără rol, linkurile de plată pe procesator, străzile FAN fără cheie, tabelele
  `__sync`/`__vechi`/`#sql`). JSON în `storage/verificare/sonde/amprenta-<site>-<data>.json`; cu `--stdout`
  pe ecran, fără nimic scris (de exemplu `ssh … '…' > amprenta.json` de pe alt calculator).

Ce garantează amândouă (capul lui `tools/verificare/lib/live.php`; le verifică `test-sonde.php`):

- **Locul.** Rulează doar dintr-un `~/domains/<domeniu>/public_html` cu `SITE_COPIE_VERIFICARE=1` în `.env`
  (deci niciodată din dosarul unui site live, unde după adoptare va fi tot acest cod), cu HOME = contul
  dosarului. Site-ul live e dosarul `~/domains/<domeniu>/public_html` (sau `www.<domeniu>`) ori
  `~/public_html` al cărui `.env` (fișier obișnuit, nu legătură) are în `APP_URL` domeniul setului și nu
  e o copie de verificare; un singur dosar, altfel refuz (cu `--dosar=` se alege, dar se verifică la fel).
  Domeniile sunt în `Locul::SITE_URI`.
- **`.env`-ul site-ului live** se citește o dată, în memorie: rămân doar `APP_URL`, `APP_TIMEZONE`, `DB_*`
  (pentru conexiune, apoi uitate) și numele cheilor nevide. Nicio valoare nu se tipărește; o eroare a bazei
  apare doar ca cod („eroarea 1045 (28000)”), nu cu mesajul ei, care poate avea utilizatorul sau o valoare.
  O eroare PHP apare ca clasă și loc în codul uneltei, fără mesaj.
- **Pe site-ul live nu se scrie nimic:** nu se încarcă `bootstrap.php`, nu pornește nicio sesiune (o
  sesiune pornită de `session.auto_start` oprește rularea), nu se folosește comanda `git` (`git status`
  poate rescrie indexul): commit-ul se citește din `.git/HEAD`, refs și `packed-refs`. Se citesc doar
  `.env`, amprenta câtorva fișiere de cod (`SiteLive::FISIERE_COD`, niciodată config sau `.env`), numele
  din `storage/import` și `storage/account-documents` (doar numărate, nedeschise) și amprenta paginilor din
  pachetul GR (`database/pagini/`, `database/design/antet.css`).
- **În bază:** după conectare, `SET SESSION TRANSACTION READ ONLY` (verificat: altfel nu se citește nimic),
  `REPEATABLE READ`, `max_statement_time` 15 s (MariaDB) / `max_execution_time` 15000 ms (MySQL),
  `lock_wait_timeout` 5 s, `mysqlnd.net_read_timeout` 30 s. Fiecare instrucțiune rulează într-o tranzacție
  `WITH CONSISTENT SNAPSHOT, READ ONLY` a ei, închisă (`ROLLBACK`) imediat ce s-au luat rândurile, înaintea
  pauzei; ultima se închide și după grup, orice ar fi. Lacătul de metadate al unui tabel citit se ține deci
  doar cât rulează instrucțiunea (cel mult 15 s, de obicei milisecunde): un ALTER al site-ului așteaptă după
  noi cel mult atât, nu cât un grup. Numerele unui grup pot veni astfel din momente la câteva milisecunde
  distanță (niciun rezultat nu le adună din instrucțiuni diferite). Fiecare instrucțiune trece prin
  `CitireLive::verificaInstructiunea`: doar `SELECT`/`SHOW`, una singură, fără comentarii, fără `INTO`,
  `FOR UPDATE`, `LOCK IN SHARE MODE`, `SLEEP`, `LOAD_FILE`, `GET_LOCK`…, iar un `SELECT` se termină cu
  `LIMIT n` (n ≤ 50.000). Conexiunea are `MYSQL_ATTR_MULTI_STATEMENTS` și `LOCAL_INFILE` oprite. După fiecare
  instrucțiune, o pauză de 20 ms; o rulare are un buget (`--buget`, implicit 300 s) după care grupurile
  rămase sunt SĂRIT. Serverul însuși refuză orice scriere sau DDL din această sesiune (eroarea 1792).
- **Memoria PHP** (`memory_limit` al contului): tabelele cu texte lungi (pagini, articole, descrieri,
  șabloane) se parcurg după `id` pe bucăți de cel mult 2 MB: întâi mărimile (`LENGTH`), apoi doar rândurile
  care încap. Un rând peste 8 MB, sau mai mare decât încape în memoria rămasă (`CitireLive::octetiCareIncap`:
  de patru ori textul, plus 4 MB liberi), nu se citește: e numărat ca `css.randuri_prea_mari.<sursă>`
  (ATENȚIE). Memoria nu se termină deci în mijlocul unei citiri, unde închiderea conexiunii ar aștepta
  răspunsul bazei (cu tranzacția deschisă) până la `net_read_timeout`.
- **Erorile PHP** nu le tipărește PHP-ul: mesajul lui are calea contului (`/home/u…/domains/…`) și poate
  avea o valoare. `tools/verificare/lib/intrare.php`, încărcat primul de ambele unelte (sintaxă PHP 7.0),
  oprește afișarea și jurnalul și pune în loc texte fixe, cu felul erorii și locul ei sub `tools/`:
  „AVERTISMENT PHP: E_WARNING în verificare/lib/…:N” pentru o eroare care nu oprește rularea (în grupuri,
  unealta o scrie ca EROARE), „OPRIT: memoria PHP s-a terminat (memory_limit) în …” pentru una fatală.
  După o eroare fatală, `agregate.php` scrie verificările de până acolo (grupul oprit ca EROARE) pe ecran și
  în fișier, cod 1 (`Rulare::opritDeEroare`); sonda nu scrie o amprentă parțială; înainte de citire, doar
  „OPRIT: …”, cod 2.
- **Ieșirea** are doar numere, DA/NU, valori din liste fixe și nume care trec tiparele din `Raport::TIPARE`
  (chei, id-uri, slug-uri, nume de structură, clase CSS, commit-uri, luni); o valoare care nu se potrivește
  oprește verificarea ei („valoare refuzată”), nu ajunge pe ecran. O coloană cu nume de date personale
  (`CitireLive::TIPAR_PERSONAL`, același tipar ca la încărcarea anonimizată din WP-09) nu poate fi citită deloc.
  Numerele se calculează în SQL (parolele adminilor se clasează în SQL, ca text, fără să fie citite, după aceeași
  regulă ca logarea, `App\Support\ParolaAdmin`, din WP-12b: hash cunoscut (începe chiar cu `$2y$`, `$argon2i$` sau
  `$argon2id$`), bcrypt `$2a$`/`$2b$`, hash stricat (forma
  unui hash, `$`, numele algoritmului, `$`, dar nu unul de mai sus: tăiat, `$6$`, `$1$`, WordPress `$P$`; nu se loghează
  cu nicio parolă, deci ATENȚIE peste 0), goală, text clar; `admini.parola_necunoscuta` = exact adminii pe care logarea îi compară în clar, deci trebuie să fie 0 pe
  toate cele patru site-uri înainte ca ramura aceea să fie ștearsă, în R2);
  în PHP ajung doar conținut public (pagini cu HTML, CSS și JS, articole, descrieri de produse, șabloane cu
  HTML, CSS și JS, setările `design_*`, și cele `_js`) pentru clasele CSS și cheile ofertelor BBD.
- **V-CSS** (clasele folosite de conținutul din bază): din atributele `class` și blocurile `<style>` ale
  HTML-ului (`ClaseCss::dinHtml`), din selectorii CSS-ului (`dinCss`) și din JavaScript (`dinJs`: textele din
  `classList.add/remove/toggle/replace/contains`, `addClass`/`removeClass`/`toggleClass`/`hasClass`,
  `getElementsByClassName`, ce primește `className` sau `setAttribute('class', …)`, HTML-ul scris în texte și
  selectorii din `querySelector(All)`, `closest`, `matches`, `$()`/`jQuery()` și metodele jQuery cu selector).
  Mai multe clase decât trebuie nu strică (o clasă în plus doar oprește ștergerea unei reguli în WP-30/WP-40),
  una lipsă da. O clasă compusă la rulare din bucăți (`'x-' + n`, `` `x-${n}` ``) apare doar cu partea fixă.

Seturile (`tools/verificare/seturi/`; fiecare = `comun.php` + ale lui):

| Set | Ce are în plus față de cele comune | Ce decide |
|---|---|---|
| comune | codul (G1); parolele adminilor (G-M5, G4); comenzile netrimise în ERP pe vârste, luna primei trimiteri (G-M8); fișierele din `storage/import` (G-m9); conturile pe al căror email sunt comenzi fără cont (OD-47); cheile secrete și `.env` ca DA/NU (G3); comutatoarele și activitatea pluginurilor pe 365 de zile; produsele, galeria pe fel (BS-V3); paginile, slug-urile celor publicate, tokenurile; emailurile din 30 de zile pe stare (G6); la urmă clasele CSS din conținut (V-CSS) | WP-12 E1, OD-50, WP-12b, WP-23, OD-47, OD-23, OD-33, WP-30, WP-40 |
| `bioscem` | V1 cheile ofertelor BBD (cu cheia derivată ca în checkout), V2 produsele inactive comandate în 90 de zile (id-uri), V3 a–e blogul, V4 câmpurile html cu marcaj, V5 codurile cu spații, BS-V1 `{{shop_catalog}}` în alte pagini, BS-V2 `{{an}}` în antet/subsol | C8, C14d, C9, GR-09, GR-06, WP-22, X1b |
| `grafoanaytis` | G2 cererile de ofertă pe stare, ordinea manuală; G5 paginile din bază față de `database/pagini/` și antetul față de `database/design/antet.css` (egal / diferit / lipsă), celelalte chei `design_*` ca amprentă scurtă | GR-15, GR-20, OD-10, OD-23 |
| `biovitality` | V7 numele cheilor din `settings`; pachete, transport gratuit, produse ascunse, fișiere în cloud, concurență, etichete de blog, articole din WordPress | WP-06, WP-11b, Etapa 4 |
| `vertij` | O18: donațiile pe stare și frecvență, membrii activi, conturile, verificările și activările pe stare, conținutul doar pentru membri, câte documente | O18, licența VT |

Ce primim de la proprietar (fișierele din `storage/verificare/sonde/`) stă local în
`tools/dev/migrari/vechi/privat/` (ignorat de git, ca `storage/`): repo-ul e public (OD-49), iar numerele
sunt scoase din datele live. `tools/dev/php.sh tools/dev/migrari/din-sonda.php <amprenta-….json>` face din
sondă `privat/<cod>-live.sql` (doar `CREATE TABLE`, niciun rând), `privat/extra-<cod>.txt` (ce are site-ul în
plus față de `database/migrari/asteptat.json` sau `--fata-de=`) și `privat/<cod>-fata-de-asteptat.txt`;
refuză un fișier de intrare sau un dosar de ieșire din repo pe care git nu-l ignoră. Căile relative sunt față
de dosarul curent (`tools/dev/php.sh` pornește din rădăcina repo-ului) și se fac absolute, cu legăturile
rezolvate, înainte de verificare, și pentru un dosar care nu există încă; aceeași cale se verifică și se scrie
(o cale cu „..” printr-un dosar inexistent e refuzată). Fixture-urile din git
(WP-04) rămân cele din cod, potrivite de mână după structura sondei, niciodată după numerele ei. Cu
`privat/<cod>-live.sql` și `privat/extra-<cod>.txt` scrise, `tools/dev/php.sh tools/dev/test-migrari.php`
rulează singur testul T2-live: baseline-ul pe schema live a site-ului, pe MariaDB și pe MySQL 8
([MIGRARI.md](MIGRARI.md), „Testele”); fără ele îl sare, cu motivul („sonda live n-a venit încă”).

### Ce urmează

- **Prima încărcare a Bioscem** (pasul 6, noaptea, proprietarul): textul ei ne arată duratele migrărilor pe baza
  adevărată (`migrari.json`) și dacă regulile acoperă tot.
- **Prima comparație a calculelor și prima amprentă** (pasul 6, e și f, după prima încărcare): „REZULTAT: TRECE” e
  acceptanța porturilor cu bani (WP-20: C14a, C9, X5; WP-25), care o rulează din nou.
- **Prima încărcare a Biovitality** (pasul 6b, noaptea, proprietarul; WP-11b făcut): „GATA”, apoi „REZULTAT: TRECE” la
  `compara-calcule.php --profil=biovitality` e acceptanța WP-11b (golden master-ul Biovitality: C14a, C9, X5 față de codul
  Biovitality însuși); amprenta biovitality.ro față de copie e lista schimbărilor vizibile la adoptare. Vertij și
  grafoanaytis primesc regulile la porturile lor.
- **Etapa 2**: modul de test al plăților (chei de test, `VERIFICARE_WEBHOOKURI_DESCHISE=1`), check-in-ul licenței
  la CRM.

### Testele

`tools/dev/php.sh tools/dev/test-verificare.php` (poarta G-verif din `tools/dev/porti.sh`, rulată și cu
PHP-ul minim): face o copie a codului (fișierele din git, cu modificările necomise) într-o găzduire
simulată, `$CMS_DEV_DIR/verificare/acasa/domains/verificare.exemplu.test/public_html`, o servește cu
`php -S` pe `CMS_DEV_PORT`+7 cu un mediu gol (ca pe Hostinger, totul din `.env`) și parcurge: deploy fără
`.env` (503 la orice), fiecare refuz al `configureaza.php` (și o bază scrisă în `.env`-ul altui site) și configurarea
pe o bază goală, cu cheia licenței; poarta (401, 429, formularul, cookie-ul, sonda, modul formular, pingul fără parolă,
webhook-urile), `stare.php` în ambele stări (ieșirea lui întreagă intră în raport; configurat: „GATA” și cele 14
gărzi DA), apoi **fiecare gardă stricată pe rând** în copia codului (14 mutații: `stare.php` trebuie să-i arate
rândul NU și să nu mai spună „GATA”); robots.txt prin CDN-ul Hostinger simulat (`tools/dev/lib/cdn-simulat.php`,
`CMS_DEV_PORT`+11: textul hcdn, alt text, alt server, antetele porții, sau totul spre copie) pe domeniul copiei (propriu:
NU) și pe o a doua găzduire simulată pe un domeniu temporar (`verificare-proba.hostingersite.com`, `CMS_DEV_PORT`+6:
INFO și „GATA”; neconfigurat, INFO cu 503), plus fiecare ramură a lui `RobotiGazda` cu răspunsuri simulate (A); un PHP
care nu încarcă singur pdo_mysql (`tools/dev/lib/php-fara-extensii.php`: PHP-ul testului cu `PHP_INI_SCAN_DIR` fără
fișierul lui ini, pornit cu `exec -a`, deci PHP_BINARY e chiar el, ca `/opt/alt/php83` pe Hostinger; sodium e în PHP-ul
local, deci aici stă în locul lui): refuzurile cu reparația exactă, `stare.php` cu rândul NU și INFO, apoi pornit cu `-d`
„Pasul următor” cu `$PHP`; o copie „încărcată” (`cms_verif_copie`, o copie a bazei de dezvoltare cu
`_verificare`, licența pusă de `licenta.php toate`; paginile 200, cu noindex și no-store) și una expirată; gărzile,
cu setările unui site adevărat lăsate în baza copiei (SMTP, Stripe live, EuPlătesc, ERP, FAN, BT live în `.env`,
GA/GTM/Ads/Clarity/tawk.to, login Google): bannerul, nicio etichetă, pozele 302 și căile rele 404, fluxul Merchant,
login-ul Google, checkout-ul fără EuPlătesc și BT, Stripe și tariful FAN refuzate, o comandă cu produsul refrigerat
cu emailurile ei (clientul și adminul) doar la proprietar prin căsuța copiei, limita de 100, fișierele `.eml`, fiecare
loc din cod din linia de comandă; apoi `SITE_COPIE_VERIFICARE=0` cu aceleași setări (etichetele, Google, EuPlătesc și
BT apar: testul vede ce opresc gărzile); la urmă `licenta.php` și `sterge.php` (refuzurile, ștergerea, o legătură
spre afară neurmată, o încărcare oprită la jumătate: tabele cu date și `_verificare` cu `anonimizat = 0`). Bannerul
se verifică pe o pagină publică și pe `/admin/login` (adresa ascunsă pe jumătate), apoi în admin după logare
(întreagă). Cere baza de dezvoltare făcută de `tools/dev/instaleaza.sh` (o copiază în `cms_verif_copie`): fără ea,
sau cu o bază fără `_platforma_stare` și `settings`, se oprește de la început cu acest motiv. Plasa de siguranță e
`tools/dev/lib/gaura-neagra.php`: proxy-ul copiei (`CMS_DEV_PORT`+12) și adresa „interzisă” (+14: SMTP-ul, ERP-ul,
FAN-ul și banca din setări) notează fiecare conexiune, iar +13 e căsuța copiei, un server SMTP care păstrează mesajele; la final trebuie să fi ajuns acolo doar drumurile permise încercate
anume (o cheie `sk_test_`, o copie `*-verif` a ERP-ului, contul FAN și BT de test), ca să se vadă că plasa chiar
numără. Bazele `cms_verif_gol`, `cms_verif_plina`, `cms_verif_copie` și utilizatorul `cms_verif_test` se fac și se
șterg acolo. Cu un PHP sub minim instalat (`php7.0` … `php8.2`) verifică și mesajul pentru un PHP prea vechi, la
toate cele patru unelte. Cu `python3` rulează `configureaza.php` și într-un
terminal adevărat (`tools/dev/lib/terminal.py`, un pty): Ctrl-C în mijlocul unei parole, cu și fără
pcntl, și SIGTERM lasă terminalul cu ecou; parola nu apare pe ecran, iar Backspace o corectează. Limita
pe /64 se încearcă prin `X-Forwarded-For`, cu serverul de test trecut ca proxy de încredere. Toate datele
sunt inventate.

`tools/dev/php.sh tools/dev/test-sonde.php` (poarta G-sonde, și cu PHP-ul minim) încearcă verificările pe
site-urile live într-o găzduire simulată în `$CMS_DEV_DIR/sonde/`: site-ul de verificare (doar uneltele) și
patru site-uri „live” inventate (`bioscem.ro`, `www.grafoanaytis.ro`, `biovitality.ro`, `vertij-tinitus.ro`),
toate pe `cms_sonde_t`, o bază făcută ca a unui site live încă neadoptat (de codul de dinainte de migrări,
`tools/dev/migrari/pe-vechi.php`, cu seed-ul și datele demo: are tabelul vechi `reviews` și celelalte obiecte
vechi, făcute de codul lor, și nu are registrul migrărilor), cu rânduri inventate pentru fiecare verificare (toate
datele personale cu un marcaj aleator). Baza de dezvoltare nu-i trebuie. Verifică garda instrucțiunilor și tiparele ieșirii, fiecare refuz al
locului (cod 2, nimic citit), fiecare număr al fiecărui set (cât se schimbă după rândurile inventate; numerele
parolelor adminilor sunt, pe toate rândurile, cele date de `ParolaAdmin::fel`, și pe valorile la care SQL-ul s-ar putea
despărți de regulă: lungimea în octeți față de caractere, un spațiu față de gol, argon2, `$2a$`/`$2b$`, alte hash-uri
(`$6$`, `$P$`, litere mari), forma hash-ului fără nume de algoritm sau cu un nume care nu e ASCII, argon2 fără `$` în
față (`password_get_info` îl recunoaște, regula nu), pe MariaDB și pe MySQL 8), că
serverul refuză scrierile din sesiune, că un ALTER din altă conexiune pe un tabel deja citit nu așteaptă în
mijlocul unui grup, că un utilizator doar cu SELECT ajunge, sonda (amprenta ei = a bazei), `din-sonda.php`
(schema refăcută dă aceeași amprentă pe MariaDB și pe MySQL 8; refuzurile și cu căi relative), aceleași
rânduri pe MySQL 8, bugetul, textele lungi cu memoria PHP mică (40 × 1 MB la `memory_limit=16M`, rândurile
prea mari doar numărate, o eroare fatală de memorie în V-CSS: „OPRIT”, cod 1, fără să se agațe și fără
mesajul PHP-ului), textele fixe ale lui `intrare.php`; fiecare rulare are un termen de 300 s; și, pe tot
parcursul, că marcajul, parolele, numele bazei, ale utilizatorilor și calea
contului nu apar în nicio ieșire, că baza nu s-a schimbat (CHECKSUM TABLE), că în dosarele site-urilor live
nu s-a schimbat niciun fișier și că în site-ul de verificare s-a scris doar `storage/verificare/sonde/`.
Bazele `cms_sonde_t`, `cms_sonde_rt` și utilizatorii `cms_sonde_tot`, `cms_sonde_citire` se fac și se șterg acolo.

`tools/dev/php.sh tools/dev/test-incarcare.php` (tot poarta G-verif, și cu PHP-ul minim; `--fara-baza`: doar A)
încearcă încărcarea. **A**, fără bază: regulile Bioscem acoperă fiecare tabel și fiecare coloană a schemei Bioscem
4254ca4 (`tools/dev/migrari/vechi/bs.sql`, și avertismentul pe produs) și a codului comun (`asteptat.json`), nicio
regulă spre o coloană care nu există, fiecare fel de greșeală din reguli e prins, scanerul, fiecare transformare,
neutralizarea setărilor și verificarea ei (A6), ce fișiere ale codului vechi se copiază, garda instrucțiunilor sursei,
mesajul pentru un PHP prea vechi; **oracolul testului**, ținut în test, nu luat din reguli: tabelele cu datele
clienților și, în fiecare, singurele coloane de text sau de dată care au voie să rămână cum sunt (orașul, stările,
datele…), plus clasa fiecărui tabel (al clienților, fără rânduri, public): orice altă coloană de text sau de dată a
tabelelor clienților trebuie transformată de reguli, iar fiecare tabel al regulilor trebuie clasificat și în test (două
chei); câte o mutație a regulilor pe care `probleme()` nu o vede (mesajul de contact, notele adminului, eroarea ERP,
eticheta adresei, data nașterii… lăsate cum sunt; un tabel al clienților copiat cum e; un tabel nou) e prinsă, cu numele
ei. **B**, o găzduire simulată în `$CMS_DEV_DIR/incarcare/acasa`: site-ul de verificare
(copia codului din git, configurat cu `configureaza.php`, servit pe `CMS_DEV_PORT`+15) și un Bioscem „live” inventat
(`domains/bioscem.ro/public_html`: `.env` cu cheile BT de test și live, cod, `.git`, sesiuni, poze, sitemap, jurnale,
o copie de bază uitată, un export, o arhivă, o legătură spre afară), cu baza `cms_incarc_sursa` făcută ca a unui site
încă neadoptat (`pe-vechi.php`, seed-ul, datele demo și tabelele avertismentului din `bs.sql`: aceleași tabele și
coloane ca `bs.sql`, verificat) și un rând inventat în fiecare tabel al clienților (după oracol, nu după reguli), cu un
marcaj aleator în fiecare coloană pe care oracolul o cere schimbată, oricare i-ar fi regula, plus o recenzie aprobată
cu adrese de email în text; copia pe `cms_incarc_tinta`; referința `cms_incarc_ref` (aceeași sursă, copiată simplu și
trecută prin aceleași migrări). Parcurge: refuzurile (cod 2, nimic atins; și legătura cu baza sursei care nu merge,
fără nicio schimbare a copiei sau a `.env`-ului, și cu `--reincarca` peste o copie terminată), scanerul (o coloană
personală nouă, una oarecare, o vedere: cod 1, copia ștearsă singură), încărcarea (A1–A9 DA; niciun marcaj și nicio
valoare personală a sursei, după oracol, în nicio coloană a copiei; pe rândurile cu marcaj, fiecare valoare cerută
schimbată de oracol e alta decât în sursă, iar pe referință toate sunt la fel, deci verificarea poate pica; tabelele
copiate cum sunt = referința, CHECKSUM TABLE; coloanele păstrate ale celor anonimizate = referința; recenzia aprobată
cu adresele schimbate în pseudonime; pseudonimele la fel în toate tabelele; setările, migrările, licența, contul
proprietarului care se loghează, adminii site-ului care nu, D3, codul vechi, sursa și dosarul ei neschimbate,
`stare.php` „GATA”), sesiunea sursei doar în citire (și un utilizator doar cu SELECT), `--reincarca`, `--reia`
(`--buget=0`: un grup pe rulare, apoi „GATA”, cu aceleași pseudonime; resturile unui grup neterminat șterse;
refuzurile), câte o mutație pentru fiecare verificare A1–A9 (o regulă sau un pas stricat în copia codului: verificarea
ei NU, copia ștearsă), încă una pentru fiecare text liber al clienților și al adminilor lăsat cum e (subiectul și
mesajul de contact, notele adminului, eroarea ERP, motivul deblocării, al reducerii, eroarea plății, eticheta adresei:
A1 pică și numește coloana), o adresă într-o coloană păstrată (A1 numește coloana) și un refuz venit după ce copia s-a
schimbat (cod 1 și copia ștearsă, nu cod 2), `--demo`
(aceleași date inventate ca baza de dezvoltare, personalul se loghează; vertij fără și cu valori private inventate)
și aceeași încărcare pe MySQL 8 (`--doar-mariadb` o sare, spus în raport: decizia A1 a proprietarului). **Biovitality**
(WP-11b): în A regulile Biovitality (fără greșeli, versiunea legată de a Bioscem) acoperă fiecare tabel și coloană a schemei
codului Biovitality 779e0d8 (`bv.sql`) și a codului comun, oracolul cu tabelele doar ale Biovitality (jetoanele aplicației,
dosarele și jurnalele fișierelor din cloud fără rânduri; concurența și etichetele blogului publice) și o mutație care copiază
jurnalul fișierelor cum e, scanerul fail-closed (o coloană nouă cu date personale, `competition_partners.contact_email`, și una
doar necunoscută opresc, cu numele lor; un tabel nou fără rânduri), cheile `bunny_*` golite și A6 pe ele, id-urile Stripe,
procesatorul linkului și „dat de”; în **B10** un Biovitality „live” (`domains/biovitality.ro/public_html`, baza `cms_incarc_bv`
făcută din `bv.sql`, cu rândurile demo copiate în coloanele comune, câte un rând cu marcaj în fiecare tabel al clienților și în
cele fără rânduri, plus conținutul Biovitality: un pachet, concurenți, etichete, o comandă Stripe, setările fișierelor din cloud
cu chei inventate; valorile private ale înghețării inventate în `privat/` al copiei): scanerul oprește pe coloana nouă, mutația
jurnalului copiat pică la A1 cu coloana numită, apoi `--din=biovitality` „GATA” cu A1–A9 DA, niciun marcaj pe copie, tabelele
Biovitality fără rânduri, cele publice cu tot atâtea rânduri, id-urile Stripe `anon-<id>`, procesatorul Stripe pus de migrare,
cheile `bunny_*` golite, înghețarea Biovitality aplicată, codul vechi copiat; cu ea pe copie, `--din=bioscem` (și `--reincarca`)
refuzate; sursa neschimbată; `sterge.php` (cu rândul ATENȚIE pentru `privat/`, care dispare după `rm -r`). Din revizuirea
WP-11b, scanerul setărilor: în A o cheie necunoscută codului (una care arată a secret, `smtp_pass`, și una oarecare) oprește,
Bioscem și Biovitality, o cheie știută care arată a secret fără neutralizare face regulile greșite, iar lista albă e la zi; în
B2 o cheie `smtp_pass` în baza sursei oprește încărcarea înaintea oricărui rând. Pe tot parcursul: niciun marcaj, nicio parolă, niciun nume de bază sau de utilizator
și nicio cale a contului în ieșiri și în textele salvate. Cere baza de dezvoltare făcută de `instaleaza.sh` (copia
demonstrativă se compară cu ea). Bazele `cms_incarc_*` și utilizatorii lor se fac și se șterg acolo, pe ambele
servere.

`tools/dev/compara-calcule-local.sh` (poarta G-calc, și cu PHP-ul minim) scoate codul Bioscem însuși (commit-ul revizuit din
[de-portat.md](de-portat.md), aceeași copie ca la `compara-cu-baza.sh`) și, din WP-11b, pe cel Biovitality (la commit-ul lui
revizuit) și rulează trei teste. **`tools/dev/test-calcule-bv.php`** (WP-11b): baza `cms_calc_bv` făcută ca a codului
Biovitality (`bv.sql`, rândurile demo în coloanele comune, plățile doar ramburs și Stripe, plus comenzi inventate: un produs cu
transport gratuit singur în coș, un cupon cerut „ vara 10 ”, un produs-pachet, o plată Stripe, peste 13 kg), trecută prin
migrările cu `--site=biovitality` (pachetul într-o copie, cu valori private inventate și fără „provizoriu”) și cu licența
Biovitality: `compara-calcule.php --local --profil=biovitality` „TRECE”, „diferite 0”, „excluse: pachete 1”, „excluse: epuizate N” (comenzile doar
cu produse epuizate, cât numără baza), produsele epuizate scoase din celelalte cât numără baza, cheile ERP noi numărate pe fiecare caz; mutațiile (transportul gratuit pe produs ignorat, cuponul cu
spații necăutat compact, coletele la 13 kg, fără regula epuizatelor, fără excluderea coșurilor doar cu epuizate, fără
excluderea pachetelor, un cod vechi care trimite și el
o cheie declarată nouă) pică fiecare cu câmpul ei; refuzurile (alt profil; profilul Biovitality pe o bază fără
`bundle_items_json`); apoi amprenta paginilor pe codul Biovitality (+26) și pe codul comun cu pachetul Biovitality (+27), pe
aceeași bază, și comparația lor (informativă, în raport). **`tools/dev/test-calcule.php`**:
`compara-calcule.php --local` pe `cms_calc_t`, o copie a bazei de dezvoltare în care fiecare ramură a calculelor contează
(transportul 12 lei în București și 45 în provincie, plafonul de 40 sub el, gratuit de la 150 / 250 lei; TVA adăugat la preț
la două produse, cota de 11% la două), cu 35 de comenzi inventate (puncte peste disponibil, sub minim, fără cont, cu cupon;
cupoane fixe, procentuale, expirate, oprite, unice folosite, inexistente, pe produse, pe categorii, fără cumulare, pentru un
client, cu număr de articole; BBD, FANbox, firmă, București, reducere și transport puse de mână, precomandă, produse inactive
și șterse, coș gol, sub, între și peste pragurile transportului gratuit în București și în provincie, TVA adăugat și altă
cotă, o localitate cu km în plus): „TRECE”, „diferite 0”, fiecare tip de caz prezent, aceleași cupoane și puncte aplicate în
ambele coduri, diferențele declarate (azi niciuna) potrivite; fiecare rând al ieșirii are una dintre formele știute (doar
numere și nume), fără nume de produse, numere de comenzi, emailuri sau numele bazei; cupoanele puse la loc (CHECKSUM TABLE),
niciun fișier de sesiune; la fel cu prețurile fixe ale transportului (cu câte o mutație a prețului FANbox, a km
suplimentari și a pragului Bucureștiului, fiecare „PICĂ”), cu `--fara-cupoane-valide` (mai puține cupoane aplicate: punerea
lor contează), `--prin-fisiere`, `--php-vechi=php<min>`, `--n`. Apoi câte o mutație în codul comun (într-o copie,
`--cod-nou`): totalul, reducerea procentuală, prețul BBD, valoarea punctelor, transportul din admin, punctele promise, o cheie
a payload-ului ERP, o excepție, TVA-ul adăugat, subtotalul fără TVA al produselor cu TVA adăugat, cota TVA, Bucureștiul
nerecunoscut (în checkout și în admin), provincia cu prețul Bucureștiului, pragul provinciei în București, transportul fără
plafon (în checkout și în admin): fiecare trebuie să dea „PICĂ” și să-și numească câmpul, deci comparația chiar vede fiecare
calcul. Refuzurile locale (cod 2, nimic schimbat), lucrătorul pornit direct pe altă bază sau fără `_verificare`, o rulare
omorâtă după cupoane (următoarea le pune la loc) și o eroare fatală de memorie (cupoanele puse la loc pe loc). Diferențele
declarate (`--declarate`), cu o diferență voită într-o copie a codului comun (transport gratuit și o cheie ERP pe o singură
comandă): declarată exact „TRECE”, nedeclarată „PICĂ”, declarația fără diferență, cu un câmp în minus sau în plus, fără cheia
ERP sau cu alta, pe o comandă care nu e caz „PICĂ” cu declarația numită, fișierele greșite refuzate. La urmă, pe un site de
verificare simulat (`$CMS_DEV_DIR/calcule/acasa`: copia codului din git, `.env`-ul copiei, licența pusă de `licenta.php`,
codul vechi în `cod-vechi/bioscem/` cu `.env`-ul lui, baza `cms_calc_copie` cu `_verificare` și un utilizator cu parolă), cu
mediul gol: baza în urma codului (o migrare neaplicată) → migrările întâi, apoi „TRECE”, textul salvat 0600, nicio parolă,
nume de bază sau cale a contului; fiecare refuz al serverului (`_verificare` lipsă, neîncheiat, din altă sursă, prea vechi;
`incarcat-la` lipsă sau expirat; `.env` fără `SITE_COPIE_VERIFICARE=1` sau cu alt pachet; alt `HOME`; baza în `.env`-ul altui
site din cont; codul vechi lipsă, legătură sau cu `.env`-ul spre altă bază; `--cod-vechi`, `--local` și `--declarate` pe
server; licența cu cupoanele oprite; baza fără registrul migrărilor); un PHP sub minim; un PHP care încarcă pdo_mysql și
mbstring doar cu `-d` (`tools/dev/lib/php-fara-extensii.php`, ca sodium în `/opt/alt/php83` pe Hostinger): fără `-d` refuzul
cu reparația exactă; cu `-d` migrările și lucrătorii primesc aceleași `-d extension=…` (și prin fișiere) și „TRECE”, cu un
rând care spune ce s-a adăugat; mutația care nu le dă (copiii cu PHP_BINARY simplu) pică la migrări.
**`tools/dev/test-amprenta-site.php`**: fără server normalizarea, câmpurile unei pagini inventate, gazda sursă (pe gazda
copiei, linkurile absolute spre site-ul copiat sunt străine fără ea și ale site-ului cu ea), versiunea pozei de distribuire (în
calea rândului și la alegerea celor 20 de poze), adresele pe care unealta nu le cere, fișierul, comparația (câte o schimbare
pe fiecare câmp, corpul doar cu `--strict`, doar versiunea pozei alta „LA FEL”, alte gazde sursă refuz, o copie de verificare
față de un site) și refuzurile liniei de comandă; apoi, pe `cms_amprenta_t` (cu o poză de produs, pentru poza de distribuire,
și cu linkuri absolute spre serverul Bioscem în conținut, ca linkurile spre bioscem.ro din conținutul importat din WordPress:
în prima pagină, în descrierea unui produs și un canonical pus de mână), codul Bioscem (`CMS_DEV_PORT`+16), codul comun cu
pachetul Bioscem (+17, cu `--gazda-sursa` a serverului Bioscem) și o copie de verificare a codului comun (+18, cu poarta):
codul comun de două ori „LA FEL” cu `--strict`; Bioscem (cu poza copiată cu altă dată: altă versiune `/og/`) față de codul
comun „LA FEL” pe câmpurile structurale, adresele linkurilor absolute cerute pe ambele, și, cu `--strict`, „DIFERĂ” doar la
corpul lui `/robots.txt` (diferența declarată în `$deBioscem`); fără `--gazda-sursa` comparația refuză, iar paginile codului
comun citite fără ea au alte linkuri și alt canonical; politețea văzută din partea serverului (doar GET, User-Agent-ul
uneltei, fără cookie, nicio adresă interzisă, câte o cerere pe adresă, pauza); licența fără blog „DIFERĂ” (`/blog` 200 → 404,
articolele doar în amprenta Bioscem); copia fără parolă, cu parola greșită sau fără `--gazda-sursa` oprită după o singură
cerere, cu ambele „copie de verificare: DA” și, față de Bioscem, „LA FEL” (și pe paginile cu linkuri absolute) cu câmpurile
gărzilor spuse ca necomparate. Bazele, serverele și dosarele se fac și se șterg acolo; baza de dezvoltare doar se copiază.
