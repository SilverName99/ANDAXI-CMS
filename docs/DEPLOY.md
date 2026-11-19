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

**Înfățișarea și fișierele vechi din git.** Pe codul comun, paginile au stilurile nucleului plus ce aduce tema din pachet
(`tema/tema.json`, [PACHETE.md](PACHETE.md), „Tema”); foile, scripturile și fonturile pe care le lega `views/layout.php` al
codului vechi nu mai vin singure. Iar din `public/` al codului vechi adoptarea aduce în docroot doar fișierele pe care git
le ignoră (sitemap-uri, fișiere de verificare a domeniului); cele ținute în git (poze din `/assets/img/`, PDF-uri) rămân
în `public_html.vechi/public/`, iar paginile care le folosesc ar primi 404, dacă nu le are pachetul (`public/`). Planul și
faza `verificari` le spun, ca ATENȚIE (nu opresc): „views/layout.php al codului vechi leagă foi de stil … și pachetul n-are
tema/tema.json” și „fișiere publice ale codului vechi, din git, pe care nu le aduce nici versiunea, nici pachetul”. Prima
înseamnă că pachetul nu e gata; a doua, fișierele de copiat de mână după comutare (le păstrează și actualizările):

```sh
cd ~/domains/<domeniu>
for f in <fișierele din ATENȚIE>; do mkdir -p "public_html/$(dirname "$f")"; cp -n "public_html.vechi/public/$f" "public_html/$f"; done
```

Și, înainte de adoptare, **comparația vizuală** a paginilor publice, codul vechi față de codul comun cu pachetul site-ului,
pe aceeași bază (tools/dev: [DEZVOLTARE.md](DEZVOLTARE.md)); apoi, după comutare, aceleași pagini în browser.

### Licența

`licenta.json` îl semnează CRM-ul (crm.andaxi.ro: perechea de chei Ed25519 stă acolo, lista de pluginuri e a fiecărui
site), în formatul din [PLUGINURI.md](PLUGINURI.md), „Licența”: `"site"` = slug-ul (`biovitality`, același cu
`LICENTA_SITE`, pe care îl scrie adopta.sh), `versiune` ≥ 1, pluginurile pornite. Cheia privată nu iese din CRM. Pe server:

```sh
mkdir -p -m 770 ~/domains/<domeniu>/site/storage/licenta
install -m 640 <licenta.json din CRM> ~/domains/<domeniu>/site/storage/licenta/licenta.json
```

Dosarul trebuie să rămână scriibil pentru PHP-ul site-ului (pe Hostinger, același utilizator): acolo scrie site-ul
`licenta-buna.json`, `stare-aplicata.json` și, cu preluarea automată, `licenta.json` însuși și `sincronizare.json`. Cheia publică a CRM-ului (base64, 32 de octeți) merge la `--cheie-licenta=`
și ajunge în `site/.env` ca `LICENTA_CHEIE_PUBLICA`; la pregătire `diagnostic.php --licenta` verifică semnătura cu ea.
Pentru Biovitality licența are `config/plugins.php` → `licente_dev.biovitality` plus `campanii` (cerut pe 5 noiembrie;
fără o campanie activă nu schimbă nimic în pagini, [PLUGINURI.md](PLUGINURI.md), „Campanii pe site”), toate `pornit`.

**Preluarea automată** ([PLUGINURI.md](PLUGINURI.md), „Preluarea automată”): după prima licență, site-ul își ia singur
licența curentă de la CRM la 5 minute, deci un plugin bifat (sau oprit) în CRM se aplică fără niciun fișier urcat. O
singură dată pe site:

1. CRM → Site-uri → site-ul → **Preluare automată** → **Generează cheia site-ului**. Cheia se arată o singură dată: o
   copiezi direct în `.env`-ul site-ului de pe server (nu pe chat, nu pe email). Una nouă o înlocuiește pe cea veche.
2. În `~/domains/<domeniu>/site/.env` (0600):

   ```
   LICENTA_URL=https://crm.andaxi.ro/api/site/licenta
   LICENTA_CHEIE=<cheia din CRM>
   ```

3. Cronul `scripts/licenta-sincronizare.php`, la 5 minute (mai jos, „Cronurile”).
4. Verifici: `$PHP ~/platforma/bin/platforma --site=<domeniu> scripts/licenta-sincronizare.php` scrie `Licența: ok: …`
   sau `Licența: la_zi: …`; în CRM, la site, apare „Ultima preluare de site: <ora>, versiunea <n>”; în admin, la
   Abonamentul tău, „Ultima verificare: … La zi (versiunea <n>)”, cu butonul **Verifică acum**.

Fără cele două rânduri din `.env`, totul merge ca înainte (licența se urcă de mână). Un plugin nou al unei versiuni noi
de cod (cu o migrare nouă) cere tot actualizarea obișnuită (`deploy/actualizeaza.sh`).

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

Preluarea automată a licenței (al nucleului: nu e un rând vechi de rescris, se **ADAUGĂ** o dată, după ce `.env`-ul are
`LICENTA_URL` și `LICENTA_CHEIE`; „Licența”, mai sus):

```
*/5 * * * *  /opt/alt/php83/usr/bin/php -d extension=sodium.so /home/uXXXXXXXXX/platforma/bin/platforma --site=/home/uXXXXXXXXX/domains/biovitality.ro/site scripts/licenta-sincronizare.php
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

## Adoptarea grafoanaytis

Înghețarea e închisă pe commit-ul live `d3c4b79` (confirmat pe 17 noiembrie 2026: `git log -1` și `git status --short` curat în
`~/domains/grafoanaytis.ro/public_html`; `grafoanaytis-ro-557157.hostingersite.com` e doar domeniul temporar Hostinger, fără git).
Comenzile serii și ale nopții sunt ca la „Comutarea Bioscem”, cu `--site=grafoanaytis --domeniu=grafoanaytis.ro
--commit-vechi=d3c4b79` (fișierele `~/cron-gr.txt`, `~/deploy-gr.log`); fără pașii plăților BT și ai ERP-ului (site-ul n-are
magazin). Pluginurile vin din licența CRM (abonamentul de azi: catalogul și cererile de ofertă, decizia OD-23); înainte de
comutare, linkurile spre `/acorduri-gdpr` (meniu, subsol, pagini) se scot dacă pluginul Acorduri GDPR nu e în licență
(`site-uri/grafoanaytis/ce-se-schimba.md`).

**Prima adoptare (17 noiembrie 2026) a fost întoarsă**: pachetul n-avea temă, deci paginile publice au ieșit cu stilurile
nucleului (filmul din primul ecran pe tot ecranul, conținutul fără așezare, bara de derulare orizontală). Codul vechi
încărca pe paginile publice Rubik, Bootstrap 5.3.8, `tokens.css` (nu și `app.css`, scos de pe paginile publice) și, la
final, `bootstrap.bundle.min.js`, `imagini.js`, `sertar-oferta.js`, `miscare.js`. Acum le aduce tema
`site-uri/grafoanaytis/tema/tema.json` (fontul și Bootstrap-ul din nucleu, `tokens.css` și blocul catalogului din modul
prezentare ca foi ale temei, `imagini.js` și `miscare.js` ca scripturi ale ei, `sertar-oferta.js` al pluginului
`cereri_oferta`; `main` „lat”, mesajele flash Bootstrap, butonul „Înapoi sus”, turcoazul `#0f766e` al bannerului de
cookie-uri și al paginii 404), iar pozele din `/assets/img/` ținute în git de codul vechi (sigla, sigla AFAS, banda de
sigle, pozele de producție și de produs) le are pachetul în `public/assets/img/`.

