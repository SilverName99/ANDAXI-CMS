# Site-ul de verificare

Site-ul de verificare e un site separat, pe găzduirea Hostinger, pe care se încearcă codul comun
înainte să ajungă la un client: aceleași pagini, aceeași bază de date (o copie anonimizată), plăți
doar de test, emailuri doar spre proprietar. **E închis publicului din prima cerere după deploy.**

Ce există acum: poarta, configurarea și verificarea serverului (WP-08a), cele **14 gărzi ale copiei**
(emailuri doar spre proprietar, plăți doar de test, ERP, FAN și EuPlătesc oprite, fără etichetele
terților, pozele de pe site-ul sursă, bannerul), licența de verificare și ștergerea copiei (WP-08).
Încărcarea anonimizată a unei copii vine în WP-09 (`incarca.php`); până atunci site-ul arată, după
parolă, doar „Copia de verificare lipsește”, iar `stare.php` se termină cu „GATA”: site-ul e pregătit
pentru date.

Tot de aici, din WP-10, rulează **verificările pe site-urile live** (pasul 5): citesc baza celor patru
site-uri doar în citire și scriu numai numere, DA/NU și nume de structură.

| Fișier | Ce e |
|---|---|
| `src/Support/Verificare.php` | Poarta (prima în `public/index.php`), ajutoarele ei și cele 14 gărzi ale copiei (WP-08) |
| `src/Support/IpClient.php` | Adresa vizitatorului (`REMOTE_ADDR`, sau `X-Forwarded-For` doar de la un proxy de încredere) |
| `tools/verificare/configureaza.php` | Scrie `.env`-ul site-ului și face cheia licenței de verificare (o dată, pe server, prin SSH) |
| `tools/verificare/stare.php` | Ce e configurat, ce știe găzduirea, dacă site-ul e închis și fiecare gardă; doar DA/NU și versiuni; ultimul rând „GATA” |
| `tools/verificare/licenta.php` | Pune pe copie un pachet de pluginuri (`bioscem`, `magazin`, `toate`, …), semnat cu cheia de pe server |
| `tools/verificare/sterge.php` | Șterge copia (baza, emailurile ca fișiere, cache-ul, sesiunile, codul vechi), după fiecare sesiune |
| `tools/verificare/lib/cont.php` | Contul SSH e comun cu site-urile live: o bază scrisă în `.env`-ul altui site e refuzată de toate uneltele de mai sus |
| `tools/verificare/agregate.php` (+ `seturi/`, `lib/`) | Verificările agregate pe un site live (WP-10, mai jos) |
| `tools/sonda/amprenta-schema.php` (+ `lib/`) | Sonda schemei unui site live (WP-10, MIG-08) |
| `tools/dev/test-verificare.php` (+ `lib/gaura-neagra.php`) | Testele (poarta G-verif), pe o găzduire simulată local, cu o plasă de siguranță care numără orice cerere spre afară |
| `tools/dev/test-sonde.php` | Testele verificărilor pe site-urile live (poarta G-sonde), tot pe o găzduire simulată |

## Pașii proprietarului (copiat și lipit)

### 1. O dată, în hPanel (aproximativ 10 minute)

1. Websites → site-ul nou → **SSL**: instalează certificatul gratuit.
2. Advanced → **PHP Configuration**: **PHP 8.3**, aceeași versiune ca la cele patru site-uri live
   (decizia OD-48: `php_min` = 8.3).
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
domeniul temporar dat de Hostinger (`….hostingersite.com`), cum e acum.

**Contul SSH e același cu al site-urilor live.** Comenzile de mai jos se dau doar din dosarul
site-ului de verificare (`cd ~/domains/<domeniul-verificare>/public_html`), niciodată din dosarul
unui site live; `configureaza.php` refuză oricum alt dosar decât `public_html`-ul site-ului lui, iar
o bază de date cu tabele care nu e o copie de verificare.

Ne trimiți: domeniul, versiunea PHP, rândul SSH și versiunea PHP a fiecăruia dintre cele 4 site-uri live
(hPanel → PHP Configuration). **Fără parole.**

