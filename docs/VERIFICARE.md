# Site-ul de verificare

Site-ul de verificare e un site separat, pe găzduirea Hostinger, pe care se încearcă codul comun
înainte să ajungă la un client: aceleași pagini, aceeași bază de date (o copie anonimizată), plăți
doar de test, emailuri doar spre proprietar. **E închis publicului din prima cerere după deploy.**

Ce există acum (WP-08a): poarta, configurarea și verificarea serverului. Încă **nu se încarcă nicio
copie**: gărzile pentru date (emailuri, plăți, ERP, FAN, etichete de urmărire, fișiere încărcate,
banner) vin în WP-08, încărcarea anonimizată în WP-09. Până atunci site-ul arată, după parolă, doar
„Copia de verificare lipsește”.

Tot de aici, din WP-10, rulează **verificările pe site-urile live** (pasul 5): citesc baza celor patru
site-uri doar în citire și scriu numai numere, DA/NU și nume de structură.

| Fișier | Ce e |
|---|---|
| `src/Support/Verificare.php` | Poarta (prima în `public/index.php`) și ajutoarele ei; aici vin și gărzile din WP-08 |
| `src/Support/IpClient.php` | Adresa vizitatorului (`REMOTE_ADDR`, sau `X-Forwarded-For` doar de la un proxy de încredere) |
| `tools/verificare/configureaza.php` | Scrie `.env`-ul site-ului (o dată, pe server, prin SSH) |
| `tools/verificare/stare.php` | Ce e configurat, ce știe găzduirea, dacă site-ul e închis; doar DA/NU și versiuni |
| `tools/verificare/agregate.php` (+ `seturi/`, `lib/`) | Verificările agregate pe un site live (WP-10, mai jos) |
| `tools/sonda/amprenta-schema.php` (+ `lib/`) | Sonda schemei unui site live (WP-10, MIG-08) |
| `tools/dev/test-verificare.php` | Testele (poarta G-verif), pe o găzduire simulată local |
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
$PHP tools/verificare/stare.php
#   ultimul rând trebuie să fie: „PREGĂTIT PENTRU CONFIGURARE, NU PENTRU DATE”
```

Ne trimiți **tot textul** afișat de ultimul `stare.php`. Nu conține parole, numele bazei sau adrese de
email: doar DA/NU, versiuni și setări PHP. Din el aflăm ce nu știm despre găzduire (versiunea PHP a
site-ului, dacă antetul parolei ajunge la PHP, ce adresă vede PHP-ul pentru vizitator, ce funcții sunt
oprite, ce server de baze de date are contul).

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
| `agregate.php` / `amprenta-schema.php`: „REFUZAT: rulează doar din dosarul site-ului de verificare” | Ești în alt dosar: `cd ~/domains/<domeniul-verificare>/public_html`. Uneltele nu rulează niciodată din dosarul unui site live |
| „REFUZAT: nu găsesc site-ul live …” | Site-ul stă în alt dosar decât cel după domeniu: `ls ~/domains`, apoi aceeași comandă cu `--dosar=~/domains/<domeniu>/public_html` |
| „REFUZAT: găsesc mai multe dosare cu …” | Două dosare au același domeniu în `.env`; alegi dosarul site-ului live cu `--dosar=…` |
| „REFUZAT: … APP_URL are domeniul …, nu …” | Dosarul dat cu `--dosar` e al altui site; verifică domeniul |
| „REFUZAT: legătura cu baza site-ului nu merge (eroarea 1045 …)” | Datele din `.env`-ul site-ului live nu deschid baza de pe acest cont; trimite-ne rândul (nu are parole) |
| „REFUZAT: … .env nu se poate citi” | PHP-ul nu are voie în dosarul celuilalt site; trimite-ne rândul |
| „PARȚIAL” sau rânduri EROARE / SĂRIT | Trimite-ne fișierul așa cum e; o verificare căzută nu le strică pe celelalte |
| „OPRIT: memoria PHP s-a terminat (memory_limit) …” | Trimite-ne fișierul (are verificările de până acolo). Dacă vrei și restul: aceeași comandă cu `$PHP -d memory_limit=512M tools/verificare/agregate.php --set=…` |
| Un rând „AVERTISMENT PHP: …” | Trimite-ne rândul; verificările merg mai departe (rândul are doar felul și locul erorii în codul uneltei) |

## Cum funcționează (pentru programatori)

### Poarta

`App\Support\Verificare::poarta()` rulează prima în `public/index.php`, înaintea fișierelor încărcate, a
stratului SEO și a sesiunii. Citește `.env` (ca `Env::load`; `.env` are prioritate față de mediu):

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
   cere 200 întoarce codul la loc dacă `migreaza.php --aplica` n-a rulat).
5. Poarta are nevoie de `VERIFICARE_POARTA_UTILIZATOR`, de un hash valid în `VERIFICARE_POARTA_HASH` și de
   dosarul de lucru; altfel tot 503 „neconfigurat” (motivul îl arată `stare.php`).
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
funcțiile oprite, extensiile, `imagewebp`. Fără jetonul bun, adresa e tratată ca oricare alta (401).

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
| `SITE_COPIE_VERIFICARE` | `1` | poarta (și gărzile din WP-08) |
| `VERIFICARE_POARTA_UTILIZATOR`, `VERIFICARE_POARTA_HASH`, `VERIFICARE_POARTA_MOD` | `verificare`, `password_hash` al parolei porții, `basic` | poarta |
| `VERIFICARE_ZILE_PASTRARE` | `7` (cel mult 7) | poarta (expirarea copiei) |
| `IP_PROXY_DE_INCREDERE` | gol | `IpClient` |
| `VERIFICARE_SURSA`, `VERIFICARE_UPLOADS_DIN`, `VERIFICARE_WEBHOOKURI_DESCHISE` | `bioscem`, `https://bioscem.ro`, `0` | WP-08/WP-09 (încărcarea, pozele, webhook-urile) |
| `VERIFICARE_EMAIL`, `VERIFICARE_SMTP_*` | adresa proprietarului; căsuța lui (sau gol) | WP-08 (toate emailurile spre proprietar) |
| `BT_IPAY_MODE`, `BT_IPAY_TEST_*`, `BT_IPAY_LIVE_*` | `test`, gol, gol | aplicația (doar modul de test) |
| `LICENTA_SITE`, `LICENTA_CHEIE_PUBLICA` | `verificare`, gol (cheia o face WP-08) | licența |
| `SITE_DATE_DOSAR` | `<dosarul codului>/site-uri/bioscem` | harta adreselor vechi și regulile SEO |
| `SITE_SLUG` | `bioscem` | `scripts/migreaza.php` (pașii site-ului: înghețarea setărilor din pachet; fără el, cu pachetul Bioscem, migrările se opresc cu codul 2). Un `.env` scris înainte (fără cheie) o primește de mână sau cu `--rescrie` ([MIGRARI.md](MIGRARI.md), „Înghețarea setărilor”) |
| `ADMIN_DEFAULT_PASSWORD` | gol | nefolosit (adminul îl face încărcarea) |

