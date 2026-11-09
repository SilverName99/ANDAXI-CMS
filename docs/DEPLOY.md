# Deploy-ul din Etapa 2 (Hostinger)

Cum trece un site live de pe codul lui vechi pe codul comun, cum primește apoi versiuni noi și cum se întoarce, în
câteva minute, dacă ceva nu merge. Primul site e Biovitality (mai jos, „Noaptea comutării Biovitality”), al doilea Bioscem
(„Comutarea Bioscem”).

Totul se face din SSH, cu patru scripturi din `deploy/` și unul din `bin/`. Fiecare arată întâi **planul** (ce ar face,
fără nicio schimbare) și face ceva doar cu `--aplica`.

| Script | Ce face | Atinge un site? |
|---|---|---|
| `deploy/release.sh` | o versiune de cod (`~/platforma/releases/<versiune>/`) dintr-un commit, verificată | nu |
| `deploy/adopta.sh` | prima trecere a unui site pe codul comun: copii, mentenanță, comutare, migrări, verificări, deschidere | da |
| `deploy/actualizeaza.sh` | un site adoptat trece pe o versiune nouă; se întoarce singur dacă versiunea nouă pică verificările | da |
| `deploy/revino.sh` | site-ul înapoi pe codul vechi: codul exact cum era, baza din copia de dinaintea migrărilor | da |
| `deploy/plati-in-curs.sh` | ce e pe drum în baza site-ului (plățile BT începute sau autorizate și neîncasate, linkurile de plată, comenzile netrimise în ERP): doar numere, doar citire; înainte și după comutare | nu |
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
„Revenim în câteva minute”. Mentenanța are două trepte:

- **strictă** (cât se copiază baza, cât rulează migrările și cât se pune baza la loc): trece doar pingul (GET pe
  `/_andaxi/ping`). Plățile (`/webhook/`) și ERP-ul (`/api/erp/`) primesc și ele 503 cu `Retry-After`, iar procesatorii de
  plăți și ERP-ul le retrimit mai târziu; jetonul de mai jos deschide doar citiri (GET); nimic nu scrie în bază;
- **ușoară** (după migrări, cât se verifică site-ul nou): plățile și ERP-ul trec din nou, vizitatorii încă nu.

În amândouă, **cronurile nu rulează**: `bin/platforma` vede `MENTENANTA`, scrie un rând în
`site/storage/logs/platforma.log` și iese cu codul 75, fără să facă nimic. Pe primul rând al
fișierului stă jetonul deploy-ului: o cerere cu același jeton trece, așa se verifică site-ul nou înainte să-l vadă
oricine, iar tu îl vezi în browser cu adresa `https://<domeniu>/?andaxi_deploy=<jeton>` (scriptul ți-o scrie când e cazul).

### Mentenanța rămasă

O rulare oprită și uitată poate lăsa `site/MENTENANTA`: site-ul rămâne închis. Se vede:

- în ping: `curl -s https://<domeniu>/_andaxi/ping` arată `"mentenanta":"strict"` sau `"usoara"` și de câte secunde
  (`"mentenanta_de"`); `"nu"` când site-ul e deschis. Monitorul CRM-ului citește același ping;
- `ls -la ~/domains/<domeniu>/site/MENTENANTA` (data fișierului) și `tail ~/domains/<domeniu>/site/storage/logs/platforma.log`
  (cronurile sărite);
- scripturile din `deploy/` avertizează când fișierul are peste 3 ore.

Ce faci: rulezi din nou comanda care a rămas la jumătate (o continuă) sau `deploy/revino.sh`. Doar dacă știi sigur că nu
mai rulează niciun deploy și că site-ul nou e bun, îl ștergi de mână: `rm ~/domains/<domeniu>/site/MENTENANTA`.

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

**Rulează-le așa încât un SSH căzut să nu le oprească.** Cu `screen` sau `tmux`, dacă le are serverul (`screen -S deploy`,
iar după o reconectare `screen -r deploy`). Altfel, fără ele:

```sh
setsid nohup deploy/adopta.sh … --aplica > ~/deploy-$(date +%H%M).log 2>&1 &
tail -f ~/deploy-*.log          # urmărești; Ctrl+C oprește doar urmărirea, nu scriptul
```

Un script oprit totuși la jumătate (procesul omorât) se reia cu aceeași comandă: fiecare fază și fiecare redenumire de
dosar se țin minte, iar o redenumire rămasă la jumătate (de exemplu `public_html` mutat, dar cel nou încă nu pus) se
termină prima, înainte de orice altceva.

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
3. Valorile private ale înghețării (nu sunt în git): `~/domains/<domeniu>/site/privat/setari-inghetate.json`
   (`mkdir -p ~/domains/<domeniu>/site/privat` întâi). Trebuie să fie pentru exact fișierul de înghețare din versiunea
   urcată (câmpul `pentru` = sha256 al lui; altfel migrările se opresc cu codul 15): le primești de la noi sau, pentru
   Biovitality și Bioscem, le faci chiar pe server din codul live, cu `genereaza-setari.php --cod-vechi` (mai jos, „Noaptea
   comutării Biovitality” și „Comutarea Bioscem”). Cele făcute pe site-ul de verificare înainte ca fișierul să fie închis nu mai sunt bune.
4. Licența din CRM: `~/domains/<domeniu>/site/storage/licenta/licenta.json`, și cheia publică a CRM-ului la îndemână
   (`--cheie-licenta=`); mai jos, „Licența”.
5. Rândurile de cron ale site-ului, copiate din hPanel → Advanced → **Cron Jobs** într-un fișier, de exemplu
   `~/cron-<site>.txt`, câte unul pe rând, cu programul în față (`*/10 * * * * /usr/bin/php …/public_html/scripts/erp-sync.php`).
   Dacă `crontab -l` le arată, nu trebuie.
6. Dacă site-ul are un CDN în față: `IP_PROXY_DE_INCREDERE=<rețelele lui>` în `.env`-ul vechi (codul vechi nu-l citește;
   adoptarea îl copiază) — [MIGRARI.md](MIGRARI.md), „Pe server, la adoptare”, pasul 5.

### Licența

`licenta.json` îl semnează CRM-ul (crm.andaxi.ro: perechea de chei Ed25519 stă acolo, lista de pluginuri e a fiecărui
site), în formatul din [PLUGINURI.md](PLUGINURI.md), „Licența”: `"site"` = slug-ul (`biovitality`, același cu
`LICENTA_SITE`, pe care îl scrie adopta.sh), `versiune` ≥ 1, pluginurile pornite. Cheia privată nu iese din CRM. Pe server:

```sh
mkdir -p -m 770 ~/domains/<domeniu>/site/storage/licenta
install -m 640 <licenta.json din CRM> ~/domains/<domeniu>/site/storage/licenta/licenta.json
```