Pașii în plus față de „Comutarea Bioscem”:

- [ ] **Seara, înainte: comparația vizuală (obligatorie).** Două servere alături: codul vechi `d3c4b79` (`git archive`) pe
      baza lui și codul comun cu `site-uri/grafoanaytis/` pe o copie a ei trecută prin `scripts/migreaza.php --aplica
      --site=grafoanaytis` (ca la adoptare; `public/` al pachetului copiat peste `public/`, plus cele 7 fișiere de mai jos).
      Baza: copia locală a celei live sau, fără ea, cea făcută de codul vechi (`scripts/install.php`, o cerere la site,
      apoi `scripts/seed-design.php`, `seed-pagini.php` și `seed-produse.php` cu `--suprascrie`), cum s-a făcut pe 17
      noiembrie. Capturi (Playwright, `deviceScaleFactor` 1) la 1440 și 390 px: prima pagină, `/produse`, un produs
      cu sertarul „Solicită mostre și ofertă de preț” deschis, `/contact`, `/companie`, `/certificari`, o pagină legală, un
      404, meniul de pe telefon, bannerul de cookie-uri. Trebuie să arate la fel, fără bară de derulare orizontală;
      diferențele știute sunt în `site-uri/grafoanaytis/ce-se-schimba.md`. Nu se adoptă cu o diferență nescrisă acolo.
- [ ] **Planul** (`adopta.sh … --plan`) arată un singur ATENȚIE despre înfățișare: cele 7 fișiere publice din git ale
      codului vechi pe care pachetul nu le are: certificatele scanate (cu numele și semnăturile celor care le-au emis) și
      poza de la stand (cu oameni) au date personale, deci nu intră în repo-ul public; PDF-urile proiectului nu pot sta în
      pachet (în `public/` doar imagini, iar anunțul de angajare are și numele unei persoane):
      `assets/img/certificari/cert-eurocert-conformitate-4069.webp`, `assets/img/certificari/cert-eurocert-proces-4068.webp`,
      `assets/img/certificari/cert-infocons-marca-increderii.webp`, `assets/img/certificari/cert-iso-9001-evalcertprod.webp`,
      `assets/img/companie/productie-2-stand.webp`, `documente/anunt-recrutare-332198.pdf`,
      `documente/comunicat-presa-proiect-332198.pdf`. Un ATENȚIE despre foile de stil („pachetul n-are tema/tema.json”)
      înseamnă o versiune fără temă: nu se adoptă.
- [ ] **Imediat după `GATA`**, înaintea verificării în browser: cele 7 fișiere, din codul vechi (benzile de sus ale
      fiecărei pagini trimit la cele două PDF-uri: obligația de informare a proiectului):
      ```sh
      cd ~/domains/grafoanaytis.ro
      for f in assets/img/certificari/cert-eurocert-conformitate-4069.webp assets/img/certificari/cert-eurocert-proces-4068.webp \
               assets/img/certificari/cert-infocons-marca-increderii.webp assets/img/certificari/cert-iso-9001-evalcertprod.webp \
               assets/img/companie/productie-2-stand.webp documente/anunt-recrutare-332198.pdf \
               documente/comunicat-presa-proiect-332198.pdf; do
          mkdir -p "public_html/$(dirname "$f")"; cp -n "public_html.vechi/public/$f" "public_html/$f"; done
      curl -sI https://grafoanaytis.ro/documente/comunicat-presa-proiect-332198.pdf | head -1     # 200
      curl -s https://grafoanaytis.ro/ | grep -c '/tema/css/tokens.css'                          # 1
      ```
- [ ] **În browser** (fereastră privată, pe calculator și pe telefon): aceleași pagini ca seara, față de capturile codului
      vechi; benzile de sus, sigla, filmul din primul ecran cu butonul „Oprește filmul de fundal”, cifrele care cresc, banda
      de sigle, `/certificari` cu cele patru certificate, sertarul de ofertă (trimis o dată, cu adresa ta), butonul
      „Înapoi sus”. Fără bară de derulare orizontală. Ceva altfel decât seara: `deploy/revino.sh --site=grafoanaytis
      --aplica` și ne trimiți capturile.

## Adoptarea vertij: ce cer pluginurile `donatii` și `membri`

Înghețarea e închisă pe commit-ul live `9ab7999` (confirmat pe 12 noiembrie 2026: `git log -1` în `public_html`); comenzile serii și ale
nopții sunt ca la „Comutarea Bioscem”, cu `--site=vertij --domeniu=vertij-tinitus.ro --commit-vechi=9ab7999`. Pe lângă pașii obișnuiți ai adoptării
(mai sus) și `site-uri/vertij/ce-se-schimba.md`:

- **Documentele cererilor de membru.** Codul vertij le ține în `public_html/storage/account-documents/`; codul comun în
  `site/storage/account-documents/` (`Paths::stocareSite`, în afara docroot-ului). `adopta.sh` nu le mută: după `GATA`, înainte să
  deschizi o cerere în admin, le copiezi păstrând numele (`cp -a ~/domains/vertij-tinitus.ro/public_html.vechi/storage/account-documents/.
  ~/domains/vertij-tinitus.ro/site/storage/account-documents/`, dosarul 0770, fișierele 0640) și verifici că
  `ls … | wc -l` dă același număr ca `SELECT COUNT(*) FROM account_verification_documents`. Pozele de profil
  (`public/uploads/gallery/profile/`) le mută `adopta.sh` odată cu `uploads/`.