`configureaza.php` scrie fișierul atomic (temporar 0600, apoi `rename`) și verifică înainte că fiecare
valoare se citește înapoi exact cum o citește `Env::load` (care taie spațiile și ghilimelele de la capete).
Refuză fără să scrie nimic: un dosar care nu e `~/domains/<gazdă>/public_html`, un `.env` existent (cu
`--rescrie`, doar unul cu `SITE_COPIE_VERIFICARE=1`), o bază care nu se deschide sau are tabele fără
`_verificare`, parole ale porții diferite, scurte (sub 12) sau egale cu parola bazei sau a căsuței, un
terminal în care parola nu se poate ascunde. Nu creează niciun tabel.

O parolă se citește tastă cu tastă, cu terminalul fără ecou și cu Ctrl-C citit ca o tastă
(`stty -echo -icanon -isig`, doar cât se scrie parola): la Ctrl-C scriptul pune singur terminalul
înapoi și abia apoi se oprește (cod 130). Oprit de semnal în acel mod, PHP-ul ar lăsa terminalul fără
ecou, iar proprietarul n-ar mai vedea ce scrie. SIGTERM și SIGHUP (găzduirea care oprește un proces)
sunt prinse la fel, dacă PHP-ul are pcntl; altfel rămâne `stty sane` din tabelul de mai sus. La
celelalte întrebări semnalele rămân cele obișnuite: Ctrl-C oprește pe loc.

