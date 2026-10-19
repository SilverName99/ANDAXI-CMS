# Site-ul de verificare

Site-ul de verificare e un site separat, pe găzduirea Hostinger, pe care se încearcă codul comun
înainte să ajungă la un client: aceleași pagini, aceeași bază de date (o copie anonimizată), plăți
doar de test, emailuri doar spre proprietar. **E închis publicului din prima cerere după deploy.**

Ce există acum (WP-08a): poarta, configurarea și verificarea serverului. Încă **nu se încarcă nicio
copie**: gărzile pentru date (emailuri, plăți, ERP, FAN, etichete de urmărire, fișiere încărcate,
banner) vin în WP-08, încărcarea anonimizată în WP-09. Până atunci site-ul arată, după parolă, doar
„Copia de verificare lipsește”.

| Fișier | Ce e |
|---|---|
| `src/Support/Verificare.php` | Poarta (prima în `public/index.php`) și ajutoarele ei; aici vin și gărzile din WP-08 |
| `src/Support/IpClient.php` | Adresa vizitatorului (`REMOTE_ADDR`, sau `X-Forwarded-For` doar de la un proxy de încredere) |
| `tools/verificare/configureaza.php` | Scrie `.env`-ul site-ului (o dată, pe server, prin SSH) |
| `tools/verificare/stare.php` | Ce e configurat, ce știe găzduirea, dacă site-ul e închis; doar DA/NU și versiuni |
| `tools/dev/test-verificare.php` | Testele (poarta G-verif), pe o găzduire simulată local |

## Pașii proprietarului (copiat și lipit)

### 1. O dată, în hPanel (aproximativ 10 minute)

1. Websites → site-ul nou → **SSL**: instalează certificatul gratuit.
2. Advanced → **PHP Configuration**: aceeași versiune ca la Bioscem (versiunea minimă finală e decizia
   OD-48; până atunci cea a Bioscem). Notează-o.
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
(`~/domains/<alt-site>/public_html/<sub>`) nu e acceptat de `configureaza.php`.

Ne trimiți: domeniul, versiunea PHP, rândul SSH și versiunea PHP a fiecăruia dintre cele 4 site-uri live
(hPanel → PHP Configuration). **Fără parole.**

### 2. Codul, cu hPanel → GIT (după ce îți scriem că poarta e pe GitHub)

Websites → site-ul nou → Advanced → **GIT** → Create a New Repository:

- **Repository**, după decizia OD-49 (încă deschisă):
  - dacă `ANDAXI-CMS` e încă **public**: `https://github.com/SilverName99/ANDAXI-CMS.git` (nu trebuie
    nicio cheie);
  - dacă l-ai făcut **privat**: `git@github.com:SilverName99/ANDAXI-CMS.git`. hPanel îți arată atunci o
    cheie SSH a site-ului (Generate SSH key / SSH key). O adaugi în GitHub → `ANDAXI-CMS` → Settings →
    Deploy keys → Add deploy key, **fără** „Allow write access” (doar citire).
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
#   emailul tău, căsuța de trimitere și parola ei (Enter = fără căsuță),
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
   sesiune).
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
și utilizatorul `cms_verif_test` se fac și se șterg acolo. Cu un PHP 7 instalat (`php7.0`, `php7.4`)
verifică și mesajul pentru un PHP prea vechi. Cu `python3` rulează `configureaza.php` și într-un
terminal adevărat (`tools/dev/lib/terminal.py`, un pty): Ctrl-C în mijlocul unei parole, cu și fără
pcntl, și SIGTERM lasă terminalul cu ecou; parola nu apare pe ecran, iar Backspace o corectează. Limita
pe /64 se încearcă prin `X-Forwarded-For`, cu serverul de test trecut ca proxy de încredere. Toate datele
sunt inventate.