Dosarul trebuie să rămână scriibil pentru PHP-ul site-ului (pe Hostinger, același utilizator): acolo scrie site-ul
`licenta-buna.json` și `stare-aplicata.json`. Cheia publică a CRM-ului (base64, 32 de octeți) merge la `--cheie-licenta=`
și ajunge în `site/.env` ca `LICENTA_CHEIE_PUBLICA`; la pregătire `diagnostic.php --licenta` verifică semnătura cu ea.
Pentru Biovitality licența are `config/plugins.php` → `licente_dev.biovitality` plus `campanii` (cerut pe 5 noiembrie;
fără o campanie activă nu schimbă nimic în pagini, [PLUGINURI.md](PLUGINURI.md), „Campanii pe site”), toate `pornit`.

### Comenzile

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
| `verificari` | sodium (web și linia de comandă), PHP-ul, uneltele, spațiul, baza (drepturile, DEFINER-ul declanșatoarelor și rutinelor), pachetul, `privat/`, licența, codul vechi curat și la commit-ul revizuit, site-ul răspunde, PHP-ul web citește `site/`, cronurile vechi (fiecare trebuie să poată fi rescris) | nu văd nimic |
| `pregatire` | `site/` (`.env`, `RELEASE`, pachetul, `storage/`), `public_html.nou/` (stub-ul, `assets/`, `tema/`); `diagnostic.php --pachet` și `--licenta`, `migreaza.php --plan` | nu văd nimic |
| `copie` | copia codului vechi (`cod-vechi.tar.gz`, fără poze) și o copie a bazei | nu văd nimic |
| `mentenanta` | mentenanța **strictă**; `public_html` → `public_html.vechi`, `public_html.nou` → `public_html`; pozele mutate în `public_html/uploads/`; fișierele site-ului (sitemap-uri, `assets/.htaccess`, `.well-known/`…) aduse; așteptarea conexiunilor rămase pe bază; **copia bazei de revenire** (amprenta bazei înainte și după ea: dacă a scris cineva în timpul copiei, se oprește) | „Revenim în câteva minute”; plățile așteaptă |
| `migrari` | `migreaza.php --plan` (arătat), apoi `--aplica --site=<site>`, reluat cât cere bugetul de timp; la sfârșit **amprenta bazei** (CHECKSUM TABLE pe fiecare tabel), apoi mentenanța **ușoară** | „Revenim…”; plățile trec după migrări |
| `diagnostic` | `diagnostic.php --pachet` și `--licenta` | „Revenim…” |
| `verificare` | pingul (versiunea, schema `la_zi`), `/health` (și pachetul), prima pagină, un produs, `/admin/login`, `/cos`, un fișier din `assets/`, o poză, jurnalele PHP fără erori fatale noi | „Revenim…” |
| `deschidere` | `/health` (cu jetonul) trebuie să spună `schema: la_zi` (altfel `MENTENANTA` rămâne: baza nu e cea migrată, de exemplu una pusă la loc de o revenire); `MENTENANTA` ștearsă; prima pagină verificată fără jeton | site-ul nou |

La sfârșit scrie rândurile de cron (mai jos) și unde a pus copiile.

**La prima problemă se oprește**, cu motivul și ce poți face:

- înainte de comutare (`verificari`, `pregatire`, `copie`): site-ul a rămas pe codul vechi, neatins. Repari și rulezi din
  nou aceeași comandă: continuă de la faza care a picat.
- după comutare: site-ul rămâne în mentenanță. **Drumul 1**: repari și rulezi din nou aceeași comandă (continuă de la
  faza care a picat; migrările se pot relua oricând). **Drumul 2**: `deploy/revino.sh --site=<site> --aplica`.
  `--reia=<faza>` rulează din nou o fază terminată (de la `migrari` încolo; după ce comutarea s-a făcut sau a început,
  `--reia` pe o fază de dinaintea migrărilor e refuzat, chiar dacă faza `mentenanta` nu e încă marcată: copia codului
  vechi ar fi a stub-ului).
- o reluare cu alte opțiuni (altă `--versiune`, alt `--pachet`, altă `--cheie-licenta`) e refuzată: înainte de comutare
  o iei de la capăt cu `--reia=verificari`; după comutare continui doar cu aceleași opțiuni sau revii.
- dacă după un minut mai lucrează conexiuni pe bază (un cron lung al codului vechi), scriptul se oprește cu lista lor (ID,
  timp, instrucțiune) și lasă mentenanța **strictă** (codul nou e deja în `public_html`, iar baza încă nemigrată: plățile
  și ERP-ul primesc 503 și retrimit după reluare); rulezi din nou peste câteva minute, sau, dacă știi ce sunt,
  `--forteaza-liniste`.
- o adoptare a cărei revenire a început (`revino.sh` a pus baza la loc, a încărcat-o, sau a mutat dosare) nu se mai
  continuă: `adopta.sh` refuză (codul 2) și spune să termini revenirea cu `revino.sh`; la fel `actualizeaza.sh`. O revenire
  refuzată înainte de orice schimbare (datele noi, liniștea) nu contează: adoptarea continuă și o uită (una omorâtă după ce
  și-a pus mentenanța strictă: adoptarea pune la loc mentenanța ei, cu jetonul ei). La fel o revenire de dinainte de
  comutare care a început să șteargă `public_html.nou`: o termini cu `revino.sh`.
- comutarea pornește doar pe un `public_html.nou` întreg (cu semnul `.andaxi-nou`, pe care o revenire îl șterge primul);
  altfel refuză și îl refaci cu `--reia=pregatire`.
- migrările pornesc doar dacă baza e exact cea din copia de revenire (nimic scris de la copie); amprenta de după
  migrări se scrie o singură dată, după primele cereri ale codului nou (pașii de activare ai pluginurilor), și nu se
  rescrie: un `--reia=migrari` după o scriere de după migrări e refuzat.

## Revenirea

```sh
deploy/revino.sh --site=<site>            # planul: ce ar face și ce date noi s-ar pierde (o estimare, cu site-ul pornit)
deploy/revino.sh --site=<site> --aplica   # 1–3 minute (cât încărcarea copiei bazei)
```

Cât o actualizare (`actualizeaza.sh`) e neterminată (`site/deploy/actualizare.stare` fără „gata” sau „intoarsa”),
`revino.sh` refuză (codul 2): întâi o termini (aceeași comandă de actualizare merge mai departe sau se întoarce singură;
o întoarcere rămasă fără testul de fum trecut: `--accepta-revenirea` sau o versiune reparată), apoi revii.

1. mentenanța **strictă** (nimic nu mai scrie: nici vizitatorii, nici plățile, ERP-ul sau cronurile), apoi așteptarea
   conexiunilor rămase (liniște = trei probe la rând, la o secundă, fără nicio conexiune care lucrează; după o încărcare
   oprită, și fără conexiuni ale aceluiași utilizator care doar dorm pe bază, ca clientul `mysql` al încărcării: dacă
   rămâne una, o oprești din phpMyAdmin, „Kill” pe ID-ul din mesaj, sau cu `KILL <ID>`; `--forteaza-liniste` nu merge acolo);