### 2. Codul, cu hPanel → GIT (după ce îți scriem că poarta e pe GitHub)

Websites → site-ul nou → Advanced → **GIT** → Create a New Repository:

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
#   înainte de configurare, ultimul rând: „NECONFIGURAT: site-ul e închis publicului (503) …”
$PHP tools/verificare/configureaza.php
#   întreabă: numele bazei, utilizatorul bazei, parola bazei (nu se vede când scrii),
#   emailul tău (emailurile de test merg doar acolo; adresa stă doar în .env-ul de pe server,
#   niciodată în git), căsuța de trimitere și parola ei (Enter = fără căsuță),
#   parola porții (de două ori, cel puțin 12 caractere, alta decât parola bazei);
#   Ctrl-C oprește oricând, fără să scrie nimic
#   pe ecran doar DA/NU: legătura cu baza, „baza e goală”, „baza nu e a altui site din cont”,
#   „.env scris, doar pentru tine (0600)”, „cheia licenței de verificare făcută acum”, apoi „Gata.”
$PHP tools/verificare/stare.php
#   ultimul rând trebuie să fie: „GATA: site-ul de verificare e pregătit (toate verificările DA)”
```

`configureaza.php` face și **cheia licenței de verificare**: o pereche de chei pe care o are doar acest
server (cheia privată în `storage/licenta/`, doar pentru tine; cea publică în `.env`). Cu ea se pune pe
copie pachetul de pluginuri (mai jos, „Licența copiei”); niciun site adevărat nu o recunoaște.

Ne trimiți **tot textul** afișat de ultimul `stare.php`. Nu conține parole, chei, numele bazei sau adrese
de email: doar DA/NU, versiuni și setări PHP. Din el aflăm ce nu știm despre găzduire (versiunea PHP a
site-ului, dacă antetul parolei ajunge la PHP, ce adresă vede PHP-ul pentru vizitator, ce funcții sunt
oprite, ce server de baze de date are contul) și că fiecare dintre cele 14 gărzi ale copiei e la locul ei
(secțiunea „Gărzile copiei”, rândurile 1–14, toate DA). Abia cu „GATA” se poate încărca o copie (WP-09).

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
PHP=/opt/alt/php83/usr/bin/php
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

### 6. Licența copiei (pachetul de pluginuri)

Ce pluginuri are copia (de exemplu cele ale Bioscem, sau un pachet cu blogul oprit, ca să vezi ce
dispare) se alege tot din dosarul site-ului de verificare. Încărcarea copiei (WP-09) pune singură
pachetul site-ului copiat; comanda de mai jos e pentru încercări:

```sh
cd ~/domains/<domeniul-verificare>/public_html
PHP=/opt/alt/php83/usr/bin/php
$PHP tools/verificare/licenta.php presetari          # numele pachetelor: toate, bioscem, magazin, …
$PHP tools/verificare/licenta.php bioscem            # pluginurile Bioscem
$PHP tools/verificare/licenta.php bioscem blog=oprit # la fel, cu blogul oprit (pornit / se_opreste / oprit)
$PHP tools/verificare/licenta.php arata              # ce e pornit acum
```

Orice pachet poate urma oricărui alt pachet. Licența e semnată cu cheia de pe acest server, deci merge
doar aici: `licenta.php` refuză să ruleze din alt dosar sau pe un site care nu e cel de verificare.

### 7. După fiecare sesiune de verificare: ștergerea copiei

```sh
cd ~/domains/<domeniul-verificare>/public_html
PHP=/opt/alt/php83/usr/bin/php
$PHP tools/verificare/sterge.php
#   arată ce șterge și întreabă; scrii „da”
#   ultimul rând: „GATA: copia a fost ștearsă”
```

Șterge tabelele bazei copiei, emailurile păstrate ca fișiere, cache-ul, sesiunile și copia codului vechi
(`~/domains/<domeniul-verificare>/cod-vechi/`). Rămân `.env`, licența, cheia ei și rezultatele de la
pasul 5 (pe care le ștergi tu, după ce ni le trimiți). Șterge la fel și o încărcare oprită la jumătate.
Refuză să șteargă o bază care nu e o copie de verificare sau pe care o folosește alt site din cont. Dacă
uiți, copia se închide singură după 7 zile (decizia D1): site-ul arată „Copia de verificare a expirat”.

### Datele de pe copie și cine le vede

- **Datele sunt anonimizate la încărcare** (WP-09): numele, adresele, telefoanele devin inventate, iar
  emailurile clienților devin adrese `…@verificare.invalid`, un domeniu rezervat care nu poate primi
  niciun email. Oricum, pe copie **orice email pleacă doar la adresa ta** (`VERIFICARE_EMAIL`), cu
  „[VERIFICARE]” în subiect, cel mult 100 pe zi; în fiecare email un rând arată cui i-ar fi plecat pe
  site.
- **Paginile publice** (cu parola porții) le vedem și noi, ca să verificăm cum arată (decizia D8);
  bannerul roșu „SITE DE VERIFICARE” de sus arată acolo adresa ta doar pe jumătate, și la fel pe pagina de
  logare a adminului. **Adminul copiei e al tău**: conturile de admin le face încărcarea, pentru tine; abia
  după logare bannerul arată adresa întreagă.
- **Personalul clienților** (decizia OD-39): repetă noul admin doar pe o copie demonstrativă, cu pachetul
  site-ului lor și fără date personale (`incarca.php --demo --pachet=<site>`, WP-09), sau pe copia
  anonimizată a propriului lor site, într-o sesiune separată; **niciodată pe datele altui client**. Pe
  copie e mereu un singur client (`sterge.php` înainte de altul).

### Codul nou, mai târziu

hPanel → GIT → **Deploy** (sau prin SSH `git -C ~/domains/<domeniul-verificare>/public_html pull --ff-only`,
dacă `stare.php` arată „git pe server: DA”), apoi `$PHP tools/verificare/stare.php`. `.env` și `storage/` nu
sunt în git, deci rămân neatinse.

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
| „REFUZAT: legătura cu baza site-ului nu merge (eroarea 1045 …)” | Datele din `.env`-ul site-ului live nu deschid baza de pe acest cont; trimite-ne rândul (nu are parole) |
| „REFUZAT: … .env nu se poate citi” | PHP-ul nu are voie în dosarul celuilalt site; trimite-ne rândul |
| „PARȚIAL” sau rânduri EROARE / SĂRIT | Trimite-ne fișierul așa cum e; o verificare căzută nu le strică pe celelalte |
| „OPRIT: memoria PHP s-a terminat (memory_limit) …” | Trimite-ne fișierul (are verificările de până acolo). Dacă vrei și restul: aceeași comandă cu `$PHP -d memory_limit=512M tools/verificare/agregate.php --set=…` |
| Un rând „AVERTISMENT PHP: …” | Trimite-ne rândul; verificările merg mai departe (rândul are doar felul și locul erorii în codul uneltei) |
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
   aplicația, tot fără parolă, ca pe un site adevărat: `ok` și `schema: <stare>`, iar cu baza în urma
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

### Sonda (`/_verificare/sonda`)

`stare.php` scrie în `storage/verificare/sonda.json` amprenta unui jeton aleator (expiră în 2 minute) și
cere `/_verificare/sonda?sonda=<jeton>` cu acel jeton și ca parolă Basic, cookie și antet propriu. Poarta
răspunde, o singură dată, cu ce vede PHP-ul web: versiunea și SAPI, pe unde a sosit Basic (sau deloc), dacă
au sosit cookie-ul și antetul, clasa lui `REMOTE_ADDR` (loopback, privată, CGNAT, publică), dacă sosesc
`X-Forwarded-For`/`X-Real-IP`/`CF-Connecting-IP`/`Forwarded`, HTTPS, `max_execution_time`, `memory_limit`,
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
| 4 | robots.txt | poarta și `Robots::text()` | `User-agent: *` / `Disallow: /`, fără `Allow:` pentru fluxul de produse și fără `Sitemap:` |
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
(fără să urmeze o legătură: o legătură se șterge ea însăși). Păstrează `.gitkeep`, `.gitignore` și gardurile
`.htaccess` (sunt ale codului), `.env`, licența și cheia, contorul emailurilor zilei, jurnalele și
`storage/verificare/sonde/` (pe care le amintește). Tipărește doar numere. Refuză, fără să șteargă nimic: alt dosar
decât `~/domains/<gazdă>/public_html`, un `.env` fără `SITE_COPIE_VERIFICARE=1` sau fără `DB_DATABASE`, o bază cu tabele fără
`_verificare` sau scrisă în `.env`-ul altui site din cont (`tools/verificare/lib/cont.php`, ca la `configureaza.php`,
`stare.php` și `licenta.php`), un răspuns care nu e „da”.

**Înțelegerea cu încărcarea (WP-09).** `sterge.php` recunoaște copia doar după tabelul `_verificare`, deci
încărcarea îl scrie **primul**: imediat după verificarea că baza țintă e goală (sau are deja `_verificare`), înaintea
oricărui `CREATE TABLE` sau `INSERT` cu date, cu `anonimizat = 0` (și pasul la care a ajuns, pentru `--reia`); abia
la sfârșit, după aserțiuni, pune `anonimizat = 1` și scrie `incarcat-la`. Așa o încărcare oprită la jumătate (procesul
omorât de găzduire, o eroare înaintea ștergerii automate) rămâne o copie de verificare: poarta arată tot „Copia de
verificare lipsește” (fără `incarcat-la`), iar `sterge.php`, `incarca.php --reincarca` sau `--reia` o pot lua de la
capăt fără phpMyAdmin. `sterge.php` nu se uită la `anonimizat`: orice bază cu `_verificare` se șterge.

### Adresa vizitatorului (`IpClient`)

`IpClient::adresa()` întoarce `REMOTE_ADDR`. Doar când `REMOTE_ADDR` e într-una din rețelele din
`IP_PROXY_DE_INCREDERE` (adrese sau CIDR, separate prin virgulă; implicit nimic) ia, de la dreapta la
stânga, prima adresă din `X-Forwarded-For` care nu e de încredere. Ce scrie vizitatorul în antet stă în
stânga și nu se ajunge la el. Dacă `stare.php` arată că PHP-ul vede un proxy (o rețea privată sau a CDN-ului
în loc de adresa vizitatorului), toți vizitatorii împart aceeași limită de 10 încercări până se trece
rețeaua lui în `IP_PROXY_DE_INCREDERE`. O limită numără pe `IpClient::cheieLimita()`: IPv4 adresa
întreagă, IPv6 rețeaua /64. Limitele de încercări ale site-ului (checkout, înregistrare, recenzii) trec pe
`IpClient` în WP-12b.

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
| `VERIFICARE_SURSA`, `VERIFICARE_UPLOADS_DIN`, `VERIFICARE_WEBHOOKURI_DESCHISE` | `bioscem`, `https://bioscem.ro`, `0` | încărcarea (WP-09), pozele (garda 13), webhook-urile (poarta) |
| `VERIFICARE_EMAIL`, `VERIFICARE_SMTP_HOST`, `_PORT`, `_UTILIZATOR`, `_PAROLA`, `_CRIPTARE` | adresa proprietarului; căsuța lui (`smtp.hostinger.com`, `465`, ea, parola ei, `ssl`) sau gazda goală | garda 5 (toate emailurile spre proprietar; fără gazdă, fișiere `.eml`) |
| `VERIFICARE_ERP_URL`, `VERIFICARE_ERP_CHEIE` | lipsesc (ERP oprit, D5) | garda 6 (doar o copie `*-verif.erp.andaxi.ro`, mai târziu) |
| `VERIFICARE_FAN_TEST`, `VERIFICARE_EUPLATESC_TEST` | lipsesc | gărzile 7 și 10 (`1` = un cont de test al FAN, al EuPlătesc) |
| `BT_IPAY_MODE`, `BT_IPAY_TEST_*`, `BT_IPAY_LIVE_*` | `test`, gol, gol | aplicația (garda 9: mereu modul de test; `stare.php` cere `BT_IPAY_LIVE_*` goale) |
| `LICENTA_SITE`, `LICENTA_CHEIE_PUBLICA` | `verificare`, cheia publică a perechii făcute de `configureaza.php` | licența (`licenta.php`) |
| `SITE_DATE_DOSAR` | `<dosarul codului>/site-uri/bioscem` | harta adreselor vechi și regulile SEO |
| `SITE_SLUG` | `bioscem` | `scripts/migreaza.php` (pașii site-ului: înghețarea setărilor din pachet; fără el, cu pachetul Bioscem, migrările se opresc cu codul 2). Un `.env` scris înainte (fără cheie) o primește de mână sau cu `--rescrie` ([MIGRARI.md](MIGRARI.md), „Înghețarea setărilor”). Repetiția adoptării pentru Biovitality, vertij sau grafoanaytis schimbă de mână **amândouă**, `SITE_SLUG=<site>` și `SITE_DATE_DOSAR=<dosarul codului>/site-uri/<site>`, și copiază `site-uri/<site>/privat/` din dezvoltare lângă pachet (cu doar una schimbată, migrările se opresc cu codul 2); după repetiție se pun la loc valorile Bioscem. `--permite-provizoriu` (înghețările BV, VT, GR sunt provizorii) merge doar aici, cu `SITE_COPIE_VERIFICARE=1`; pentru Bioscem nu schimbă nimic |
| `ADMIN_DEFAULT_PASSWORD` | gol | nefolosit (adminul îl face încărcarea) |