- **Webhook-ul Stripe**: aceeași adresă (`https://vertij-tinitus.ro/webhook/stripe`) și același secret de semnare. Endpoint-ului din
  Stripe i se adaugă, dacă lipsesc, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted` și
  `charge.refunded` (lunile neîncasate, abonamentele încheiate, rambursările donațiilor și ale cotizațiilor); apoi Setări plăți →
  „Verifică webhook-ul” trebuie să nu mai arate niciun eveniment lipsă.
- **Versiunea API Stripe**: codul vertij nu trimite `Stripe-Version` (setarea `stripe_api_version` goală = la fel), deci contează versiunea
  implicită a contului Stripe. Donația lunară cere factura cu `payments` și `confirmation_secret` (versiunile basil, din 2025-03-31);
  în Stripe → Developers vezi versiunea contului. Prima factură a unei donații lunare e marcată plătită de `invoice.paid` în ambele forme
  ale facturii (`tools/dev/test-donatii-membri.php`, D). În versiunile basil, factura din webhook nu mai are plata (PaymentIntent) pentru
  lunile 2+: codul comun o cere la Stripe (`GET /v1/invoices/<id>?expand[]=payments`, o cerere pe lună plătită fără plată), ca o
  rambursare a acelei luni (`charge.refunded`, care spune doar plata) să se găsească. Dacă cererea nu reușește, luna rămâne fără plată
  și rambursarea ei nu se notează (se vede doar în Stripe); rândul din `storage/logs/account.log` spune care.
- **Fusul orar**: în `site/.env`, `APP_TIMEZONE=Europe/Bucharest` (implicitul). Codul comun îl pune pe fiecare conexiune la bază, deci
  orele noi și coloanele TIMESTAMP se arată în ora României oricare ar fi fusul serverului MySQL. Notezi o dată
  `SELECT @@global.time_zone, @@system_time_zone` pe baza vertij: dacă nu e ora României, orele vechi de tip DATETIME scrise de MySQL
  (`paid_at`, `confirmed_at`, `reviewed_at`, `cancelled_at`) rămân cu 2–3 ore în urmă (doar ora, nu data; nu se convertesc, fiindcă
  aceleași coloane au și ore scrise de PHP în ora României).
- **Membrii vechi**: pasul `site/vertij/0001` (cu `migreaza.php --aplica --site=vertij`, o singură dată) dovedește adresa celor care și-au
  confirmat-o **cu linkul cererii** pe codul vertij; o „cerere nouă” din cont (născută confirmată, fără link) și o cerere decisă cât
  aștepta confirmarea nu dovedesc nimic, iar conturile lor rămân neconfirmate (se confirmă oricând din cont). **Înainte de aplicare**,
  numărătoarea: `migreaza.php --plan --site=vertij` scrie avertismentul pasului („N conturi primesc adresa dovedită …; M cereri cu
  confirmed_at lăsate deoparte”; numărătoarea exactă: momentul creării citit exact, `confirmed_at` în ora României, oricare ar fi fusul
  serverului MySQL). Pe baza vertij, ca plafon: `SELECT COUNT(DISTINCT user_id) AS conturi, COUNT(*) AS cereri FROM account_verifications
  WHERE confirmed_at IS NOT NULL`: N din plan e cel mult `conturi`, iar M cel mult `cereri`; un N mult sub așteptări (de exemplu 0 la un site cu membri activi) înseamnă că te oprești și ne trimiți
  avertismentul și cele două numere. Cei cu cererea încă neconfirmată de dinainte de adoptare intră în cont ca azi (nu se blochează
  nimeni; o înscriere nouă pe adresa lor retrimite linkul fără să le închidă sesiunile), cei înscriși după adoptare intră abia după
  confirmare.
- **Cronuri noi**: niciunul (donațiile și cotizațiile vin doar pe webhook și pe întoarcerea de la Stripe).

## Adoptarea unui site WordPress

Un site WordPress (de exemplu cu tema Divi) trece pe codul comun cu `deploy/adopta-wp.sh`. Spre deosebire de
`adopta.sh`, site-ul nou primește o **bază nouă** (făcută de proprietar în hPanel), instalată de `scripts/install.php`, în
care `scripts/importa-wordpress.php` aduce paginile, SEO-ul, setările și mediile. Baza WordPress e doar **citită** (cu
datele din `wp-config.php`, fără să-l ruleze), iar WordPress rămâne întreg în `public_html.vechi/`: revenirea doar îl pune
la loc.

```bash
# o dată: datele bazei noi (din hPanel → Databases), întrebate pe ecran, scrise în site/.env (0600)
deploy/adopta-wp.sh --site=<slug> --domeniu=<domeniu> --baza-noua
# planul (nu schimbă nimic) și adoptarea
deploy/adopta-wp.sh --site=<slug> --domeniu=<domeniu> --versiune=<v> --cheie-licenta=<cheia> --admin-email=<adresa>
deploy/adopta-wp.sh … aceleași … --aplica
```

`--admin-email` e **obligatoriu**: adresa primului administrator al site-ului nou nu se ia niciodată din `admin_email` al
WordPress (poate fi a agenției care a făcut site-ul). Fazele lui `--aplica` (ținute minte în `site/deploy/adopta-wp.stare`;
aceeași comandă continuă de la prima neterminată): `verificari`, `pregatire`, `instalare`, `import`, `copie`, `comutare`,
`verificare`, `deschidere`.

- **Mediile rămân la adresele lor.** Fișierele din `wp-content/uploads/` se copiază de pe disc în
  `public_html.nou/wp-content/uploads/`, la aceeași cale și cu aceiași octeți (fără `/uploads/wordpress/` și fără 301).
  Se copiază doar fișiere obișnuite, cu extensii de media și documente; se refuză legăturile simbolice (și dosarele-legătură),
  ce iese din dosar prin `realpath`, fișierele peste 200 MB, numele cu o extensie executabilă înăuntru (`poza.php.png`) și
  orice fișier cu cod PHP înăuntru: `<?php` oriunde, `<?=` și `<?` urmat de spațiu în fișierele text, iar pentru orice alt
  `<?` (fiecare, presupunând `short_open_tag=1`, inclusiv un short tag lipit `<?echo`) cod PHP pe care PHP l-ar compila și rula
  (o poză poliglotă; verificat cu `token_get_all(TOKEN_PARSE)`, tagul rescris în `<?php`: un cod închis cu `?>` sau
  `__halt_compiler()` înaintea datelor binare, chiar cu un octet non-ASCII în comentariu sau șir, se refuză; fereastra de
  compilare se mărește dacă padding-ul — spații, un comentariu sau un șir lung — a tăiat codul la margine, până la EOF sau
  8 MiB, și atunci se refuză; `<?xml` dintr-un SVG nu compilează ca PHP, deci nu se refuză pentru el; cei doi octeți apar și
  la întâmplare în pozele comprimate, dar acolo PHP dă o eroare de compilare înainte de margine, deci nu rulează nimic și
  fișierul trece). Numărul de `<?` verificate astfel e mărginit pe fișier (cel puțin 16 384, sau mărimea împărțită la 4 KiB
  dacă e mai mare): un fișier care trece de buget (o „bombă” de `<?`, care altfel ar ține minute) se refuză fail-closed;
  pozele adevărate au `<?` rar, la întâmplare, mult sub buget. Chiar înainte de copiere sursa se verifică din nou
  (legăturile pe drum, `realpath`,
  mărimea, codul PHP) și se copiază din fișierul deschis, care trebuie să fie chiar cel verificat: un dosar înlocuit între
  plan și copiere cu o legătură spre afara lui nu se urmează. Manifestul, cu sha256 de pe disc al fiecărui fișier:
  `site/storage/import-wordpress/media.json`. Paginile folosesc variantele de poze ale WordPress: adoptarea nu face
  variantele WebP ale nucleului.
- **Arborii copiați** (`wp-content/`, `wp-includes/`, `capturat/`) primesc la comutare `.htaccess`-urile din git
  (`deploy/stub/wp-content.htaccess`, `wp-includes.htaccess`, `capturat.htaccess`): nimic nu se execută (`Require all
  denied` pe PHP și scripturi, `Options -ExecCGI -Indexes`, niciun `.js` servit), cache de 7 zile cu `Expires`, ca pe
  site-ul live, CORS doar pe fonturi, `X-Content-Type-Options: nosniff` pe tot și `Content-Security-Policy: sandbox` pe SVG.
  Fișierele lor le servește serverul web înaintea PHP-ului; unul care lipsește răspunde 404, ca înainte.
- **Spațiul.** Mediile și arborii statici stau de două ori pe disc (în WordPress, neatins pentru revenire, și în docroot-ul
  nou), plus copia codului WordPress și a bazei. Verificările spun cât trebuie, iar `df` nu arată cota contului Hostinger:
  rândul ATENȚIE cere verificarea în hPanel → Disk usage.

**Importul** (`scripts/importa-wordpress.php --wp=<dosarul WordPress> --plan|--aplica`, rulat de adoptare prin
`bin/platforma --public=public_html.nou`):

- regulile site-ului stau în pachet, `import-wordpress.json` (`pagini`, `articole`, `redirecturi`, `setari`, `continut`,
  `dialect`, `scripturi_aruncate`, `captura`), verificate de `tools/dev/verifica-pachet.php` (fără literale de email);
- slug-ul fiecărei pagini e `post_name`-ul WordPress, păstrat exact: decodat în `pages.slug` (și Unicode), în forma
  percent-encoded a WordPress în adrese; căile păstrează „/” final cum îl pune structura permalinkurilor. Două slug-uri care
  diferă doar printr-un accent se bat cap în cap pe cheia UNIQUE a bazei (colația `utf8mb4_unicode_ci`), deși sunt distincte ca
  text: planul le prinde așa cum le compară baza (un tabel temporar cu aceeași cheie și colație) și spune NU cu ambele ID-uri
  WordPress și adrese, înainte de orice `1062 Duplicate entry`;
- resturile tehnice primesc 410 în harta adreselor vechi (`/wp-admin/…`, `/wp-json/…`, `/wp-login.php`, `/xmlrpc.php`,
  `/readme.html`), dar niciodată arborii copiați și niciodată un endpoint compatibil al site-ului (lista albă din
  `ImportWordPress\Plan::COMPATIBILE`: `POST /wp-admin/admin-ajax.php` cu acțiunea galeriei, managerul consimțământului sub
  `/wp-json/`): unde unul ar fi acoperit, planul spune ATENȚIE și regula nu se scrie;
- harta WordPress (id-ul fiecărei pagini, articol, proiect, atașament, categorii și autor, cu adresa lui) intră în raport;
- **reimportul nu scrie peste munca oamenilor**: fiecare pagină, rând SEO și setare scrise de import au amprenta lor
  (`import_amprente`, migrarea `nucleu/0068`); un rând editat după import e NU în plan și e refuzat la `--aplica`, afară de
  `--suprascrie`. Amprenta SEO acoperă și `og_type` și `intro_html` (editabile de om în panoul SEO, dar nescrise de import):
  un om care le pune e un conflict, iar un reimport cu SEO gol nu mai poate șterge tot rândul (cu intro_html-ul omului) în
  tăcere. Ștergerile sunt tot editări: un rând SEO golit din panoul SEO (rândul dispare, amprenta rămâne), o pagină
  a importului mutată în coș (cu `--suprascrie` se restaurează pe același id) sau ștearsă definitiv. La un reimport, o setare
  pe care importul de dinainte n-a scris-o, dar pe care a pus-o omul (alta decât implicita instalării), e tot NU. O pagină
  care nu e a importului, pe o adresă din plan, e NU în plan, și când stă în coș (unicitatea adresei cuprinde coșul: o ștergi
  definitiv sau îi schimbi adresa). Conflictele (paginile care nu sunt din import, rândurile editate de om) se verifică din nou
  în tranzacția scrierii, cu rândurile blocate (`SELECT … FOR UPDATE`): o salvare făcută de om în timpul copierii mediilor
  (secunde–minute) se vede și oprește reimportul cu mesajul conflictului, fără să scrie nimic (mediile deja copiate rămân pe
  disc, se spune în mesaj);
- **drumul capturat** (`"continut": "capturat"`, `"dialect": "divi4"`): `--url` și captura site-ului live (pasul 4,
  `capturat/` din dosarul site-ului, sau `--captura=<dosar>`) sunt obligatorii; paginile le transformă dialectul `divi4` din
  captură (mai jos), iar politica listei albe le verifică (NU pe pagină); head-ul și antetul/subsolul sunt ale capturii (fără
  setările `design_*`), SEO-ul primește valorile AIOSEO exacte, iar dintre trackere rămân doar ID-urile, fără fanioanele de
  pornire (bannerul nucleului oprit: `cookie_banner_enabled=0`). Paginile private sau cu parolă nu se importă (n-au captură:
  ATENȚIE). Fiecare document se taie apoi în cele cinci bucăți (mai jos): `pages.html_content` primește doar conținutul, iar
  restul (și 404-ul, cardurile embed ale paginilor importate, arhivele, răspunsurile înghețate, fragmentele, formularele,
  galeriile) tabelele capturii (migrarea `nucleu/0070`), în aceeași tranzacție, cu `hash_import` pe fiecare rând: un rând editat
  de om după importul de dinainte e NU fără `--suprascrie`, ca paginile. **P1 la import:** fiecare document se randează din
  rândurile abia scrise și se compară cu documentul servit (după normalizarea de comparație); o diferență anulează tot.

**Captura** (`scripts/captureaza-wordpress.php --url=https://<site> --wp=<dosar> | --lista=<adrese.json>
--fara-integritate=<motiv> --plan|--aplica`; `App\Support\ImportWordPress\Captura`): cere site-ul live prin HTTP, fără browser,
cum îl vede un vizitator: doar GET, fără cookie-uri și fără autentificare, cel mult o cerere pe secundă (`--ritm=`, cel puțin 1),
cu un buget fix de cereri (`--buget=`, implicit 300, cel mult 2000: fiecare încercare se numără, iar planul, cu rezerve pentru ce
se află abia la captură, spune NU dacă nu încape), reluare la 429 și la 503 cu `Retry-After` și oprire politicoasă după prea
multe la rând. Nicio cerere spre trackere (Clarity, Google Analytics, Tag Manager, DoubleClick…) și spre altă gazdă decât site-ul
(și, cu `--fonturi`, gazdele fonturilor Google; la integritate, api.wordpress.org și downloads.wordpress.org); niciun formular
trimis. Lista adreselor vine din baza WordPress, doar citită (pe server), sau dintr-un fișier (când captura se face din mediul
nostru și HTML-ul se urcă): paginile publicate rămase, un 404 aleator, cardul embed și oEmbed-ul fiecărei pagini, robots,
sitemap-urile (cu sub-sitemap-urile și XSL-ul găsite în index), feed-urile. Documentele HTML se cer pe șase UA × 2
(`--raspunsuri-ua=2`: răspunsurile înghețate pe două UA × 2, când bugetul e mic) și se compară după **normalizarea declarată**
(`Captura\Normalizare`, DECIZIE §6.7): nonce-urile (și în textul scripturilor) devin jetoane, clasele de sistem puse de server pe
`<body>` după UA se scot, adresele proprii absolute devin relative în atribute, CSS și JSON (head-ul SEO, sitemap-urile și robots
rămân absolute; la comparație doar cele cu schema și gazda exacte ale site-ului, deci o diferență http/https sau www/fără www
între două răspunsuri rămâne NU); un `{{ax:` aflat deja într-un răspuns e NU. Doar la comparație, fără să schimbe octeții
păstrați (T păstrează secretul cardului embed), patru variații aleatoare găsite de comparația pe UA pe un site real
(`Normalizare::FELURI_COMPARATIE`, declarate și ele în `ce-se-schimba.md`, după revizie): secretul cardului embed, referințele
numerice cu care un plugin de cookie-uri ascunde un email (doar în `span.cmplz-obfuscate`, cu fanionul ei), ID-urile taburilor
din cardul embed (valoarea întreagă a atributului, și doar dacă fiecare `aria-controls` are `id`-ul pereche cu aceeași valoare)
și ora generării, doar în comentariul exact al generatorului și doar într-un sitemap (XML, sau cel RSS al AIOSEO, `/sitemap.rss`). Orice altă diferență între UA-uri sau între
două cereri e NU, cu diferența arătată. Ieșirea, numai în dosarul capturii (implicit `site/capturat/`, niciodată în repo, judecat
pe calea reală): `brut/<sha256 adresei>/` cu corpul decodat al fiecărui răspuns și antetele lui (cele de codare și de fir se scot
și se notează), `index.json`, `manifest.json` (paginile și fișierele cerute de ele și, recursiv, de foile proprii, cu sha256-ul
sursei și al copiei publicate, cele negăsite, gazdele externe pentru revizia omului, fișierele din `brut/` cu sha256-ul lor),
jurnalele `jurnal-cereri.jsonl` și `jurnal-integritate.jsonl` (doar adăugate, scrise pe disc după fiecare cerere, cu metoda și
începutul în ms) și `raport-captura.json` (ritmul, bugetul, gazdele permise, problemele, sha256-ul fiecărui fișier al lanțului;
scris și la o oprire), al cărui sha256 se tipărește la sfârșit. `--din-dosar=` citește o captură urcată, fără rețea, doar cu
`--sha-raport=<acel sha256>`: lanțul se verifică întreg (raport → index, manifest, jurnale, integritate → fiecare fișier din
`brut/`), iar orice nepotrivire e NU (cod 2); `--din-oglinda=` citește o oglindă locală, neverificată (doar în mediul nostru). Cu
`--public=` (și `--aplica`) fișierele statice se copiază de pe disc în docroot-ul nou, la aceeași cale, doar în arborii copiați
`wp-content/`, `wp-includes/` și `capturat/`, pe care git trebuie să-i ignore acolo (restul fișierelor de la rădăcina site-ului
sub `capturat/radacina/`; o coliziune cu un fișier sau o rută a CMS-ului e NU) (`Captura\CopiereStatica`: doar fonturi, foi, poze
și video, niciun `.js`/`.php`, fără legături simbolice sau tari, fără fișiere ascunse; o poză (PNG, JPEG, GIF, WebP, AVIF, ICO)
doar cu semnătura extensiei ei și un antet acceptat de `getimagesize()`, apoi fără `<?php` (orice litere); orice alt
fișier fără `<?php`, `<?=` sau `<?` urmat de spațiu, în afară de `<?xml` la începutul unui SVG; fiecare fișier deschis o singură
dată, SVG-urile prin
`SvgSigur` strict, adresele proprii din foi relative), iar cu `--fonturi` fonturile Google (doar https, foaia `text/css`, fiecare
font cu tipul extensiei lui) în `capturat/fonturi/`. Întâi, înaintea oricărei cereri spre site, vine integritatea WordPress-ului
de pe disc (`Captura\Integritate`): nucleul față de sumele publice ale versiunii și limbii instalației (fișierele diferite și
PHP-ul în plus din `wp-admin/` și `wp-includes/` sunt NU, ca orice PHP din `uploads/`), pluginurile de pe wordpress.org față de
sumele lor, semnele codului ascuns (și în `uploads/` și `mu-plugins/`, ATENȚIE); codul fără sume publice (pluginurile premium,
temele, mu-plugins) iese „neverificabil”, cu sha256-ul fiecărui fișier. Un NU oprește totul (cod 2); fără discul WordPress, doar
cu `--fara-integritate=<motiv>`, iar starea ei rămâne „în așteptare (server)”. Linkurile externe din elemente ascunse ies
ATENȚIE. Codurile de ieșire: 0 fără NU, 1 măcar un NU al capturii, 2 folosire greșită sau un refuz înaintea capturii. Poarta:
**G-captura** (`tools/dev/test-captura.php`), pe un site Divi inventat servit local.

**Dialectul `divi4`** (`App\Support\ImportWordPress\Continut\Divi4`, interfața `Continut\Dialect`) face din pagina capturată
documentul servit de site-ul nou, T(captura), doar prin editări pe intervale de octeți (`Html5\Editare`), fiecare de un fel din
lista închisă: scripturile furnizorilor și ale trackerelor se scot doar dacă sha256-ul lor (calculat după normalizarea adreselor
și a nonce-urilor, ca o recaptură să dea aceleași hash-uri) e în `import-wordpress.json` → `scripturi_aruncate`, cu motivul;
configurațiile inline (`var <nume> = <JSON>;`, din lista închisă) se citesc strict ca JSON și trec în blocul
`<script type="application/json" id="ax-date">`, pus unde stătea primul script din head (și împărțite pe pagină, antet și
subsol); `onload`-ul preload-urilor devine `data-ax-tarziu`, cel al cardurilor embed `media="all"` (doar formele exacte, pe
tagul lor; orice alt `on…` îl refuză politica); legăturile spre adresele WordPress care dispar (pingback, EditURI, wlwmanifest,
`api.w.org`, JSON-ul REST al paginii) și `dns-prefetch`-urile se scot, un `<link>` doar dacă toate `rel`-urile lui sunt din
listă; orice indiciu de resurse spre altă gazdă (`preconnect`, `prefetch`, `preload`, `modulepreload`) se scoate și el
(`indiciu-tert`, declarat în `ce-se-schimba.md`: site-ul nou nu pregătește conexiuni spre terți), iar politica refuză unul
rămas; foile Google devin `/capturat/fonturi/<cheie>.css`; plus normalizările capturii. Pe drumul capturat, politica primește
manifestul capturii: un `<link>` dintr-un `<noscript>` doar spre o foaie a site-ului din el. Din pachet,
`import-wordpress.json` → `captura.structura` (`{"<cale>" | "404": {"et-last-child": ["<selector>", …]}}`: clasele de structură
observate pe un document fără o regulă care să reiasă din DOM) devine, pe documentul lui, ultima cheie din `ax-date`,
`ax_structura`, pe care runtime-ul o aplică la încărcare; clasele sunt din lista închisă, selectorii doar taguri, `#id`,
`.clasă`, spații și „>”, iar cheile de sus ale lui `ax-date` sunt și ele închise (configurațiile știute, `file`,
`ax_structura`): altceva e NU. Un script necunoscut e NU (cu sha256-ul lui, de revizuit
și trecut pe listă); un handler sau un `data-*` necunoscut îl refuză politica. Rezultatul dă și lista editărilor (în afara lor,
octeții capturii) și pozițiile scripturilor runtime-ului. Poarta: **G-wp** (`tools/dev/test-import-wordpress.php`, secțiunea 5a).

**Tăierea** (`Continut\Divi4Taiere`, peste rezultatul dialectului; `Continut\Taiere`) face, tot pe intervale de octeți:
(1) conținutul servit, T: `data-ax-s` pe fiecare secțiune din conținut (numele din eticheta admin Divi stau separat),
`data-ax-slot` pe texte, titluri, butoane, poze, video-uri, elemente de meniu, de carusel și de galerie, câmpuri de formular,
`data-ax-g` pe textele egale din conținut (seturile desktop/telefon) și câmpul-capcană al fiecărui formular Divi; (2) jetoanele
`{{ax:…}}` peste T: scripturile runtime-ului (`runtime:cap` pe tagul lui `ax-date` sau pe `</head>`, `runtime:final` unde stătea
ultimul script scos sau pe `</body>`: exact o ancoră, altfel NU), datele (`ax-date`, refăcut la randare din partea paginii și
a fragmentelor, după o rețetă a ordinii), clasele și `aria-current` ale elementelor de meniu (elementul curent), numărul
formularului din subsol (`formular_index`), calea cererii (`action`, `_wp_http_referer`, pe 404 și JSON-LD-ul), anul (doar cu
Divi Dynamic Content în subsol) și head-ul SEO (toate locurile valorilor AIOSEO; dacă unul diferă, head-ul rămâne înghețat
întreg, cu motivul în plan); (3) cele cinci bucăți (atributele `<html>`, head-ul până la `</head>`, atributele `<body>`, înainte,
conținutul, după până la `</body>`), cu antetul (`header.et-l--header`), subsolul (`footer.et-l--footer`) și bannerul
(`#cmplz-cookiebanner-container`) ca fragmente deduplicate pe octeți (o diferență face o variantă nouă). **Dovada:** bucățile, cu
fragmentele și valorile de la import, refac exact T (altfel NU). Plus formularele (câmpurile din DOM, destinatarul și mesajele
din atributele modulului, din baza WordPress), galeriile (lista din DOM și categoriile) și corpusul căutării (titlul,
rezumatul și conținutul brut din WordPress). Un „{{ax:” deja în captură, și într-un răspuns înghețat, e NU la import. Randarea
(`App\Support\CadruCapturat`) pune la loc fragmentele, apoi toate jetoanele într-o singură trecere, cu valorile escapate pe
locul lor; un jeton necunoscut sau rămas e o eroare (500 și un rând în jurnal), niciodată o pagină pe jumătate. Poarta: **G-wp**
(secțiunea 5a.2, pe o captură inventată: `tools/dev/lib/captura-divi-proba.php`).

**HTML-ul capturat se editează doar pe tokeni** (`App\Support\Html5`): `Tokenizer` îl împarte în tokeni WHATWG cu
intervalele lor de octeți (textul, tagurile cu atributele lor, comentariile, doctype, textul brut al `script`/`style`/…,
RCDATA, CDATA în SVG și MathML), fără reserializare (niciodată DOMDocument). Unde textul brut începe și unde `<![CDATA[` e
CDATA o hotărăște, ca în browser, constructorul arborelui (`Constructie`: stiva elementelor deschise cu spațiul lor de
nume, formatarea și algoritmul de adopție, modurile de inserare, tabelele, punctele de integrare ale SVG și MathML), fără
DOM; tot el dă fiecărui tag spațiul de nume, perechea și dacă parserul l-a ignorat sau i-a mutat nodurile.

**Contractul** (în docblock-urile `Constructie` și `Tokenizer`): pentru orice intrare, ori lista `nesigure` e nevidă, ori
deciziile tokenizerului (ce octeți sunt text brut/RCDATA/script/PLAINTEXT/CDATA/comentariu și ce taguri fac elemente vii, în
ce spațiu de nume) se potrivesc cu Chromium de azi. Unde browserele se abat de la WHATWG sau unele de altele, nu imităm
fiecare capriciu, ci prindem construcția care îl declanșează și marcăm documentul nesigur, cu declanșatori largi pe care
conținutul real nu-i conține. `Tokenizer::analizeaza` marchează nesigur: conținutul unui `<select>`, `<search>`, un `<input>`/`<keygen>`/`<textarea>`
în `<select>` (parserele vechi închid select-ul, Chromium îl ține înăuntru), orice `<frameset>`/`<frame>` (frameset-ok nu
se resetează la fel între browsere pe U+FFFD/NUL), orice `<template>` (conținutul lui poate fi viu: shadow DOM declarativ
`shadowrootmode`/`shadowroot` sau clonat de JS în DOM), orice atribut `srcdoc` (`<iframe srcdoc>` construiește un
subdocument viu neparcurs), CDATA
într-un punct de integrare, un BOM UTF-16; o închidere în conținut străin cu un nume SVG camelCase (`clippath`,
`lineargradient`…) care nu închide chiar nodul curent (abaterea Blink, și peste un element MathML, nu doar HTML); orice `&`
în valoarea unui atribut care dirijează construcția (`encoding` la `annotation-xml`, `type` la `input`: referințele din ele
se decodează altfel între browsere, iar codarea se face într-o singură trecere, fără dubla decodare); o stivă de peste 512
elemente sau un buget de lucru depășit (reconstruirea formatării și plimbările stivei, proporțional cu lungimea
documentului — o pagină obișnuită folosește sub 0,2% din el). Octeții se citesc ca UTF-8: CMS-ul servește paginile cu
`Content-Type: text/html; charset=utf-8`, care face browserul să ignore `<meta charset>`, deci o codare `<meta>` ne-UTF-8
din conținut nu schimbă decodarea. Politica HTML-ului capturat refuză un document nesigur. `Editare` schimbă doar
intervale: valoarea unui atribut, interiorul unui element, un nod (doar cu sfârșitul lui: un nod închis implicit, neînchis
sau mutat de parser se refuză) sau un atribut șters (fără să schimbe tokenizarea tagului), un atribut inserat; restul
octeților rămâne neatins. În plus, o editare care, prin construcția arborelui, ar schimba tokenizarea din afara nodului ei
(ar scoate parserul din SVG/MathML, ar face dintr-un text brut inert un `<img onerror>` viu) sau ar face documentul nesigur
e refuzată, nu doar cea care schimbă octeți în afara intervalului; iar efectul COMBINAT al tuturor editărilor se verifică
mereu pe rezultatul final la `aplica()` (o editare refuzată oprește tot, iar una acceptată abia atunci intră în uniunea
protejată). Tokenizarea recompusă dă exact octeții paginii (poarta G-html5, `tools/dev/test-html5.php`).

**Politica listei albe** (`App\Support\HtmlCapturat`, cu `HtmlCapturat\ContextCapturat`, `SchemaDivi4` și `Problema`) rulează
peste acești tokeni la import și la fiecare salvare a unei pagini capturate (`verifica($html, $ctx, 'import'|'salvare')`):
HTML-ul capturat e de neîncredere, iar pe site-ul nou NU rulează niciun script capturat — comportamentul vine doar din
runtime-ul nostru, pe același DOM. Ce nu e explicit permis e REFUZAT, cu motivul (o `Problema`); nimic nu se scoate în
tăcere, lista albă se lărgește doar după o revizie de om. Un document cu măcar o problemă nu se importă și nu se salvează
(în planul importului: „NU” pe pagină). Politica permite, pe scurt:
- **scripturi — numai date inerte scrise de noi:** `application/ld+json` (JSON valid), `speculationrules` (schemă închisă:
  doar `prefetch`/`prerender` cu adrese relative) și `<script type="application/json" id="ax-date">`. Orice alt `<script>`
  (inline, `src`, `module`, cu tip, cu categorie de consimțământ) e refuzat. Dialectul (pasul 5a) le-a scos deja:
  configurațiile în `ax-date`, restul pe lista aruncată;
- **atribute de eveniment:** orice `on…`, oriunde (inclusiv în SVG), e refuzat. Cele din lista închisă (cele două forme
  `onload` de preload CSS → `data-ax-tarziu`; `onload="media='all'"` din răspunsurile embed → atributul `media`) le
  transformă determinist dialectul, înainte de politică (`SchemaDivi4::handlerRecunoscut`);
- **documente purtate de adrese:** `<iframe>` doar din lista albă (`ContextCapturat`, implicit harta Google Maps), inclusiv
  forma `data-src-cmplz`; `data:` doar în `<img src>` și `srcset`, doar imagini rastru, base64 corect, ≤ 2 KB decodați, cu
  octeții de început potriviți cu tipul; `<link>` doar cu `rel` dintr-o listă închisă; `href`/`src`/`srcset`/`poster`/
  `action`/`formaction` doar http(s), relative, `mailto`, `tel`, `#`. Refuzate: `object`/`embed`/`applet`/`portal`,
  `<base>`, `meta http-equiv=refresh`, formularele spre alt server, schemele de script în ORICE atribut (inclusiv `style`
  și JSON-ul `data-*`, citite după decodarea entităților, fără spații și fără caractere de control, fără deosebire de litere
  mari), `data:` oriunde în afara imaginilor rastru. `<frame>`/`<frameset>`, `<template>`, `srcdoc` le prinde deja
  tokenizerul (document nesigur, refuzat);
- **atribute și stiluri:** `class`/`id` fără metacaractere de marcaj; `data-*` doar din schema dialectului (`SchemaDivi4`),
  cu JSON-ul validat recursiv (cheile de poluare a prototipului refuzate, variantele HTML din multi-view trec prin aceeași
  politică); `style` declarație cu declarație (fără `expression`/`behavior`/`-moz-binding`, `url()` doar spre căi
  relative/http(s) sau imagini `data:`); SVG prin lista albă; `<noscript>` doar cu conținut inert verificat recursiv;
- **necunoscutele:** un tag sau un atribut care nu e în nicio listă e refuzat.

Poarta **G-html-capturat** (`tools/dev/test-html-capturat.php`) o încearcă pe încărcături plantate inventate (fiecare
prinsă), pe un fixture „ca Divi” care trece neschimbat, iar pe datele reale (întregul site capturat) și pe documente
adversariale în Chromium — din afara repo-ului.

**Cadrul capturat** (pasul 6; `App\Support\CadruCapturat`, `App\Support\CspSite`; `tema.json` → `cadru` = `capturat`, `runtime`,
`csp`, `previzualizare`, docs/PACHETE.md): prima pagină, paginile (și `/contact`), cardurile embed, arhivele și 404-ul cu un document
în `pagini_capturate` ies din bucățile lor, cu fragmentele alese ale documentului (variantele de antet și subsol), conținutul din
`pages.html_content` și jetoanele rezolvate pe cerere: calea, anul, nonce-ul formularelor (un jeton HMAC al zilei, cu cheia
aplicației; îl verifică serverul formularelor, pasul 9b), SEO-ul din `seo_pages`, datele `ax-date`, elementul curent din meniu și
scripturile runtime-ului din manifestul versiunii lui. Nimic din `views/layout.php`. Un jeton necunoscut sau rămas, un runtime
lipsă: 500 și un rând în jurnal, niciodată o pagină pe jumătate. 404-ul capturat are statusul 404 și antetele lui. Politica CSP e
una pe tot site-ul, trimisă din `public/index.php` înaintea cache-ului de pagini (aceeași la MISS, la HIT și la 304, fără nimic din
cerere: nici gazda): `default-src 'self'`, `script-src 'self' 'inline-speculation-rules'` (niciun script inline, fără hash-uri),
`style-src 'self' 'unsafe-inline'`, `img-src 'self' data:`, `frame-src` doar harta Google, `object-src 'none'`, `base-uri` și
`form-action 'self'`, `worker-src 'none'`, `frame-ancestors 'self'` (nu pe cardurile embed, pe care le încadrează alte site-uri),
`upgrade-insecure-requests`, `report-uri /_andaxi/raport`, plus sursele https exacte din `csp`; `csp.mod` = `raport` o trimite ca
Report-Only (copia de verificare, înainte de comutare). `X-Content-Type-Options: nosniff` pe orice răspuns. Fără `cadru`, nimic din
toate acestea: site-urile de azi ies octet cu octet ca înainte (G-cmp). Poarta: **G-capturat** (`tools/dev/test-capturat.php`).

**P1 după import** (pasul 7): `php scripts/verifica-randare.php --capturat` randează din linia de comandă fiecare document
capturat, pe drumul cererii web (CadruCapturat, cu scripturile runtime-ului din `tema.json` → `runtime`), cu valorile de la
import, și compară sha256-ul cu amprenta aprobată (`pagini_capturate.sha_randare`, scrisă de P1 la import); verifică și că nicio
ieșire n-are un script nepermis (doar `ax-date`, JSON-LD, `speculationrules` și scripturile runtime-ului de pe sloturi) și că
politica CSP n-are `'unsafe-inline'`/`'unsafe-eval'` în `script-src`. Cod 1 la orice diferență; după o editare văzută de om sau o
versiune nouă a runtime-ului, `--aproba=<page_id>` sau `--aproba-toate` reînnoiește amprenta. `php scripts/diagnostic.php
--capturat`: fiecare fișier din manifestul capturii există în docroot cu sha256-ul lui, niciun `.php`/`.phtml`/`.phar` în
`wp-content/`, `wp-includes/`, `capturat/`, iar `.htaccess`-urile arborilor copiați au `nosniff`. Pe cadrul capturat, `/health`
are și rândul `capturat: <primele 12 caractere ale sha256-ului manifestului>` (sau `lipsa`).

**Răspunsurile înghețate** (pasul 9a; `App\Support\RaspunsuriInghetate`): pe cadrul capturat, o cerere GET sau HEAD cu metoda, calea
și query-ul exacte ale unui răspuns din captură (robots.txt, sitemap-urile și XSL-ul lor, feed-urile, oEmbed, redirecționările
capturate) îl primește din `raspunsuri_inghetate`, înaintea stratului de adrese WordPress, a ghicirii, a rutelor nucleului și a
lui 404: statusul și corpul octet cu octet, cu antetele din lista albă (tipul conținutului, cache-ul, `expires`, `x-robots-tag`,
`location` doar relativ). Codarea (`content-encoding`), lungimea și antetele hop-by-hop nu se reiau niciodată: le face serverul
nostru pentru corpul trimis. Pe drumul capturat, `GET /wp-json/oembed/1.0/embed` e între endpointurile compatibile (nu primește
410): îl servesc răspunsurile înghețate.

**Fișierele de la rădăcină** (pasul 9a; `App\Support\RadacinaCapturata`): cele copiate de captură sub `capturat/radacina/<cale>`
(`/favicon.ico`, poze de la rădăcină, `/wp-admin/images/…`) se servesc pe cadrul capturat la adresa lor, ca răspunsuri înghețate:
doar GET și HEAD, doar tipurile copiate, cu tipul fix al extensiei, `nosniff`, cache 7 zile, iar un SVG cu
`Content-Security-Policy: sandbox`. O adresă care se ciocnește cu un fișier, un dosar sau o rută a CMS-ului nu se servește
(`diagnostic.php --capturat` o arată NU), nici un fișier ascuns, o legătură simbolică sau o cale cu „..”.

**Adresele greșite** (VC-URL-024): pe cadrul capturat nu se ghicește nimic (`ghicire` din wp-compat.json e ignorat): o adresă
greșită, oricare ar fi prefixul (`/au…/`, `/h…/`), primește 404-ul capturat, fără redirecționare. Fluxurile: doar cele înghețate
din captură (`/feed/`, `/comments/feed/`, fluxul unei pagini legat din head-ul ei) răspund; orice alt flux (de exemplu
`/<pagină>/feed/` nelegat) e 404. Paginile scoase la adoptare (articolul pus de WordPress la instalare, o pagină goală a constructorului) au 301 explicite spre `/`, din
`redirecturi` al pachetului.

**Rapoartele CSP** (pasul 9a; `App\Support\RaportCsp`): `POST /_andaxi/raport`, adresa din `report-uri`, interceptat în
`public/index.php` înaintea sesiunii (ca statisticile: fără cookie, fără fișier de sesiune), doar pe cadrul capturat. Primește doar
`application/csp-report` și `application/reports+json` (cel mult 10 rapoarte `csp-violation` pe cerere), corpul cel mult 8 KB, cu
bugetul pe rețeaua vizitatorului (120 pe zi pe IPv6 /64 sau IPv4, într-un singur fișier al zilei, numele găleții un HMAC cu o
sare a zilei din APP_KEY sau dintr-un secret aleator scris o dată; fișierul zilei trecute se șterge;
nimic în `register_submit_logs`), un plafon al site-ului (2000 de rapoarte pe zi, apoi 204 fără nimic scris), și răspunde 204. Nu
ține nimic despre vizitator (nici IP-ul, nici agentul):
doar cinci câmpuri, fiecare tăiat la 200 de caractere și fără query (calea documentului, directiva, adresa blocată, fișierul sursă,
linia), numărate pe zi în `rapoarte_csp` (nucleu/0071; cel mult 200 de rânduri pe zi, restul pe rândul `altele`, păstrate 30 de
zile). Le vezi pe Acasă, în rândul de sănătate (text escapat), și în `php scripts/diagnostic.php --capturat` (cele mai dese 20 din
ultimele 7 zile). Pe copia de verificare, cu `csp.mod` = `raport`, aici se văd încălcările înainte de comutare.

**Runtime-ul compat** (`public/assets/compat/`, scris clean-room, din specificațiile de comportament, fără niciun cod de
furnizor) reface pe același DOM ce făceau scripturile scoase. Site-ul își fixează versiunea (`divi4@1`), iar manifestul ei,
`public/assets/compat/divi4/v1/sloturi.json`, spune ce scripturi pune cadrul capturat pe cele două sloturi ale dialectului:
fiecare cale din `cap` și din `final` devine `<script src="/assets/compat/<cale>?v=<12 hex din sha256>"></script>`, sincron (fără
`async`/`defer`), în ordine, la poziția slotului — `cap` în head, înaintea lui `ax-date`; `final` unde stătea ultimul script scos din
corp. Fără slot `cap` (niciun script scos din head), scripturile lui stau înaintea lui `ax-date` sau a lui `</head>`; fără `final`,
înaintea lui `</body>`. Niciun script inline: `script-src 'self'`, fără hash-uri, plus `'inline-speculation-rules'`, fiindcă blocul
`speculationrules` rămâne în pagini (date inerte), iar Chromium îi aplică `script-src` (fără cuvânt: o încălcare pe fiecare pagină și
nicio preîncărcare). Piesele de bază (pasul 8a, în `comun/v1/`): `incarcare.js` (clasa `js` pe `<html>` din head; `window.axCompat`:
modulele pornite la `imediat`, într-o sarcină după DOMContentLoaded (`dom`) sau la `load`, în ordinea manifestului, doar cu un
`ax-date` valid — exact un element cu acest id,
un `<script type="application/json">` cu un obiect JSON; altfel niciun modul și un singur rând în consolă; `date()` cu copii ale
configurației; `adresaSigura()`, prin care trece orice adresă din configurație: https sau aceeași origine; accesul la cookie-uri și la
Web Storage fără excepții, ca în previzualizarea izolată din admin), `tarziu.js` (fiecare `link[data-ax-tarziu]` trece din `preload` pe
`stylesheet` la `load`-ul foii, pe loc, fără `ax-date`; foile „late” din `file` la locul lor, înainte de DOMContentLoaded, la primul
cadru) și `clase-ua.js` (clasele de browser și de dispozitiv pe `<body>`, din slotul final, înainte de DOMContentLoaded; în Safari,
`display: block` pe `<body>` după `load`). Felia 1 mai are, în `divi4/v1/`, toate la `dom`, ca pe live: `structura.js`
(`et_pb_column_single`, `et_pb_row_<mărimi>` pe rândurile interioare din conținut și pe rândul „specialty” din antet, `z-index: 3` pe
acel rând și pe rândul subsolului, clasele dintr-o listă pe pagină, `ax-date` → `ax_structura` = `{"et-last-child": [selectori]}`, pusă de import
din pachetul site-ului), `fit-vids.js` (`<style id="fit-vids-style">`, cu textul observat, la sfârșitul lui head) și `dg-lightbox.js` (containerul ascuns
`.dg-carousel-lightbox` la sfârșitul lui body). Felia 2: `meniu-mobil.js` (pe fiecare `.mobile_nav` al unui modul meniu,
`ul#mobile_menuN.et_mobile_menu` cu o copie a listei desktop, prin `cloneNode`, fără id-uri și fără `data-ax-*`; tapul pe bară o
deschide și o închide în 500 ms pe curba „swing”, cu `closed`/`opened`, iar un tap în timpul animației o întoarce, OBS-06) și
`comun/v1/consimtamant.js` (bannerul de consimțământ capturat, cu `ax-date` → `complianz`: clasele și `data-cmplz` pe `<body>`,
containerul mutat primul în body, documentele din `page_links`, butoanele Acceptă/Respinge/X/Preferințe/Salvează și tabul, cookie-urile
în ordinea observată, OBS-24, valori dintr-o listă închisă; hărțile blocate, `iframe[data-src-cmplz]`, primesc placeholderul cu imaginea
și înălțimea după raport, iar `src` se pune doar după acordul categoriei și doar pentru o adresă https trecută prin `adresaSigura()`;
nicio cerere spre altă gazdă înainte de acord; scripturile de urmărire nu se încarcă, CSP-ul rămâne `script-src 'self'`). Poarta:
**G-compat** (`tools/dev/compat.sh`, Chromium pe pagini inventate).

**Adresele WordPress** (`wp-compat.json` în pachetul site-ului; [SEO.md](SEO.md), §1b): după comutare, site-ul nou răspunde
la adresele vechi ca WordPress-ul: slash-ul final (`/x` → `/x/`), majusculele cu 200, `?page_id=`, `?p=`, `?cat=`,
`?author=`, `/index.php` și atașamentele (301 la fișier), ghicirea adresei greșite, sitemap-urile vechi (302), 404 cu
antetele WordPress, 410 pe resturile tehnice (în afara endpointurilor compatibile) și slug-urile Unicode în admin. ID-urile
WordPress le ia din tabelul `wp_harta` (migrarea `nucleu/0069`), pe care îl scrie importul (`WpCompat\Harta::scrie`, din
harta WordPress a planului; legarea în import vine cu pasul ei). Fără `wp-compat.json` nimic din toate acestea nu pornește.

**Revenirea**: `deploy/revino.sh` vede `site/deploy/adopta-wp.stare` și ia drumul WordPress (`deploy/lib/revino-wp.php`):
înainte de comutare șterge doar `public_html.nou`; după, pune `public_html.vechi` la loc (WordPress, verificat cu amprenta
de la comutare), iar baza nouă rămâne neatinsă. `deploy/actualizeaza.sh` refuză un site cu adoptarea WordPress neterminată
sau cu o revenire începută.

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
- Adoptarea WordPress: `deploy/adopta-wp.sh`, `deploy/lib/{adopta-wp,revino-wp,wordpress}.php`, importul
  `App\Support\ImportWordPress` și `scripts/importa-wordpress.php`; testul `tools/dev/php.sh tools/dev/test-import-wordpress.php`
  (un site WordPress inventat, [DEZVOLTARE.md](DEZVOLTARE.md)).