2. **ce s-ar pierde**: amprenta bazei de acum (structura și `CHECKSUM TABLE` pe fiecare tabel) față de cea de la sfârșitul
   migrărilor. Orice tabel schimbat de atunci înseamnă date care s-ar pierde, oricare ar fi ele: o plată confirmată
   (un UPDATE pe `orders`), o comandă, o schimbare de stare sau un AWB din admin, un stoc venit din ERP, puncte de
   fidelitate, un cupon folosit, o adresă, o resetare de parolă, o recenzie. Atunci, și oricum după ce site-ul a fost
   deschis vizitatorilor, `--aplica` **refuză** și spune tabelele; mentenanța revine cum era;
3. dacă migrările au pornit: verifică copia (`gzip -t`, și că e copia **acestei** baze: numele și gazda din `.env`),
   șterge tabelele, vederile, rutinele și evenimentele de după copie, încarcă copia și o compară cu amprenta de la copie:
   „exact ca la copie”. Dacă diferă, se oprește în mentenanța strictă, fără să întoarcă codul, și ține minte asta în
   stare (o nouă încărcare ar ieși, de obicei, la fel). Până alegi, aceeași comandă refuză (codul 2) și site-ul rămâne în
   mentenanța strictă; alegi una: `--accepta-baza-diferita` (codul vechi pornește pe baza încărcată, cu diferențele
   arătate; refuzat dacă baza s-a schimbat de la încărcare: amprenta de atunci e ținută în stare), `--fara-baza` (baza
   rămâne cum e acum, fără altă încărcare; doar codul se întoarce) sau `--reincarca-baza` (copia se încarcă din nou, de
   la început, pentru o diferență întâmplătoare). Oricare, ne trimiți ieșirea;
4. pozele înapoi în codul vechi; `public_html` → `public_html.esuat-<data>`, `public_html.vechi` → `public_html`;
5. codul vechi comparat cu amprenta lui de la comutare (fiecare fișier, octet cu octet; la poze mărimea și data);
6. prima pagină a site-ului vechi verificată.

Când refuză, ai două feluri:

- `--fara-baza`: doar codul se întoarce, baza rămâne cum e (migrările doar adaugă coloane și tabele, deci codul vechi
  merge pe ea). **Nu se pierde nimic.** E alegerea obișnuită după ce site-ul nou a fost deschis.
- `--pierde-datele-noi`: baza exact cea de dinaintea migrărilor; tot ce arată lista se pierde (notezi întâi din admin).

O revenire oprită la jumătate se reia cu **aceeași** comandă: aceeași alegere despre bază (una pornită cu `--fara-baza`
nu devine, la reluare, una care pune baza la loc sub codul vechi deja pornit, și invers), mentenanța de dinaintea primei
rulări pusă la loc la un refuz cât revenirea n-a schimbat încă nimic, apoi uitată (o revenire de mai târziu, de exemplu
după o actualizare, o citește din nou; după ce a pornit încărcarea bazei sau o mutare a dosarelor, un refuz lasă
mentenanța strictă), iar o încărcare a copiei bazei rămasă la jumătate se reface de la început
(atunci `--fara-baza` e refuzat: baza nu e întreagă, codul vechi nu pornește pe ea; și `--forteaza-liniste`: încărcarea
oprită poate lucra încă pe bază, iar a doua ar porni peste ea). Rândurile noi din `orders`, `users`,
`product_reviews`… față de sfârșitul migrărilor se arată doar ca informație (o migrare poate adăuga rânduri: recenziile
copiate din tabelul vechi); hotărăște amprenta tabelelor. O redenumire imposibilă (amândouă dosarele există, sau niciunul)
o rezolvi de mână, fără să ștergi ceva: **înainte** de pas există doar sursa, **după** el doar ținta. Când amândouă
există, muți deoparte pe cea care nu trebuie (`mv <ținta> <ținta>.deoparte` dacă pasul nu s-a făcut,
`mv <sursa> <sursa>.deoparte` dacă s-a făcut); când niciuna nu există, pui una la loc (dintr-un `….deoparte` sau din
copie). Scriptul îți scrie căile exacte. Apoi aceeași comandă: pasul se termină singur (la fel la `adopta.sh` și
`actualizeaza.sh`); dacă le-ai pus altfel, `revino.sh` are
`--rezolvat-de-mana` (doar perechea imposibilă se lasă așa; o mutare care nu merge, de exemplu din lipsă de drepturi sau
de spațiu, oprește și cu el). Dacă pasul lăsat așa e comutarea, `revino.sh` mai merge pe drumul „adoptarea s-a oprit
înainte de comutare” doar cu codul vechi în `public_html` (checkout-ul git, la commit-ul de la verificări); altfel refuză
și nu schimbă nimic. Dacă e întoarcerea codului (`revino-cod`), recunoaște două așezări: **făcută** (codul vechi, la
commit-ul de la verificări, în `public_html`, codul nou în `public_html.esuat-<data>`, fără `public_html.vechi`: merge mai
departe ca după pas) și **neîncepută** (stub-ul în `public_html`, codul vechi în `public_html.vechi`, fără
`public_html.esuat-<data>`: face pasul din nou); altfel refuză, cu cele două așezări. După revenire pui înapoi în
hPanel cronurile vechi (dacă le-ai schimbat). `site/` rămâne; o adoptare nouă pornește de la început.

## O versiune nouă

```sh
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica         # versiunea nouă, <v>
deploy/actualizeaza.sh --site=<site> --versiune=<v>                     # planul (licența și migrările cu codul nou)
deploy/actualizeaza.sh --site=<site> --versiune=<v> --aplica
```

Fazele: verificări, pregătire (pachetul, `assets/` și `tema/` noi alături, copia stub-ului de acum), mentenanța
**strictă**, copia bazei, comutarea (pachetul, `assets/`, `tema/`, stub-ul), **migrările cu codul nou, înainte ca
`RELEASE` să se schimbe**, apoi `RELEASE` (cache-ul paginilor golit) și mentenanța ușoară, diagnosticul, testul de fum,
deschiderea. **Dacă ceva pică după comutare, se întoarce singur**: `RELEASE` (dacă apucase să se schimbe), pachetul,
`assets/`, `tema/` și stub-ul de dinainte, verificarea versiunii vechi și, dacă trece, site-ul deschis (codul de ieșire
rămâne 1). O întoarcere oprită la jumătate se termină la rularea următoare. Dacă nici versiunea veche nu trece testul de
fum după întoarcere, site-ul rămâne în mentenanță; de acolo: o versiune reparată (`--versiune=<reparația> --aplica`
merge mai departe), sau `--accepta-revenirea` (deschide site-ul pe versiunea veche, cu un avertisment); amândouă schimbă
ceva doar cu `--aplica`, fără el sunt doar planul. `release.sh
--sterge` nu atinge versiunile unei actualizări în curs.
Baza rămâne cum au lăsat-o migrările (doar adăugări); copia ei e în `site/deploy/copii/<data>-actualizare-<v>/`.
După o actualizare reușită rămân doar cele mai noi 5 copii ale actualizărilor (`--pastreaza-copii=N` schimbă numărul);
cele mai vechi se șterg, cu numele și spațiul eliberat în ieșire. Copia adoptării (`site/deploy/copii/<data>/`) și orice
copie pomenită în starea adoptării sau a actualizării de acum nu se șterg niciodată. Celelalte site-uri de pe
versiunea veche nu se schimbă. Înapoi pe versiunea de dinainte, de mână: aceeași comandă cu `--versiune=<veche>`
(și `--inapoi`, dacă baza are deja migrări pe care versiunea veche nu le știe).