`configureaza.php` scrie fișierul atomic (temporar 0600, apoi `rename`) și verifică înainte că fiecare
valoare se citește înapoi exact cum o citește `Env::load` (care taie spațiile și ghilimelele de la capete).
Refuză fără să scrie nimic: un dosar care nu e `~/domains/<gazdă>/public_html`, un `.env` existent (cu
`--rescrie`, doar unul cu `SITE_COPIE_VERIFICARE=1`), o bază care nu se deschide, are tabele fără
`_verificare` sau e scrisă în `.env`-ul altui site din cont (`~/public_html`, `~/domains/<d>/public_html`,
`~/domains/<d>/site`; doar `DB_DATABASE`/`DB_HOST` citite, în memorie, și pe ecran doar domeniul), parole ale
porții diferite, scurte (sub 12) sau egale cu parola bazei sau a căsuței, un terminal în care parola nu se poate
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
| `incarcat-la` | data încărcării copiei (ISO 8601 sau secunde Unix), scrisă de încărcare (WP-09), ștearsă de `sterge.php` |
| `emailuri-azi.json` | ziua, câte emailuri au plecat și câte au fost sărite (limita de 100 pe zi), 0600 |
| `mail/` | emailurile copiei ca fișiere `.eml` (0600), când copia n-are căsuță de trimitere; le șterge `sterge.php` |
| `tmp/` | fișierele temporare ale încărcării (WP-09); le șterge `sterge.php` |
| `sonde/` | rezultatele verificărilor pe site-urile live (pasul 5), 0600 într-un dosar 0700; le trimite proprietarul, apoi le șterge |

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
  Numerele se calculează în SQL (parolele adminilor se clasează în SQL, ca text, fără să fie citite);
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

- **WP-09**: `incarca.php --din=bioscem` (copia anonimizată în timp ce se citește, `_verificare` scris primul, cu
  `anonimizat = 0`, și `anonimizat = 1` + `incarcat-la` la final, ca la „Ștergerea”,
  emailurile `@verificare.invalid`, adminul proprietarului, licența `bioscem` prin `licenta.php`, codul vechi în
  `cod-vechi/`) și `incarca.php --demo --pachet=<site>` (pentru repetițiile personalului, OD-39).
- **WP-11**: calculele vechi față de cele noi pe copie (`compara-calcule.php`) și amprenta paginilor
  (`amprenta-site.php`, fără bannerul copiei).
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
rândul NU și să nu mai spună „GATA”); o copie „încărcată” (`cms_verif_copie`, o copie a bazei de dezvoltare cu
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
locului (cod 2, nimic citit), fiecare număr al fiecărui set (cât se schimbă după rândurile inventate), că
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