`configureaza.php` și `stare.php` folosesc doar sintaxa PHP 7.0 (codul aplicației îl încarcă abia după
verificarea versiunii), ca un PHP prea vechi să primească un mesaj, nu o eroare de sintaxă. Nu pornesc
sesiuni (nu încarcă `bootstrap.php`).

### Dosarul de lucru

`Verificare::dosar()` = `storage/verificare/` din rădăcina codului (temporar: în WP-07 devine
`Paths::stocareSite('verificare')`, iar `.env` `Paths::env()`), cu un `.htaccess` „Require all denied”
(rădăcina trimite oricum totul în `public/`):

| Fișier | Ce e |
|---|---|
| `poarta-esecuri.json` | amprentele adreselor cu parole greșite, din ultimele 10 minute |
| `sonda.json` | amprenta jetonului sondei (2 minute, o singură folosire) |
| `incarcat-la` | data încărcării copiei (ISO 8601 sau secunde Unix), scrisă de încărcare (WP-09) |
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

- **WP-08**: gărzile din cod (emailuri doar spre `VERIFICARE_EMAIL`, cel mult 100 pe zi; ERP, FAN,
  EuPlătesc oprite; Stripe doar `sk_test_`; BT doar test; fără GA/GTM/Ads/Clarity/tawk.to; login Google
  oprit; pozele 302 spre `VERIFICARE_UPLOADS_DIN`; bannerul), cheia licenței de verificare în
  `configureaza.php`, `licenta.php`, `sterge.php`; `stare.php` termină cu „GATA”.
- **WP-09**: `incarca.php` (copia anonimizată, `incarcat-la`).
- **WP-07**: `Paths` în locul căilor de mai sus; `GET /_andaxi/ping` fără parolă.

### Testele

`tools/dev/php.sh tools/dev/test-verificare.php` (poarta G-verif din `tools/dev/porti.sh`, rulată și cu
PHP-ul minim): face o copie a codului (fișierele din git, cu modificările necomise) într-o găzduire
simulată, `$CMS_DEV_DIR/verificare/acasa/domains/verificare.exemplu.test/public_html`, o servește cu
`php -S` pe `CMS_DEV_PORT`+7 cu un mediu gol (ca pe Hostinger, totul din `.env`) și parcurge: deploy fără
`.env` (503 la orice), fiecare refuz al `configureaza.php` și configurarea pe o bază goală, poarta (401,
429, formularul, cookie-ul, sonda, modul formular), `stare.php` în ambele stări (ieșirea lui întreagă
intră în raport), o copie „încărcată” pe baza de dezvoltare (paginile 200, cu noindex și no-store) și una
expirată, apoi `SITE_COPIE_VERIFICARE=0` (poarta nu face nimic). Bazele `cms_verif_gol`, `cms_verif_plina`
și utilizatorul `cms_verif_test` se fac și se șterg acolo. Cu un PHP sub minim instalat (`php7.0` … `php8.2`)
verifică și mesajul pentru un PHP prea vechi. Cu `python3` rulează `configureaza.php` și într-un
terminal adevărat (`tools/dev/lib/terminal.py`, un pty): Ctrl-C în mijlocul unei parole, cu și fără
pcntl, și SIGTERM lasă terminalul cu ecou; parola nu apare pe ecran, iar Backspace o corectează. Limita
pe /64 se încearcă prin `X-Forwarded-For`, cu serverul de test trecut ca proxy de încredere. Toate datele
sunt inventate.

`tools/dev/php.sh tools/dev/test-sonde.php` (poarta G-sonde, și cu PHP-ul minim) încearcă verificările pe
site-urile live într-o găzduire simulată în `$CMS_DEV_DIR/sonde/`: site-ul de verificare (doar uneltele) și
patru site-uri „live” inventate (`bioscem.ro`, `www.grafoanaytis.ro`, `biovitality.ro`, `vertij-tinitus.ro`),
toate pe `cms_sonde_t`, o copie a bazei de dezvoltare, cu rânduri inventate pentru fiecare verificare (toate
datele personale cu un marcaj aleator). Verifică garda instrucțiunilor și tiparele ieșirii, fiecare refuz al
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