## Cronurile

Rândurile noi pornesc prin lansator și citesc `RELEASE`, deci după o actualizare rulează singure codul nou:

```
*/10 * * * *  /opt/alt/php83/usr/bin/php -d extension=sodium.so /home/uXXXXXXXXX/platforma/bin/platforma --site=/home/uXXXXXXXXX/domains/biovitality.ro/site scripts/erp-sync.php
```

`adopta.sh` le scrie din cele vechi (același program, același script, aceleași opțiuni PHP și argumente; și pentru
`~/public_html/…`, legătura Hostinger spre domeniul principal, și pentru `cd …/public_html && php scripts/…`) în
`site/deploy/cron-nou.txt` și pe ecran: **ȘTERGI** rândul vechi, **ADAUGI** pe cel nou; un script care nu mai există
în codul comun (de exemplu o unealtă de depanare) doar se șterge; un rând care nu rulează codul vechi (un `curl` spre
`/health`) **RĂMÂNE**. Un rând care pomenește `public_html`, dosarul de acasă sau un script cunoscut, dar nu poate fi
rescris sigur (un script din afara `scripts/`, ghilimele, o conductă) e **DE VERIFICAT**: verificările se opresc până îl
rescrii tu și rulezi cu `--cron-de-mana`. Le schimbi în hPanel → Advanced → Cron Jobs, imediat după `GATA`. Până atunci cronurile vechi
pică inofensiv (fișierul lor nu mai e în `public_html`).

Orice script, de mână: `$PHP ~/platforma/bin/platforma --site=<domeniu> scripts/diagnostic.php --licenta`.

## Noaptea comutării Biovitality

### Înainte (zilele dinainte)

- [x] Repetiția pe site-ul de verificare, cu o copie a bazei Biovitality ([VERIFICARE.md](VERIFICARE.md); [MIGRARI.md](MIGRARI.md),
      „Pe server, la adoptare”, pasul 2): încărcarea trecută, `compara-calcule.php --profil=biovitality` 511 identice,
      0 diferite (cu 102 coșuri cu pachete), amprenta paginilor diferă doar prin ce scrie în `site-uri/biovitality/ce-se-schimba.md`
      (5 noiembrie).
- [x] Înghețarea setărilor Biovitality confirmată: `provizoriu` scos (`genereaza-setari.php --confirmat`), fișierul `inchis`
      (5 noiembrie). Sursa `Mutare-Site-Biovitality@d95598e` e commit-ul live: `git log -1` în `public_html` a dat `d95598e`,
      iar `genereaza-setari.php --cod-vechi` pe server a dat exact înghețarea din pachet.
- [ ] `git -C ~/domains/biovitality.ro/public_html status --short`: niciun fișier urmărit schimbat (`adopta.sh` îl verifică
      oricum). Fișierele noi (`??`) de pe biovitality.ro (5 noiembrie) cer `--accepta-fisiere-noi` la plan și la noapte;
      ce face adoptarea cu fiecare e mai jos, „Fișierele noi din codul vechi”. Exportul WordPress `.xml` mutat afară din
      `public_html` (făcut).
- [x] hPanel → biovitality.ro: PHP 8.3, **sodium** bifat, Auto deployment (GIT) oprit.
- [ ] O copie Hostinger recentă (hPanel → Backups), ca plasă în plus, chiar înainte de noapte.
- [ ] `~/domains/biovitality.ro/site/privat/setari-inghetate.json` și
      `~/domains/biovitality.ro/site/storage/licenta/licenta.json`: seara, mai jos.
- [x] Cronurile Biovitality copiate din hPanel în `~/cron-bv.txt`, câte unul pe rând, cu programul în față.

### Fișierele noi din codul vechi (`--accepta-fisiere-noi`)

Codul vechi trimite orice cerere în `public/` (`.htaccess`-ul din rădăcină: `RewriteRule ^(.*)$ public/$1`), deci un fișier
din rădăcina lui `public_html` nu se vede pe web. Cu `--accepta-fisiere-noi`, la comutare:

- din `public/` (în afară de `public/uploads/`), fișierele noi se aduc în noul docroot, fără `public/` în cale, dacă acolo
  nu există deja: `public/assets/.htaccess` → `public_html/assets/.htaccess`, `public/sitemap.xml` și `public/sitemap-nou.xml`
  → `public_html/`. Restul rămâne în `public_html.vechi/` (de acolo îl pune la loc `revino.sh`);
- `public/uploads/` (pozele) se **mută** în `public_html/uploads/`, deci `https://<domeniu>/uploads/…` merge ca azi (azi
  merge prin aceeași regulă spre `public/uploads/`, nu prin legătura `uploads` din rădăcină); legătura rămâne în
  `public_html.vechi/` și nu mai servește nimic;
- `erp/default.php`, `magazin/default.php` (pagina goală Hostinger), `storage/newsletter-cron.lock`: rămân în
  `public_html.vechi/`; nici azi nu se servesc (`/magazin/` e ruta magazinului, nu `magazin/default.php`). Nimic de mutat de mână;
- `/sitemap.xml` (și `/sitemap-pagini.xml` …) le face mereu codul comun din bază (`.htaccess`-ul stub-ului le trimite la
  `index.php` înaintea fișierelor), deci `sitemap.xml` adus nu se servește; `sitemap-nou.xml` nu e un nume al codului
  comun și se servește, neschimbat, ca azi;
- `assets/.htaccess` (Admin → Setări magazin → Caching) îl scrie codul comun din aceleași setări, în `public_html/assets/`
  (și `public_html/uploads/.htaccess`), la fiecare salvare a paginii Caching; `actualizeaza.sh` îl păstrează.

### Seara, înainte (aproximativ 15 minute; site-ul nu se schimbă)

```sh
ssh -p 65002 uXXXXXXXXX@<IP>
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
cd ~/platforma/repo && git fetch origin && git checkout --detach origin/claude/etapa1-cod-comun
# valorile private ale înghețării, pentru fișierul închis din acest commit, din codul live (doar citit):
$PHP tools/inghetare/genereaza-setari.php --site=biovitality --cod-vechi=$HOME/domains/biovitality.ro/public_html
#   „OK    biovitality: înghețarea din codul site-ului = cea din pachet (Mutare-Site-Biovitality@d95598e); scris …
#   (0600; 4 valori private: …); pachetul neatins”; altceva: te oprești și ne trimiți rândul
mkdir -m 700 -p ~/domains/biovitality.ro/site/privat
install -m 600 site-uri/biovitality/privat/setari-inghetate.json ~/domains/biovitality.ro/site/privat/setari-inghetate.json
mkdir -p -m 770 ~/domains/biovitality.ro/site/storage/licenta       # licența din CRM („Licența”, mai sus)
install -m 640 <licenta.json din CRM> ~/domains/biovitality.ro/site/storage/licenta/licenta.json
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica        # notezi versiunea: V=2026.11.…-…
V=<versiunea>
deploy/adopta.sh --site=biovitality --domeniu=biovitality.ro --versiune=$V --commit-vechi=d95598e \
    --cheie-licenta=<cheia publică din CRM> --cron-vechi=$HOME/cron-bv.txt --accepta-fisiere-noi
```

Ultimul rând trebuie să fie `PLAN: site-ul n-a fost schimbat …`, fiecare verificare `OK`, iar planul migrărilor cu
codul 10 („de aplicat, toate fără blocare”). Orice `NU` sau alt cod: ne trimiți ieșirea și nu mergi mai departe.
Valorile private se fac din nou (aceeași comandă) doar dacă se schimbă commit-ul: după un `git checkout` pe alt commit al
codului comun, `--cod-vechi` poate refuza („nu dă înghețarea din pachet”), fiindcă un fișier închis nu se mai generează.

### Noaptea (la o oră cu puține comenzi, de exemplu 23:30)

```sh
screen -S deploy            # dacă există; altfel comanda de mai jos cu setsid nohup … > ~/deploy-bv.log 2>&1 & și tail -f
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
cd ~/platforma/repo && V=<versiunea de seară>
deploy/adopta.sh --site=biovitality --domeniu=biovitality.ro --versiune=$V --commit-vechi=d95598e \
    --cheie-licenta=<cheia publică din CRM> --cron-vechi=$HOME/cron-bv.txt --accepta-fisiere-noi --aplica
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
  jurnalul (`~/domains/biovitality.ro/site/deploy/jurnale/`). Dacă revenirea refuză („tabele schimbate după
  migrări”: o plată a ajuns între timp), rulezi aceeași comandă cu `--fara-baza`.
- Site-ul e deschis, dar găsești o problemă gravă (comenzile nu se pot plasa): `deploy/revino.sh --site=biovitality`
  (planul spune ce tabele s-au schimbat de la deschidere), apoi `--aplica --fara-baza` (nimic nu se pierde) și
  cronurile vechi înapoi în hPanel. Cu baza pusă la loc (`--pierde-datele-noi`) doar dacă ai notat tot ce s-a schimbat.

### A doua zi dimineața

- `~/domains/biovitality.ro/site/storage/logs/fatal.log` gol, `php.log` fără `PHP Fatal`.
- Comenzile din noapte și de dimineață în admin, cu plata confirmată (Stripe → Developers → Webhooks: livrările
  reușite) și trimise în ERP (cronul `erp-sync.php`, Setări → ERP ANDAXI).
- Emailurile: `$PHP ~/platforma/bin/platforma --site=biovitality.ro scripts/diagnostic.php --email`.
- Cronurile noi au rulat (hPanel → Cron Jobs, ultima rulare) și FAN-ul își actualizează AWB-urile.
- Monitorul CRM-ului (pingul `/_andaxi/ping`) verde; în admin, Jurnalul 404 (adresele vechi căutate).
- Clienții logați aseară trebuie să se logheze din nou (sesiunile codului vechi nu trec); coșurile vechi se pierd.
- Stripe → Developers → Webhooks: livrările refuzate cu 503 în timpul mentenanței au fost retrimise cu succes.
- După 7 zile fără probleme: `public_html.vechi/` și copiile din `site/deploy/copii/` se pot șterge (ne întrebi întâi).

## Comutarea Bioscem

bioscem.ro e în același cont Hostinger ca biovitality.ro, deci `~/platforma` (repo-ul, versiunile, `bin/platforma`) e deja
acolo. Codul vechi: `~/domains/bioscem.ro/public_html`, checkout-ul git al `Nou-site-bioscem` (ramura
`claude/migrate-bioscem-site-bz38y4`), la **`c47f270`** (`git pull` din 6 noiembrie), commit-ul revizuit din
[de-portat.md](de-portat.md). Înghețarea setărilor Bioscem e închisă (`inchis`) pe 9 noiembrie, din `c47f270`. Ce are
Bioscem în plus față de Biovitality și cere grijă la comutare:

- **BT iPay în producție**, cu plata în două trepte: suma se blochează la plată și se încasează la aprobarea din ERP, cu
  butonul din comandă sau singură după 96 de ore (cronul `bt-ipay-sync.php`, la 15 minute). Banca trimite confirmarea la
  `https://bioscem.ro/webhook/bt-ipay` (aceeași adresă în codul comun), iar clientul se întoarce pe `/checkout/bt/retur`;
- **linkurile de plată EuPlătesc** (plata cu cardul prin EuPlătesc e oprită în checkout, dar linkurile se plătesc prin
  ea; confirmarea vine la `/webhook/euplatesc`);
- **ERP-ul** (comenzile pleacă în ERP, aprobarea vine din ERP pe `/api/erp/notificare`), **ridicarea personală** și FAN.

Ce face codul comun cu ele (doar dacă licența are pluginurile lor, mai jos):

- **Confirmările de plată din timpul mentenanței.** În mentenanța **strictă** (copia bazei, migrările: câteva minute)
  `/webhook/bt-ipay`, `/webhook/euplatesc` și `/api/erp/` primesc 503 cu `Retry-After: 120`; din mentenanța **ușoară** trec
  din nou. Stripe și ERP-ul retrimit o cerere refuzată. **Nu ne bazăm pe faptul că BT sau EuPlătesc retrimit**: nu
  scrie nicăieri că o fac. Pentru BT nici nu trebuie. Cronul `bt-ipay-sync.php` al codului comun întreabă banca
  (`getOrderStatusExtended`) de fiecare plată începută și neterminată și o trece în starea băncii. Dacă banca spune că e
  autorizată, comanda devine plătită. Doar o plată pe care banca o arată încă neplătită expiră, după 60 de minute.
  Clientul care se întoarce de la bancă chiar în mentenanță vede „Revenim în câteva minute”, dar banii lui sunt deja
  blocați la bancă, iar cronul îi găsește. Fereastra de risc ține deci doar până rulează cronul nou. De aceea, după
  `GATA`, rulezi `bt-ipay-sync.php` de mână, înaintea oricărui alt pas (mai jos). Pentru EuPlătesc codul n-are o
  verificare la procesator: o plată pe link făcută chiar în mentenanța strictă o cauți în panoul EuPlătesc a doua zi
  dimineața (mai jos).
- **Plățile BT autorizate și neîncasate de dinainte de comutare** rămân în același tabel (`bt_ipay_transactions`; migrarea
  `plata_bt/0008` doar îl găsește, nu rescrie nimic). Codul comun le citește la fel: termenul de 72 de ore (emailul cu
  lista) și cel de 96 de ore (încasarea automată) se socotesc de la autorizarea scrisă în tabel, aprobarea din ERP le
  încasează ca azi, iar Admin → Comenzi le arată cu „BT: autorizată — de încasat (automat la …)”. Banca cere încasarea
  în cel mult 5 zile, deci cronurile noi trebuie puse în hPanel imediat după `GATA`, nu a doua zi.
- **ERP-ul**: aprobările și adresele trimise în mentenanța strictă primesc 503 și ERP-ul le retrimite. `erp_trimite_din` e
  gol în înghețarea Bioscem (decizia G-M8), deci comenzile rămase „în așteptare” pleacă la prima rulare a lui `erp-sync.php`.
- **Linkurile EuPlătesc trimise** rămân în `order_payment_links` (procesatorul, starea, tokenul) și se deschid la aceeași
  adresă, `/plata/<token>`. Plata cu cardul prin EuPlătesc rămâne oprită în checkout: setarea e a site-ului, din bază.
- **Ridicarea personală**: bifa și programul din bază. Adresa sediului vine din înghețare dacă lipsește sau e goală, deci
  opțiunea arată ca azi.
- **Cheia linkurilor din newsletterele trimise** (`NEWSLETTER_CHEIE_SEMNARE`) o scrie `adopta.sh` în `site/.env`.

`deploy/plati-in-curs.sh --domeniu=bioscem.ro` arată ce e pe drum, doar în numere și ore, nimic despre clienți:

- plățile BT pe stări, câte sunt autorizate și neîncasate, de câte ore e cea mai veche și câte sunt începute și
  neterminate;
- linkurile de plată pe procesator și stare;
- comenzile din ultimele 30 de zile pe starea ERP;
- când a rulat ultima oară cronul BT.

Înainte de comutare citește `.env`-ul codului vechi, după comutare pe cel din `site/`. Doar citește, deci merge oricând.
Îl rulezi înainte și după comutare, iar textul lui ni-l trimiți așa cum e.

### Înainte (zilele dinainte)

- [ ] **Codul vechi rămâne `c47f270` până la comutare.** Nu mai dai `git pull` în `public_html`. Un commit nou ar trebui
      revizuit și portat, iar înghețarea, fiind închisă, nu se mai poate reface. hPanel → bioscem.ro → **GIT**: „Auto
      deployment” **oprit**. După comutare `public_html` nu mai e un repo, iar un deploy automat ar scrie peste stub.
      ```sh
      git -C ~/domains/bioscem.ro/public_html log -1 --format=%h      # trebuie: c47f270
      git -C ~/domains/bioscem.ro/public_html status --short          # ne trimiți tot textul
      ls -la ~/domains/bioscem.ro/public_html ~/domains/bioscem.ro/public_html/public
      ```
      Un fișier urmărit schimbat (` M`) oprește adoptarea: ne trimiți `git diff --stat`. Fișierele noi (`??`) cer
      `--accepta-fisiere-noi`. Ce face adoptarea cu fiecare e în „Fișierele noi din codul vechi”, mai sus; pentru
      Bioscem lista o facem noi din textul tău.
- [ ] hPanel → bioscem.ro → **PHP Configuration**: PHP 8.3 și **sodium** bifat la PHP extensions. Planul `adopta.sh` îl
      verifică oricum, cu sonda web.
- [ ] **CDN-ul.** Rulezi comanda de mai jos și ne trimiți rândurile.
      ```sh
      curl -sI https://bioscem.ro/ | grep -iE '^(server|via|cf-ray|x-hcdn|x-cache)'
      ```
      - `server: hcdn` = CDN-ul Hostinger. Din hPanel → bioscem.ro → **Performance → CDN** vezi dacă e pornit.
      - După comutare, Admin → Setări magazin (secțiunea mentenanței) arată „IP-ul tău curent”. Trebuie să fie adresa ta
        de acasă, cea din `curl -s https://ifconfig.me`. Dacă e o adresă a CDN-ului, toți vizitatorii împart aceeași
        limită a checkout-ului (10 încercări în 15 minute).
      - În acel caz: `IP_PROXY_DE_INCREDERE=<rețelele CDN-ului>` în `~/domains/bioscem.ro/site/.env`, cu rețelele de la
        noi ([MIGRARI.md](MIGRARI.md), „Pe server, la adoptare”, pasul 5). Dacă vrei să fie de la început, îl pui în
        `.env`-ul codului vechi înainte de noapte: codul vechi nu-l citește, iar adoptarea îl copiază.
- [ ] **Cronurile**: hPanel → Advanced → Cron Jobs, fiecare rând copiat în `~/cron-bs.txt`, cu programul în față.
      Așteptăm cel puțin `bt-ipay-sync.php` (la 15 minute), `erp-sync.php`, `fan-tracking-sync.php`, `fan-lockers-sync.php`,
      `fan-nomenclator-sync.php`, `abandoned-cart-emails.php` și `newsletter-campaigns.php`.
      - Un script pe care codul comun nu-l are (`cos-debug.php`, `import-*.php`, `set-*.php`, `verifica-*.php`) e șters
        din rândurile noi.
      - Un rând „DE VERIFICAT” din plan ni-l trimiți.
- [ ] **Licența din CRM** pentru `bioscem`. Pluginurile sunt lista din `config/plugins.php` → `licente_dev.bioscem`, aceeași
      cu `site-uri/bioscem/pluginuri.json`, toate `pornit`: `catalog`, `magazin`, `livrare_fan`, `plata_stripe`,
      `plata_euplatesc`, `plata_bt`, `link_plata`, `erp`, `cupoane`, `fidelitate`, `recenzii`, `precomenzi`,
      `oferte_bbd`, `produse_promo`, `conturi_clienti`, `blog`, `newsletter`, `chat_live`, `manechin`, `acorduri_gdpr`.
      - Fără `plata_bt` cronul BT nu face nimic, iar plățile autorizate nu se mai încasează singure.
      - Fără `plata_euplatesc` și `link_plata` linkurile trimise nu se mai plătesc.
      - `plata_stripe` rămâne până la decizia OD-33. `statistici` și `campanii` intră doar la cererea proprietarului.
- [ ] **Repetiția pe site-ul de verificare** ([VERIFICARE.md](VERIFICARE.md), pasul 6), cu valorile private făcute pe server,
      ca la Biovitality (pasul 6b). Noaptea, cu o copie a bazei din acea noapte:
      ```sh
      cd ~/domains/<domeniul-verificare>/public_html && git pull --ff-only
      PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
      git -C ~/domains/bioscem.ro/public_html log -1 --format=%h                  # c47f270
      $PHP tools/inghetare/genereaza-setari.php --site=bioscem --cod-vechi="$HOME/domains/bioscem.ro/public_html"
      #   „OK    bioscem: înghețarea din codul site-ului = cea din pachet (Nou-site-bioscem@c47f270); scris
      #   site-uri/bioscem/privat/setari-inghetate.json (0600; 4 valori private: contact_form_recipients, gdpr_operator_email,
      #   gdpr_operator_reprezentant, gdpr_operator_telefon); pachetul neatins”; altceva: mai jos, „Valorile private”
      $PHP tools/verificare/sterge.php                 # doar dacă pe copie e altă încărcare
      $PHP tools/verificare/stare.php                  # „GATA: site-ul de verificare e pregătit …”
      $PHP tools/verificare/incarca.php --din=bioscem  # „GATA: copia e anonimizată, toate verificările au trecut”
      $PHP tools/verificare/compara-calcule.php        # profilul bioscem (implicit): „REZULTAT: TRECE”, „diferite 0”
      # amprenta paginilor: pasul 6 (f) din VERIFICARE.md, ultimul rând „REZULTAT: LA FEL” (sau doar ce scrie în
      # site-uri/bioscem/ce-se-schimba.md)
      $PHP tools/verificare/sterge.php && rm -r site-uri/bioscem/privat
      ```
      Ne trimiți tot textul: încărcarea, comparația calculelor și amprentele.
- [ ] **Plățile de test BT**: în Setări plăți → Banca Transilvania → „Plată de test” nu rămâne niciuna „autorizată”. Le
      anulezi (reverse) înainte.
- [ ] O copie Hostinger recentă (hPanel → Backups), chiar înainte de noapte.

### Valorile private

Sunt cele patru valori „privat” ale înghețării Bioscem: destinatarii formularului de contact și datele operatorului de pe
acordul GDPR. Toate sunt scrise în codul Bioscem (`Settings::DEFAULTS`, `SiteController`), deci se fac pe server din
codul live, ca la Biovitality. Nu le trimitem noi.

`--cod-vechi` scrie fișierul doar dacă înghețarea generată acum e exact cea din pachet. Un commit al codului comun care
schimbă de atunci o valoare implicită o face să refuze („nu dă înghețarea din pachet”), deși fișierul închis e același.
Atunci rulezi comanda de mai jos, dintr-o copie a repo-ului la commit-ul care a închis înghețarea. Fișierul `privat/`
făcut acolo e bun pentru orice versiune urcată după, cât timp fișierul închis rămâne neschimbat.

```sh
cd ~/platforma/repo && C=$(git log -1 --format=%h origin/claude/etapa1-cod-comun -- site-uri/bioscem/setari-inghetate.json)
git worktree add ~/platforma/inghetare-bs "$C"
$PHP ~/platforma/inghetare-bs/tools/inghetare/genereaza-setari.php --site=bioscem --cod-vechi=$HOME/domains/bioscem.ro/public_html
#   „OK    bioscem: …”; fișierul e în ~/platforma/inghetare-bs/site-uri/bioscem/privat/setari-inghetate.json
```

Dacă refuză și de acolo („nu e codul revizuit”), codul de pe bioscem.ro nu e `c47f270`: te oprești și ne trimiți rândul.

### Seara, înainte (aproximativ 15 minute; site-ul nu se schimbă)

```sh
ssh -p 65002 u742855925@<IP>
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
cd ~/platforma/repo && git fetch origin && git checkout --detach origin/claude/etapa1-cod-comun
$PHP tools/inghetare/genereaza-setari.php --site=bioscem --cod-vechi=$HOME/domains/bioscem.ro/public_html
#   „OK    bioscem: înghețarea din codul site-ului = cea din pachet (Nou-site-bioscem@c47f270); scris …; pachetul neatins”;
#   „nu dă înghețarea din pachet”: „Valorile private”, mai sus (atunci fișierul e în ~/platforma/inghetare-bs/…)
mkdir -m 700 -p ~/domains/bioscem.ro/site/privat
install -m 600 site-uri/bioscem/privat/setari-inghetate.json ~/domains/bioscem.ro/site/privat/setari-inghetate.json
mkdir -p -m 770 ~/domains/bioscem.ro/site/storage/licenta           # licența din CRM („Licența”, mai sus)
install -m 640 <licenta.json din CRM pentru bioscem> ~/domains/bioscem.ro/site/storage/licenta/licenta.json
deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica        # o versiune nouă dacă etapa1 s-a mișcat; notezi V
V=<versiunea>
deploy/plati-in-curs.sh --domeniu=bioscem.ro                           # ce e pe drum acum (codul vechi); ne trimiți textul
deploy/adopta.sh --site=bioscem --domeniu=bioscem.ro --versiune=$V --commit-vechi=c47f270 \
    --cheie-licenta=<cheia publică din CRM> --cron-vechi=$HOME/cron-bs.txt [--accepta-fisiere-noi]
```

`--accepta-fisiere-noi` intră doar dacă îți scriem, după `git status --short`. Ultimul rând trebuie să fie `PLAN: site-ul
n-a fost schimbat …`, iar fiecare verificare `OK`. Și:

- planul migrărilor cu codul 10 („de aplicat, toate fără blocare”);
- la `diagnostic.php --pachet`: `poze refacute: 10 din 10 trec pe WebP` și niciun rând `atentie`;
- la `--licenta`: licența `bioscem`, semnătura bună;
- rândurile de cron: `ȘTERGI` / `ADAUGI`, fără `DE VERIFICAT`.

Orice `NU`, alt cod sau alt rând: ne trimiți ieșirea și nu mergi mai departe.

### Noaptea (la o oră cu puține comenzi, de exemplu 23:30)

Nu în minutele în care ERP-ul aprobă comenzi în masă. Plățile BT pornite chiar atunci se rezolvă singure, mai sus.

```sh
screen -S deploy            # dacă există; altfel setsid nohup … > ~/deploy-bs.log 2>&1 & și tail -f
export PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"
cd ~/platforma/repo && V=<versiunea de seară>
deploy/plati-in-curs.sh --domeniu=bioscem.ro > ~/bs-inainte.txt; cat ~/bs-inainte.txt
deploy/adopta.sh --site=bioscem --domeniu=bioscem.ro --versiune=$V --commit-vechi=c47f270 \
    --cheie-licenta=<cheia publică din CRM> --cron-vechi=$HOME/cron-bs.txt [--accepta-fisiere-noi] --aplica
```

Cât durează: ca la Biovitality, 2–5 minute până la mentenanță și 3–10 minute de mentenanță. Dacă SSH-ul cade, aceeași
comandă.

**Când apare `GATA`, în ordinea asta, imediat:**

1. **Cronurile**: în hPanel → Cron Jobs ștergi rândurile vechi și adaugi pe cele noi din
   `~/domains/bioscem.ro/site/deploy/cron-nou.txt`, întâi `bt-ipay-sync.php` și `erp-sync.php`.
2. **Plățile BT rămase pe drum**, de mână, fără să aștepți cronul:
   ```sh
   $PHP ~/platforma/bin/platforma --site=bioscem.ro scripts/bt-ipay-sync.php
   #   „[…] BT iPay: verificate N, expirate …, eliberate …, reîncercări încasare …, reamintiri …, încasate automat …, erori 0”
   $PHP ~/platforma/bin/platforma --site=bioscem.ro scripts/erp-sync.php
   deploy/plati-in-curs.sh --domeniu=bioscem.ro > ~/bs-dupa.txt; diff ~/bs-inainte.txt ~/bs-dupa.txt
   ```
   - „live: autorizate, neîncasate” și „cea mai veche autorizare neîncasată (ore)” trebuie să fie ca înainte. Pot crește
     doar cu plățile din timpul comutării.
   - „începute și neterminate” nu crește.
   - „cronul BT, ultima rulare” e de 0 minute.
   - Linkurile de plată sunt ca înainte.
   - Comenzile „pending” la ERP scad.
   - `erori` diferit de 0 sau o autorizare neîncasată mai veche de 100 de ore: ne scrii imediat.
3. **În browser** (fereastră privată):
   - paginile: prima pagină, o categorie, un produs, coșul și finalizarea comenzii (cu ridicarea personală, dacă era
     pornită);
   - Admin → Comenzi: o comandă cu plata BT autorizată arată „BT: autorizată — de încasat (automat la …)”, cu aceeași
     dată ca înainte;
   - Setări plăți → **Banca Transilvania**: modul **producție**, „Callback (notificarea băncii)” =
     `https://bioscem.ro/webhook/bt-ipay`, „Ultima rulare încheiată a cronului” de acum;
   - Setări plăți → Card – EuPlătesc: tot oprit în checkout;
   - Setări → ERP ANDAXI (fără erori noi);
   - Setări magazin: „IP-ul tău curent” (CDN-ul, mai sus);
   - în admin nu trebuie să apară „Licența lipsește” sau bannerul roșu al schemei.
4. **O plată de test BT în producție** (cu cardul tău):
   - Setări plăți → Banca Transilvania → „Plată de test”, suma 1 leu, cu datele tale;
   - plătești pe pagina băncii;
   - la întoarcere trebuie să vezi „a fost autorizată”, apoi apeși „Anulează (reverse)”.

   Așa verifici datele de acces ale băncii, întoarcerea și confirmarea pe codul nou.
5. `$PHP ~/platforma/bin/platforma --site=bioscem.ro scripts/diagnostic.php`: ultimul rând trebuie să fie `Gata.`.

### Dacă ceva nu merge

Ca la Biovitality, cu `--site=bioscem`. Scriptul s-a oprit după comutare: aceeași comandă, sau
`deploy/revino.sh --site=bioscem --aplica`. Dacă revenirea refuză („tabele schimbate după migrări”, de exemplu o plată
BT confirmată între timp): `--fara-baza`, ca nimic să nu se piardă. După o revenire pui înapoi cronurile vechi în hPanel.
Plățile BT autorizate rămân la bancă și le încasează din nou cronul codului vechi.

### A doua zi dimineața

- `~/domains/bioscem.ro/site/storage/logs/fatal.log` gol, iar `php.log` fără `PHP Fatal`.
- `deploy/plati-in-curs.sh --domeniu=bioscem.ro` (ne trimiți textul): cronul BT de cel mult 15 minute, „începute și
  neterminate” 0 sau aproape, nicio autorizare mai veche de 96 de ore.
- **BT**, portalul de comerciant: plățile din noapte, fiecare cu comanda ei plătită în admin.
- **EuPlătesc**, panoul de comerciant: o plată pe link din timpul nopții care în admin e încă neplătită ne-o scrii, cu
  numărul comenzii.
- ERP: comenzile din noapte trimise, aprobările de dimineață cu plata BT încasată.
- Emailurile: `$PHP ~/platforma/bin/platforma --site=bioscem.ro scripts/diagnostic.php --email`.
- Cronurile noi au rulat (hPanel → Cron Jobs). FAN își actualizează AWB-urile.
- Monitorul CRM-ului (`/_andaxi/ping`) e verde. În admin vezi Jurnalul 404 și fluxul `/feed/google-merchant.xml`.
- Clienții logați aseară se loghează din nou. Coșurile vechi se pierd.
- După 7 zile fără probleme: `public_html.vechi/`, `~/platforma/inghetare-bs` (`git -C ~/platforma/repo worktree remove
  ~/platforma/inghetare-bs`, dacă l-ai făcut) și copiile din `site/deploy/copii/` se pot șterge. Ne întrebi întâi.

## Ce nu face (încă)

- **Sesiunile codului vechi nu trec**: clienții și adminii se loghează din nou, coșurile deschise se pierd.
- **Plățile din timpul mentenanței stricte** primesc 503 și sunt retrimise de procesator mai târziu (Stripe: în câteva
  minute până la câteva ore); o comandă plătită chiar atunci apare „neplătită” până la retrimitere.
- **Un proces pornit înainte de mentenanță** (un cron lung al codului vechi) poate scrie încă: scriptul așteaptă cel
  mult un minut să termine (trei probe liniștite la rând) și se oprește dacă nu, iar amprenta bazei înainte și după copie prinde orice scriere.
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
- Testul: `tools/dev/mariadb-nestrict.sh ruleaza tools/dev/php.sh tools/dev/test-deploy.php` (poarta **G-deploy**, și cu
  PHP-ul minim; cere un MariaDB privat, `CMS_DEV_SOCK` dat explicit, și refuză serverul comun): o găzduire simulată cu
  două site-uri vechi (checkout git, baze făcute ca a codului Biovitality), trei versiuni (una cu prima pagină stricată)
  și injectări: o migrare care pică, un test de fum care pică, o licență stricată, o reluare cu altă versiune, procesul
  oprit între redenumiri (adoptare, revenire, actualizare), o copie a altei baze, un UPDATE după migrări și după
  deschidere, lacătul migrărilor ținut la o actualizare, open_basedir, o tranzacție deschisă pe bază (liniștea care nu
  vine, după comutare și într-o revenire reluată), o bază pusă la loc diferită de copie, `--rezolvat-de-mana` pe o mutare
  care nu merge, pe o comutare și pe o întoarcere a codului terminate de mână, `--reia=copie` după comutare,
  `--accepta-revenirea` fără `--aplica`, `--accepta-baza-diferita` (și după o scriere), `--reincarca-baza`, adopta.sh și
  actualizeaza.sh în timpul unei reveniri începute, deschiderea pe o bază care nu e la zi, o conexiune care doarme după o
  încărcare oprită, liniștea cu probe la rând; codul și baza comparate octet cu octet.
  `CMS_DEV_PASTREAZA=1` lasă găzduirea pentru cercetat. `plati-in-curs.sh` rulează acolo înainte de adoptare (codul vechi, un
  tabel lipsă) și după (`site/.env`), iar baza trebuie să rămână neatinsă.
- Stub-ul și `bin/platforma` le verifică și `tools/dev/test-paths.php` (B).
