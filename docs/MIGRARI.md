# Migrările bazei de date

Schema bazei (tabelele, coloanele, indecșii) se schimbă dintr-un singur loc: **`scripts/migreaza.php`**,
din linia de comandă, la instalare și la fiecare release. Paginile nu trimit DDL. Azi codul (moștenit
din Bioscem) încă își creează singur coloanele din mers, cu cele 36 de funcții `ensure*Schema`; ele
rămân până când fiecare site trece pe migrări (garda de la WP-05, apoi ștergerea lor după adoptări).

Ce există acum: **runner-ul**, cu detectarea, reluarea și codurile de ieșire, plus testele lui
(WP-02 din planul Etapei 1); din WP-10, **sonda schemei** site-urilor live (mai jos); din WP-03,
**migrările de bază** `0001`–`0024` (mai jos), cu `asteptat.json`, `migrari.lock`, `ultima.php` și
unealta care le compară cu schema făcută de cod (`tools/dev/migrari/genereaza-fixture.php`); din WP-04,
**testele T1–T11** pe schemele celor cinci coduri (și pe cele live, când vine sonda) și **verificarea
fișierelor de migrare** (`tools/dev/verifica-migrari.php`, în testul de fum), mai jos. Încă nu
rulează pe niciun site: instalarea și paginile merg ca înainte, cu funcțiile `ensure*`. Ce vine:
instalarea prin migrări, garda `Garda::stare` și `/health` (WP-05), pasul de înghețare a setărilor
unui site (WP-06).

## Pe scurt

```sh
php scripts/migreaza.php --plan        # ce s-ar face pe această bază (nu schimbă nimic)
php scripts/migreaza.php --aplica      # aplică ce lipsește, apoi verifică baza
php scripts/migreaza.php --verifica    # baza față de database/migrari/asteptat.json (doar nume de obiecte)
php scripts/migreaza.php --stare       # ultima_ord, ce lipsește, ce e întrerupt, dacă rulează alt runner
php scripts/migreaza.php --amprenta    # amprenta schemei acestei baze, JSON (doar structură)
tools/dev/php.sh scripts/migreaza.php --plan   # local, pe baza de test
```

Opțiuni:

| Opțiune | Ce face |
|---|---|
| `--json` | ieșirea ca un singur obiect JSON (pentru `actualizeaza.sh` și CRM): `cod`, `mesaj`, `migrari`, `avertismente`, … |
| `--permite-blocante` | aplică și migrările care pot opri scrierile într-un tabel (o copiere a tabelului). Doar cu site-ul în `MENTENANTA`, după o repetiție pe verificare |
| `--permite-contract` | aplică și migrările de tip „contract” (ștergeri), la un release după ce niciun cod nu mai folosește obiectul |
| `--buget=300` | secunde; după ele nu mai începe nicio operație (codul 13), iar rularea următoare continuă. Hostinger poate opri procesele lungi |
| `--asteptare-lacat=5` | secunde de așteptat după un tabel ocupat (`lock_wait_timeout`), apoi se reîncearcă după 2, 4, 8, 16, 30 s. Pe server, implicit e o zi întreagă, timp în care coada cererilor site-ului ar sta în spatele ALTER-ului |
| `--pana-la=<id>` | se oprește după migrarea dată (repetiții, teste); verificarea finală se sare, fiindcă schema e voit incompletă |
| `--site=<site>` | rulează și pașii acelui site (`database/migrari/site/<site>/`); implicit `SITE_SLUG` din `.env` (în `.env.example`). Cele două nu au voie să difere |

Ieșirea nu conține niciodată parole, DSN-uri sau valori din rânduri, doar nume de obiecte și numere.
Un mesaj al serverului care ar arăta o valoare („Duplicate entry 'x@y.ro' for key 'uniq_email'”) se
scrie cu `'…'` în locul valorii („Duplicate entry '…' for key 'uniq_email'”). MariaDB și MySQL nu
dublează ghilimelele unei valori în mesaj (`'o'brien'`), deci `Schema::faraValori` nu caută perechi de
ghilimele: la formele cunoscute cu o valoare (1062, 1366/1292, „near … at line”) valoarea e tot ce stă
între începutul și sfârșitul fixe ale mesajului; formele cunoscute care au doar nume (1050, 1054,
1060, 1061, 1091, 1048, 1364, 1406 …) rămân cum sunt; din orice altă formă cu ghilimele se ascunde tot
ce e între prima și ultima ghilimea. Mesajul are în față codul erorii și SQLSTATE-ul
(`eroare SQL 1062 (23000): …`).

Planul (`--plan`, și simularea dinaintea lui `--aplica`) arată la fiecare ALTER pe un tabel care există
mărimea lui, după estimarea serverului (`information_schema.TABLES`: `~N rânduri, X MB`; în `--json`
câmpurile `randuri` și `octeti`): o copiere a tabelului durează cât copierea acestor octeți. Tot planul
arată tabelele `#sql-*` rămase de la un ALTER omorât la jumătate (pe serverele fără DDL atomic, MariaDB
sub 10.6): nu intră în amprentă și se șterg de mână, după o copie a bazei. `--aplica` scrie
`sql_mode`-ul serverului (pe care nu-l schimbă) în ieșire și în `storage/logs/migrari.log`.

## Codurile de ieșire

| Cod | Ce înseamnă | Ce faci |
|---|---|---|
| 0 | nimic de făcut (`--plan`), totul aplicat și verificat (`--aplica`), verificarea trecută | — |
| 1 | eroare într-o migrare (mesajul spune migrarea și operația), sau un tabel ocupat după toate reîncercările | citești mesajul; rularea din nou e sigură |
| 2 | configurare: fără `DB_DATABASE`, conexiune refuzată, server prea vechi, PHP sub `php_min`, registru greșit (număr folosit de două ori, dosar care nu e un proprietar, fișier fără forma cerută), argument greșit | repari configurarea sau codul |
| 3 | alt runner aplică migrări pe aceeași bază, sincronizarea FAN ține tabelele FAN (`lacatFan`), sau `information_schema` tot nu arată un tabel pe care altă conexiune îl schimbă chiar atunci (mai jos, la amprentă) | rulezi din nou mai târziu |
| 4 | baza nu e a unui site ANDAXI (are tabele, dar nu `settings` + `admins` + `pages` și nici `_platforma_migrari`; de exemplu un WordPress) | verifici `DB_DATABASE`; nimic n-a fost atins |
| 10 | `--plan`: sunt migrări de aplicat, toate fără blocare | `--aplica` cu site-ul pornit |
| 11 | sunt migrări blocante sau de tip contract (`--plan`), refuzate fără `--permite-blocante` / `--permite-contract` (`--aplica`, cu baza neatinsă) | fereastra de mentenanță |
| 12 | o migrare aplicată pe această bază s-a schimbat în cod, a dispărut din cod, sau nu se potrivește cu `migrari.lock` | o schimbare se face într-o migrare nouă; una retrasă se trece la `retrase` |
| 13 | bugetul de timp s-a terminat; starea e consecventă | rulezi din nou (continuă de unde a rămas) |
| 14 | după aplicare (sau la `--verifica`) lipsește un obiect cerut de `asteptat.json`, ori `asteptat.json` lipsește deși există migrări | citești lista `LIPSĂ` |
| 15 | lipsește un parametru al site-ului (`migrari.json` din dosarul site-ului) | îl scrii în pachetul site-ului |

## Fișierele

```
database/migrari/
  nucleu/0001_baza_nucleu.php        un dosar pe proprietar: „nucleu” sau cheia unui plugin din config/plugins.php
  erp/0010_baza_erp.php
  site/<site>/<nume>.php             pașii unui singur site (id site/<site>/<nume>), doar cu --site
  migrari.lock                       {"migrari": {id: sha256}, "retrase": {id: motiv}}: ce s-a publicat
  asteptat.json                      amprenta schemei după toate migrările, pe o bază goală
  ultima.php                         numărul ultimei migrări, pentru garda de la fiecare cerere (WP-05)
src/Support/Migrari/                 Registru, Runner, Schema, Server, Amprenta, Executie, Eroare, ParametruLipsa
scripts/migreaza.php                 linia de comandă
storage/logs/migrari.log             câte un rând pe operație executată (nume de obiecte, clasa, durata)
tools/sonda/amprenta-schema.php      sonda schemei unui site live (WP-10, MIG-08), rulată de proprietar
tools/dev/migrari/din-sonda.php      local: sonda → fixture-ul și extra-<cod>.txt, în tools/dev/migrari/vechi/privat/
tools/dev/migrari/genereaza-fixture.php   local: schema pe care o face codul unui site; asteptat.json, migrari.lock,
                                     ultima.php; verificarea migrărilor de bază față de cod; fixture-urile din vechi/ (mai jos)
tools/dev/migrari/vechi/             fixture-urile testelor: <cod>.sql (schema codului cms, bs, bv, vt, gr), extra-<cod>.txt,
                                     variante.sql + extra-variante.txt (o bază veche, inventată); privat/ = sonda live, în afara git-ului
tools/dev/migrari/excluderi.php      ce nu primește o instalare nouă din schema codului (folosit de genereaza-fixture și de teste)
tools/dev/migrari/ddl-din-cod.txt    lista DDL-ului vechi din cod, permis până la R2 (verifica-migrari.php)
tools/dev/test-migrari.php           testele (runner-ul și T1–T11; T1–T11 stau în tools/dev/migrari/test-baza.php)
tools/dev/verifica-migrari.php       verificarea fișierelor de migrare și a DDL-ului din cod (T11, în testul de fum)
```

- **id** = `<proprietar>/<NNNN>_<nume>`. **NNNN** e unic în tot dosarul (oricare proprietar) și dă
  ordinea. Numerele sunt rezervate pe pachete de lucru în planul Etapei 1 (§4.3).
- Dosarul spune doar **al cui e codul**. Fiecare migrare rulează pe **fiecare site, oricare ar fi
  starea pluginului ei**: o licență schimbată nu are voie să ceară DDL, iar codul comun citește
  coloanele pluginurilor și cu pluginul oprit (istoricul comenzilor).
- `sha256` se calculează pe fișier cu sfârșituri de rând LF. O migrare publicată nu se mai schimbă.

## O migrare

```php
<?php
// database/migrari/erp/0010_baza_erp.php

declare(strict_types=1);

use App\Support\Migrari\Schema;

return [
    'descriere' => 'Coloanele ERP pe comenzi și linii',
    'tip' => 'schema',          // schema | date | contract (pașii site-ului: site)
    'blocanta' => false,        // true = oprește scrierile; runner-ul o refuză fără --permite-blocante
    'sus' => static function (Schema $s): void {
        $s->coloana('orders', 'erp_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");   // niciodată AFTER
        $s->coloana('order_items', 'erp_price_before', 'DECIMAL(10,2) DEFAULT NULL');
        $s->index('orders', 'idx_orders_erp_status', ['erp_status', 'erp_next_retry_at']);
    },
];
```

Exact cheile acestea patru; o cheie în plus sau în minus e codul 2. Nu există migrări „înapoi”.

### Ce poate face (`Schema`)

Fiecare metodă se uită întâi în `information_schema` și face doar ce lipsește.

| Metodă | Dacă lipsește | Dacă există |
|---|---|---|
| `tabel($t, $corp)` | `CREATE TABLE IF NOT EXISTS … ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; cheile străine doar aici | nimic (`coloana()`/`index()` de după aduc structura la zi) |
| `tabelComplet($t, [coloană => definiție], [chei])` | un singur `CREATE TABLE` cu tot (cheile scrise ca în CREATE TABLE: `PRIMARY KEY (id)`, `UNIQUE KEY nume (…)`, `KEY nume (…)`, `CONSTRAINT nume FOREIGN KEY (…) REFERENCES …`) | fiecare coloană cu `coloana()`, fiecare index cu `index()`/`unic()`: doar ce lipsește; cheia primară și cheile străine nu se adaugă (una lipsă = avertisment). La fel dacă tabelul l-a făcut altcineva chiar atunci (codul vechi): CREATE-ul fără efect (nota 1050) nu e o operație, iar orice lipsește sau diferă (o coloană, un index, cheia primară, o cheie străină, o definiție) se tratează ca la un tabel existent |
| `coloana($t, $c, $definitie)` | `ADD COLUMN`, la coadă, cu lanțul de clauze | compară tipul, NULL, valoarea implicită: altfel = **avertisment**, coloana nu se schimbă |
| `schimbaImplicit($t, $c, $valoare)` | eroare | doar valoarea implicită (`ALTER COLUMN … SET DEFAULT`, instant, rândurile neatinse); aceeași deja: nimic. Un pas anume al unei migrări (link_plata/0009), nu „adus la zi” |
| `index($t, $n, [coloane])`, `unic(…)` | `ADD [UNIQUE] INDEX`, `INPLACE, LOCK=NONE`; `unic` numără întâi valorile repetate (NULL nu se numără) și se oprește cu numărul lor | același nume, sau un index cu aceleași coloane în aceeași ordine și aceeași unicitate (sub orice nume) |
| `stergeIndex($t, $n)` | nimic | `DROP INDEX` (un index nu ține date) |
| `modificaColoana($t, $c, $definitie)` | eroare (coloana nouă se face cu `coloana()`) | `MODIFY COLUMN`, clasat mereu „copie”: refuzat fără `--permite-blocante` |
| `stergeColoana`, `stergeTabel` | nimic | doar într-o migrare `'tip' => 'contract'` |
| `date($descriere, $sql, $parametri)` | o instrucțiune INSERT/UPDATE/DELETE/REPLACE, cu condiții care o fac fără efect a doua oară; întoarce rândurile schimbate | |
| `peBucati($t, $pk, $marime, $pas)` | `$pas($db, $de, $pana)` pe intervale ale cheii primare; fiecare bucată și cursorul ei se scriu în aceeași tranzacție, deci după o întrerupere nicio bucată nu se face de două ori; pauză între bucăți; se oprește la capătul bugetului. Fără DDL în pas | |
| `existaTabel/existaColoana/existaIndex`, `dacaTabel($t, fn)`, `dacaColoana($t, $c, fn)` | pentru pașii peste obiecte vechi (o coloană veche de copiat) | |
| `citeste($sql, $parametri)` | doar SELECT/SHOW | |
| `parametruSite($cheie)` | codul 15, înainte de orice schimbare | valoarea din `migrari.json` din dosarul site-ului (`SITE_DATE_DOSAR`) |
| `lacatFan('localitati'\|'strazi', 60)` | | ia lacătul sincronizării FAN (același nume ca `FanNomenclator`) până la finalul migrării; neobținut → codul 3 |
| `avertisment($text)` | un rând în plan și la aplicare (doar structură și numere) | |

### Regulile

- **Fără `AFTER`/`FIRST`** (blochează pe MySQL < 8.0.29 și eșuează în tăcere când coloana de
  referință lipsește) și **fără `IF NOT EXISTS` în ALTER** (există doar pe MariaDB): detectarea o
  face runner-ul, la fel pe ambele servere.
- **Doar adăugări** într-un release (expand); ștergerile și redenumirile vin la un release după ce
  niciun cod nu mai folosește obiectul (contract). Codul de dinainte merge mereu pe schema nouă,
  deci revenirea automată la codul vechi rămâne sigură.
- **NOT NULL fără DEFAULT** pe un tabel cu rânduri e refuzat: codul vechi n-ar mai putea scrie.
- **O coloană existentă nu se schimbă niciodată singură**: o definiție diferită e un avertisment.
- **Fără `CHARACTER SET`/`CHARSET`/`COLLATE` pe o coloană** (refuzate, codul 1, și în corpul lui
  `tabel()`): coloana ia colația tabelului (`utf8mb4_unicode_ci`). Colația schimbă felul în care se
  compară textele, deci și ce respinge un index unic; amprenta o arată pe a unei coloane doar când
  diferă de a tabelului, iar o migrare care ar cere-o n-ar avea cu ce s-o compare.
- **Fără conținut în migrări** (pagini implicite, lista implicită de newsletter, categorii de blog):
  acelea sunt pașii de activare ai pluginurilor (WP-05).
- **Fără valori din rânduri** în mesaje, avertismente sau jurnal.

## Cum rulează `--aplica`

1. Conexiunea, cu setările lui `Database::connection` (utf8mb4, fusul orar al aplicației), și
   `lock_wait_timeout` scurt. Serverul sub MariaDB 10.3 / MySQL 8.0 → codul 2. `sql_mode` rămâne al
   serverului (cel cu care scrie și site-ul) și se scrie în raport și în jurnal.
2. Baza e a unui site ANDAXI (codul 4 altfel), apoi lacătul `platforma_migrari:<MD5(baza)>`
   (serverul e împărțit între site-uri; codul 3 dacă îl ține altcineva).
3. Registrul și `migrari.lock`; o migrare aplicată schimbată sau dispărută → codul 12.
4. **Simularea** tuturor migrărilor de aplicat, în ordine, pe un model comun (a doua vede ce creează
   prima): planul, valorile repetate sub un index unic, NOT NULL fără DEFAULT, parametrii site-ului.
   O migrare blocantă (declarată, sau cu o operație clasată „copie”) sau de tip contract fără voie →
   codul 11 **înainte de orice schimbare** (nici tabelele de urmărire nu se creează).
5. Tabelele de urmărire (singurul DDL în afara migrărilor).
6. Fiecare migrare: întâi cele întrerupte, apoi cele noi, după număr; pașii site-ului la urmă.
   Rândul ei e `in_curs`, apoi `aplicata` (a schimbat ceva) sau `detectata` (totul exista deja: o
   bază veche, sau o copie restaurată fără rândurile de urmărire). Fiecare ALTER încearcă
   `ALGORITHM=INSTANT`, apoi `ALGORITHM=INPLACE, LOCK=NONE`; fără clauză (copierea tabelului) doar cu
   `--permite-blocante`. Erorile 1050/1060/1061/1091 (obiectul a apărut între timp, de exemplu creat
   de codul vechi în aceeași clipă) se acceptă dacă baza e acum cum trebuie; 1205/1213 se reîncearcă.
   La capătul bugetului: `partiala` și codul 13.
7. Verificarea față de `asteptat.json`, apoi starea (`ultima_ord`, `amprenta`, `server`,
   `ultima_rulare` cu codul final al rulării, de exemplu 14).

**Starea o scrie doar un runner care a început să aplice:** ține lacătul, codul lui se potrivește cu
baza (pasul 3) și a trecut de simulare (pasul 4). Unul refuzat n-o atinge: lacătul ținut de altul (3),
cod mai vechi decât baza sau o migrare schimbată (12), bază străină (4), simularea oprită (1, 11, 15),
configurarea (2). Altfel, un cod vechi pornit din greșeală ar coborî `ultima_ord`, pe care o citesc
garda (`/health`, WP-05) și CRM-ul. O eroare în timpul aplicării (1, 3 de la lacătul FAN, 13) scrie
starea, sub lacăt, cu codul ei.

### Tabelele de urmărire

`_platforma_migrari` (un rând pe migrare: `id`, `ord`, `proprietar`, `tip`, `sha256`, `blocanta`,
`rezultat` = in_curs | aplicata | detectata | partiala, `operatii` = câte au schimbat ceva,
`cursor_json`, `cod_versiune`, `server_versiune`, `inceputa_la`, `terminata_la`, `durata_ms`) și
`_platforma_stare` (`ultima_ord` = cea mai mare ord până la care toate migrările din cod sunt
aplicate sau detectate; `amprenta` = sha256 al listei „id:sha” a codului care a rulat; `server`;
`ultima_rulare`). Prefixul `_platforma_` e al runner-ului; tabelele lui nu intră în amprentă. Ele
călătoresc în copia bazei, deci o bază restaurată continuă exact de unde era.

## Amprenta și `asteptat.json`

`Amprenta` citește structura unei baze și o scrie la fel pe MariaDB și pe MySQL: lățimea întregilor
(`int(11)` = `int`; rămâne doar la `tinyint(1)` cu semn, ca la MySQL: `tinyint(1) unsigned` =
`tinyint unsigned`), `zerofill` înseamnă și `unsigned`, `year(4)` = `year`, `json` = `longtext`,
valorile implicite (MariaDB pune textele între ghilimele și scrie `NULL`, MySQL nu; aici: textele între
ghilimele, numerele fără, cu zecimalele coloanei, expresiile cu litere mici, `current_timestamp`;
`b'101'` pe o coloană BIT; pe MySQL un text ca `x'y` sau `0x12` pe un VARCHAR rămâne text), `EXTRA`
fără `DEFAULT_GENERATED`, `NO ACTION` = `RESTRICT`, `utf8mb3` = `utf8`. MariaDB sare din `information_schema`
un tabel pe care altă conexiune îi schimbă chiar atunci structura (avertismentul 1684, „was skipped since its
definition is being modified by concurrent DDL statement”): fără grijă, tabelul ar părea că lipsește (o
coloană de adăugat la un tabel „inexistent”, un `LIPSĂ` fals la verificare). `Amprenta` se uită după fiecare
citire la avertismente și, dacă un tabel a fost sărit, citește totul din nou după o pauză scurtă (în total
~3 s), apoi se oprește cu codul 3 (rulează din nou). S-a văzut în testul T6, cu codul vechi care își face
tabelele în același timp cu `--aplica`. Colația unei coloane intră
(`"colatie"`) doar când diferă de a tabelului (de exemplu `utf8mb4_bin` sau alt set de caractere): o
coloană fără ea are colația tabelului, deci `asteptat.json` de azi n-o are nicăieri. Ordinea coloanelor
nu contează. Tabelele `_platforma_*`, `*__sync`, `*__vechi` (copiile de lucru ale sincronizării FAN) și
`#sql*` nu intră. Ce nu se aduce la aceeași formă: valorile implicite ale coloanelor BINARY/VARBINARY
(MariaDB scrie octeții, MySQL hex); niciun cod de azi nu le folosește. Ce nu se vede: un `longtext`
`utf8mb4_bin` (pe MariaDB așa arată o coloană JSON, pe MySQL JSON n-are colație), deci și un LONGTEXT
COLLATE utf8mb4_bin adevărat; niciun cod de azi nu are.

`asteptat.json` = amprenta unei baze goale după toate migrările (`--aplica`, apoi `--amprenta`). La
`--verifica` (și la finalul lui `--aplica`): un tabel, o coloană sau un index care lipsește (un index
echivalent sub alt nume ajunge) = codul 14; o definiție diferită, o cheie străină lipsă (nu se adaugă
niciodată singură peste date existente) = avertisment; ce are baza în plus = doar informație.

După orice schimbare a migrărilor (o migrare nouă; înainte de prima publicare, și una schimbată):

```sh
tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat           # asteptat.json, migrari.lock, ultima.php
tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat --rescrie-lacatul   # doar înainte de prima publicare
```

`--asteptat` aplică toate migrările pe o bază goală (`cms_mig_fx_asteptat`), scrie amprenta ei, o verifică
și scrie `ultima.php`. O migrare care e deja în `migrari.lock` cu alt sha e refuzată (o migrare publicată nu
se schimbă: o schimbare înseamnă o migrare nouă); o eroare lasă cele trei fișiere cum erau.

## Migrările de bază (baseline): 0001–0024

Primele migrări sunt schema de azi a codului, împărțită pe proprietari, plus conversiile de date pe care
codul le face azi la fiecare cerere. Pe o bază goală **creează** schema; pe o bază veche (oricare dintre
cele patru site-uri) **găsesc** fiecare obiect și adaugă doar ce lipsește. Nu există un mod „marchează ca
aplicat”: o bază veche ajunge „detectata” pentru că totul e acolo, verificat unul câte unul.

**De unde vin definițiile.** Din schema pe care o face codul pe o bază goală: `scripts/install.php`
(`database/schema.sql` plus ALTER-urile lui), apoi fiecare funcție `ensure*Schema`/`asigura*Schema`,
până nu mai schimbă nimic (`tools/dev/migrari/genereaza-fixture.php --cod=cms`: 57 de tabele, 665 de
coloane, 146 de indecși, 6 chei străine; prima trecere prin funcții trimite 391 de instrucțiuni, din care
240 eșuează, fiecare trecere de după 387, din care 287 eșuează). Fiecare tabel e un `tabelComplet()`, cu
definiția fiecărei coloane scrisă explicit (`NULL`/`NOT NULL`, valoarea implicită), ca o instalare nouă
să iasă la fel oricare ar fi serverul (de exemplu `explicit_defaults_for_timestamp`).

| Grupul B (schemă) | Ce conține |
|---|---|
| `nucleu/0001_baza_nucleu` | adminii, setările, paginile, `seo_pages`, galeria, mesajele de contact, istoricul emailurilor, jurnalul adminului, jurnalele formularelor |
| `catalog/0002_baza_catalog` | produsele (fără coloanele precomenzii și ale ofertelor BBD), categoriile, câmpurile extra, șabloanele de produs |
| `conturi_clienti/0003_baza_conturi` | `users` (fără punctele de fidelitate), adresele, resetările de parolă; înaintea magazinului, pentru că `orders` are o cheie străină spre `users` |
| `magazin/0004_baza_magazin` | comenzile (coloanele magazinului), liniile, emailurile comenzilor, coșurile abandonate |
| `livrare_fan/0005_baza_fan` | tabelele FAN, coloanele FAN și de AWB ale comenzilor; ține lacătele sincronizării nomenclatorului; pasul vechi al străzilor (mai jos) |
| `plata_stripe/0006`, `plata_euplatesc/0007` | coloanele plăților pe comenzi |
| `plata_bt/0008_baza_bt` | tabelele BT iPay; `bt_ipay_schema_version` = `3` în setări, dacă nu e deja |
| `link_plata/0009_baza_link_plata` | linkurile de plată, cu procesatorul linkurilor vechi (mai jos); `payment_links_schema_version` = `2` |
| `erp/0010` … `acorduri_gdpr/0019` | ERP, cupoane, fidelitate, recenzii, precomenzi, oferte BBD, produse promoționale, blog, newsletter, acorduri GDPR |

| Grupul C (date, o singură dată) | În locul a ce face codul la fiecare cerere |
|---|---|
| `nucleu/0020_roluri_admini` | rolurile adminilor aduse la forma de azi, cu aceiași doi pași: un `role` gol devine `administrator_general` (aceeași condiție, `role IS NULL OR role = ''`, comparată de server), apoi regula lui `Auth::rolesFromStorage` + `primaryRole`; în loc de UPDATE-ul pe fiecare admin la fiecare pagină de admin și la fiecare login |
| `catalog/0021_coloane_vechi_catalog` | galeria, insignele, câmpurile extra, șabloanele, valorile câmpurilor din coloanele vechi (doar dacă baza le are; pe bucăți de 500) |
| `cupoane/0022_coloane_vechi_cupoane` | numele gol = codul; limitele din `min_items`/`max_items` |
| `recenzii/0023_recenzii_vechi` | recenziile din tabelul vechi `reviews` care lipsesc din `product_reviews` (o dată: una ștearsă nu mai revine), apoi sursa celor fără sursă |
| `blog/0024_legaturi_categorii` | legăturile articol–categorie din categoria principală a articolelor vechi |

**Ce nu primește o instalare nouă** (doar site-urile care le au le păstrează, până la faza „contract”):
tabelul vechi `reviews`, tabelele moarte `mannequin_points`/`mannequin_point_products` (pluginul manechin
ține punctele în setări), coloana veche `products.gallery_json`; nici conținutul pe care îl punea codul la
prima creare: categoriile de blog Bioscem, lista implicită de newsletter (o face
`NewsletterService::defaultListId` când e nevoie), pagina `/acorduri-gdpr` (pasul de activare al
pluginului, WP-05). Singura altă diferență față de schema codului: `bt_ipay_transactions` și `bt_ipay_log`
au la cod colația implicită a serverului (`DEFAULT CHARSET=utf8mb4` fără colație), la migrări
`utf8mb4_unicode_ci`, ca toate tabelele; pe o bază veche rămân cum sunt (un avertisment la `--verifica`).
Valorile implicite rămân cele de azi (TVA-ul produselor 19.00, `orders.payment_method` = `euplatesc`); pe
Biovitality, vertij și grafoanaytis, `payment_method` are alt implicit (`stripe`): un avertisment, nu o
schimbare.

**Linkurile de plată fără procesator** (codul Biovitality și grafoanaytis): procesatorul linkurilor vechi îl
știe doar site-ul, din `link_plata.procesator_linkuri_existente` în `migrari.json` al site-ului (`euplatesc`,
`btipay` sau `stripe`; lipsă sau altă valoare → codul 15, înainte de orice schimbare). Coloana se adaugă cu
el ca valoare implicită, deci fiecare link existent îl primește în același ALTER, iar apoi valoarea
implicită devine `euplatesc` (`schimbaImplicit`). Oricând s-ar opri rularea, niciun link vechi nu rămâne cu
alt procesator, iar reluarea termină pasul.

**Străzile FAN de dinainte de `row_key`** (pasul vechi din `FanNomenclator::asiguraSchemaStrazi`):
`row_key` vine cu `''` (cum o adăuga codul), rândurile își primesc cheia pe bucăți, cu formula de acolo (cu
id-ul rândului, deci unică), indexul unic vechi `uniq_fan_streets` iese, iar `uniq_fan_streets_row` vine abia
la urmă. O reluare vede rândurile rămase cu `''`.

**Cum se verifică** (`tools/dev/migrari/genereaza-fixture.php --verifica`, poarta **G-baza** din
`porti.sh`, pe MariaDB și pe MySQL 8; de la WP-04 o rulează testul T1, deci și poarta G-mig): o bază goală după toate migrările = `asteptat.json`, fără
avertismente; baseline-ul pe o bază goală = schema codului minus excluderile de mai sus (fiecare trebuie să
existe în schema codului) și colația declarată; pe baza făcută de cod („pe vechi”), planul are în grupul B
doar „există deja”, aplicarea face 0 operații (toate 19 „detectata”), schema rămâne aceeași, `--verifica`
trece (în plus: exact excluderile), a doua aplicare n-are nimic de făcut; fixture-ul SQL făcut pe MariaDB se
încarcă pe fiecare server și dă aceeași amprentă; pe MySQL 8 codul vechi face aceeași schemă ca pe MariaDB.
Pentru grupul C: aceleași rânduri inventate (`GF_RANDURI_GRUP_C`: admini cu role gol, doar din spații, cu
un tab, roluri necunoscute sau repetate, JSON greșit; o galerie veche; un cupon fără nume; o recenzie din
`reviews` necopiată și una fără sursă; un articol fără legătura la categoria lui) trec o dată prin funcțiile
codului și o dată prin migrări, iar rezultatul trebuie să fie același, rând cu rând (fiecare migrare de date
trebuie să schimbe ceva, altfel cazul nu dovedește nimic). Testul de fum cere în plus ca, pe baza de
dezvoltare (făcută tot de cod, cu date demo), grupul B să nu aibă nicio operație.

`genereaza-fixture.php` face schema codului pe o **copie** a lui (arborele de lucru copiat, sau
`--repo=<clonă>@<commit>` cu `git archive`), într-un proces nou pe fază, cu pluginurile toate pornite și
fără licență, pe o bază `cms_mig_fx_*` inventată (în ea ajung doar setările implicite și un admin
`@exemplu.ro`). Pentru celelalte coduri: `--cod=bs --repo=../Nou-site-bioscem@<commit>` (la fel `bv`, `vt`,
`gr`) scrie `<cod>.sql` (doar structură, se încarcă pe ambele servere) și `<cod>-amprenta.json` în
`$CMS_DEV_DIR/fixture-migrari/` (sau `--iesire=`); `--compara <a.json> <b.json> …` arată fiecare amprentă
față de prima. Azi, BS = CMS; BV și GR primesc 3 tabele și 24 de coloane, VT 8 tabele, 57 de coloane și
un index. Schema codului e a lui `install.php` de azi: când instalarea trece pe migrări (WP-05),
referința devine un commit fix (`--verifica --repo=<clona CMS>@<commit>`).

**Fixture-urile din git** (`tools/dev/migrari/vechi/`, testul T2): `genereaza-fixture.php --fixture-uri`
scrie `<cod>.sql` și `extra-<cod>.txt` pentru `cms`, `bs`, `bv`, `vt`, `gr`. Codul vechi al unui site e clona
lui (`CMS_DEV_REPO_BS`, `_BV`, `_VT`, `_GR`) la „ultimul commit revizuit” din [de-portat.md](de-portat.md);
`cms` e acest repo la commit-ul scris în capul lui `cms.sql` (`fc8a5e1`, ultimul înainte de migrări;
`--cms=<commit>` îl schimbă): schema pe care o face codul comun pe vechi. Sursa stă în capul fișierului
(`-- sursa: <repo>@<commit>`). `--verifica-fixture-uri` (rulat de T2) reface schema din fiecare sursă și cere
aceeași amprentă ca fișierul din git, `extra-<cod>.txt` la zi cu `asteptat.json`, iar sursa unui site vechi
egală cu „ultimul commit revizuit” al lui: când WP-R mută acel commit (o livrare SEO a Bioscem care adaugă o
coloană din mers, de exemplu), testul pică până la `--fixture-uri`, iar diff-ul arată ce s-a schimbat în
schema site-ului. Fixture-urile au doar structură, făcută de cod: nimic dintr-o bază live (OD-49).
`variante.sql` (scris de mână, peste `cms.sql`) face din ea baza unui site vechi: coloanele vechi ale
catalogului și ale cupoanelor, `reviews` cu rânduri, `fan_streets` de dinainte de `row_key` cu indexul unic
vechi, linkuri de plată fără procesator, copiile unei sincronizări FAN întrerupte, tabelele manechinului în
cealaltă formă, câteva rânduri inventate; `extra-variante.txt` spune ce rămâne din ele după baseline.

## Serverele

Pe Hostinger nu se știe încă dacă e MariaDB sau MySQL (o spune sonda). Runner-ul nu presupune nimic:

| | MariaDB 10.3 | MariaDB 10.4+ | MySQL 8.0.12–8.0.28 | MySQL 8.0.29+ |
|---|---|---|---|---|
| `ADD COLUMN` la coadă, instant | da (10.3.2+) | da | da | da (până la 64 de versiuni ale rândului, apoi INPLACE) |
| `DROP COLUMN` instant | nu | da | nu | da |
| index nou fără blocare (`INPLACE, LOCK=NONE`) | da | da | da | da |

Testele rulează pe MariaDB 10.11 și pe MySQL 8.0 (`tools/dev/mysql8.sh` pornește al doilea server,
doar pe socket) și cer aceeași amprentă pe amândouă.

## Sonda schemei de pe site-urile live (WP-10, MIG-08)

Schema pe care o produce codul unui site nu e neapărat cea din baza lui live (coloane vechi, părți
niciodată create, altă colație, tabele lăsate de scripturi unice; MIG-08 din planul Etapei 1). Sonda o citește:
`$PHP tools/sonda/amprenta-schema.php --site=<bioscem|grafoanaytis|biovitality|vertij>`, rulată de
proprietar, noaptea, din dosarul site-ului de verificare (pașii în [VERIFICARE.md](VERIFICARE.md), pasul 5),
strict în citire. Scrie un JSON cu rândurile din `information_schema` în forma lui `Amprenta::citesteBrut()`
(deci `Amprenta::normalizeaza()` le aduce la aceeași amprentă ca orice bază locală), valorile serverului
(`sql_mode`, `lock_wait_timeout`, motorul și formatul implicit al rândurilor…), drepturile utilizatorului
bazei (pentru runner: are ALTER și INDEX?), numele cheilor din `settings`, rândurile fiecărui tabel și
numerele pe care le citesc migrările de date. Niciun rând de date, nicio parolă.

Local, `tools/dev/php.sh tools/dev/migrari/din-sonda.php <amprenta-<site>-….json>` scrie în
`tools/dev/migrari/vechi/privat/` (ignorat de git: repo-ul e public, OD-49) `<cod>-live.sql` (schema ca
`CREATE TABLE`, cu coloanele în ordinea lor, colațiile, indecșii și cheile străine; încărcată, dă aceeași
amprentă, pe MariaDB și pe MySQL 8), `extra-<cod>.txt` (obiectele în plus față de `asteptat.json`, pentru
testul T2) și `<cod>-fata-de-asteptat.txt` (ce lipsește sau diferă). Fixture-urile din git rămân cele făcute
din cod (WP-04), potrivite de mână după structura sondei, niciodată după numerele ei (până vine prima
sondă nu e nimic de potrivit). `din-sonda.php` refuză o intrare sau o ieșire din repo pe care git nu o
ignoră, și cu căi relative (le face absolute față de dosarul curent înainte de verificare; `tools/dev/php.sh`
pornește din rădăcina repo-ului).

**T2-live.** Cât timp `tools/dev/migrari/vechi/privat/<cod>-live.sql` lipsește, `test-migrari.php` scrie
`SĂRIT T2-live <cod>` cu motivul (și în rândul final). Când fișierele există (`din-sonda.php` pe sonda
trimisă de proprietar), T2-live le ia singur: schema live se încarcă pe MariaDB și pe MySQL 8, iar baseline-ul
trebuie să treacă la fel ca pe fixture-urile din cod (planul fără nicio copiere de tabel, aplicarea exact cum
arăta planul, nimic șters sau schimbat din ce exista în afară de indexul vechi `uniq_fan_streets`, în plus
exact `extra-<cod>.txt`, avertismentele = cele de dinainte). Parametrul procesatorului linkurilor vechi (BV, GR)
e cel de test (`stripe`): baza refăcută nu are rânduri. Calea e verificată și fără sonda reală, pe o sondă
simulată (schema `bs` + variantele, citită în forma sondei și trecută prin `din-sonda.php`).

## Linia de comandă fără sesiune

`bootstrap.php` nu mai pornește o sesiune PHP în linia de comandă (`PHP_SAPI === 'cli'`): `$_SESSION`
e un array gol, fără fișier în `storage/sessions` („Erori moștenite” 5 din DEZVOLTARE.md). Un script
care chiar vrea o sesiune definește `ANDAXI_CU_SESIUNE` înainte de `require`. Paginile (și `php -S`,
SAPI „cli-server”) pornesc sesiunea ca până acum.

## Testele

`tools/dev/php.sh tools/dev/test-migrari.php` (poarta **G-mig** din `porti.sh`, și cu PHP-ul minim; `--runner`
= doar runner-ul, poarta **G-runner**; `--migrari` = doar migrările adevărate, fără D și R; `--doar-mariadb` =
fără MySQL 8, rezultat parțial, spus explicit). Raport: `storage/logs/dev/test-migrari-raport.txt`. Bazele
`cms_mig_t_*` și dosarul de lucru se fac și se șterg acolo; MySQL 8 se oprește la final dacă l-a pornit testul.

**Runner-ul** (U, D, R, T, S; WP-02, WP-03): fără bază (Server, Amprenta pe rândurile ambelor servere,
definițiile, corpul unui CREATE TABLE, compararea, registrul), apoi pe fiecare server detectarea, Schema direct
și fiecare cod de ieșire al lui `scripts/migreaza.php` pe migrări de jucărie: oprirea după fiecare operație și
reluarea (aceeași amprentă, fiecare rând numărat o dată), oprirea chiar înainte și chiar după commit-ul
fiecărei bucăți și al instrucțiunii de date (pică dacă cursorul sau numărătoarea ar ieși din tranzacția
bucății), bugetul, migrările schimbate, blocante, de contract, parametrii site-ului, lacătele, starea neatinsă
de un runner refuzat și scrisă cu codul final, un tabel ocupat, mărimea tabelelor în plan, un tabel `#sql-*`
rămas, `sql_mode`, verificarea, configurarea, pașii site-ului, mesajele serverului cu ghilimele și `\` în
valoare (1062, 1366, din `date()` și din `peBucati()`); `tabelComplet` (și cursa cu codul vechi care face
tabelul chiar atunci, fără o coloană, sau cu toate coloanele dar fără indecși și fără cheia străină) și
`schimbaImplicit`; colația coloanelor (în amprentă doar cea diferită de a tabelului, la fel pe ambele servere;
CHARACTER SET/COLLATE refuzate într-o migrare); un tabel sărit de `information_schema` (1684) citit din nou,
iar sărit mereu, codul 3; plus sesiunile din linia de comandă.

**Migrările adevărate** (M, T1–T11 din migrari.md §4; WP-04; în `tools/dev/migrari/test-baza.php`), pe
MariaDB și pe MySQL 8:

| Test | Ce face | Trece când |
|---|---|---|
| T1 | o bază goală → `--plan`, `--aplica`; plus `genereaza-fixture.php --verifica` (G-baza, mai sus) | planul 10 fără avertismente, nimic scris; aplicarea 0 (grupul B „aplicata”, C „detectata”), fără avertismente; amprenta = `asteptat.json`; `_platforma_stare` la zi; G-baza trece |
| T2 | fiecare `vechi/<cod>.sql` (cms și bs cu versiunile de schemă pe care le scrie codul Bioscem) → `--plan`, `--aplica`, `--verifica`; plus `--verifica-fixture-uri` | grupul B are exact numerele din migrari.md §2.2 (cms, bs: nimic; bv, gr: 3 tabele, 24 de coloane, valoarea implicită a procesatorului; vt: 8 tabele, 57 de coloane, un index), nicio copiere de tabel; aplicarea face exact ce arăta planul; nimic șters sau schimbat din ce exista, adăugat exact ce lipsea; în plus exact `extra-<cod>.txt`; avertismentele = diferențele de definiție de dinainte; bv și gr fără parametrul procesatorului: 15, baza neatinsă |
| T2, baza veche | `cms.sql` + `variante.sql`, o dată prin funcțiile `ensure*` ale codului (o trecere, ca o cerere), o dată prin migrări | datele (roluri, galerie, insigne, câmpuri extra, șabloane, valori, cupoane, recenzii, legături de blog, `row_key`-ul străzilor, versiunile de schemă) ies la fel, fiecare citire schimbată; procesatorul linkurilor = parametrul site-ului; în plus exact `extra-cms` + `extra-variante`; scos doar `uniq_fan_streets`; copiile sincronizării FAN neatinse |
| T2-live | schema live (sonda, mai sus) sau sonda simulată | ca T2, fără numerele fixe ale planului |
| T3 | a doua aplicare, după T1 și după fiecare T2 | nicio migrare rulată, aceleași rânduri (CHECKSUM TABLE), aceeași amprentă; `--plan` 0 |
| T4 | migrările până la penultima (și până la finalul grupului B), `seed.php` și `tools/dev/date-demo.php` (prin `tools/dev/php.sh`, pe baza testului), apoi restul | amprenta = `asteptat.json`; tabelele schimbate ⊆ tabelele migrărilor rămase (o migrare de date nouă își trece tabelele în `$tabeleDate` din test) |
| T5 | oprit (`MIGRARI_TEST_OPRESTE_DUPA`) după fiecare operație a instalării noi și a bazei vechi, și chiar în/după commit-ul unor bucăți și instrucțiuni de date (`MIGRARI_TEST_OPRESTE_IN`), apoi reluat (câte 4 în paralel, pe baze separate) | aceeași amprentă, aceleași date, nimic „in_curs”, fiecare operație numărată o dată |
| T6 | funcțiile `ensure*` ale codului după primele 4 migrări, și în același timp cu `--aplica` (1, 2, 3 procese; `--aplica` cu pauzele de pe site-uri la 1205/1213, pe MySQL 8 un ALTER al codului și unul al migrării dau uneori deadlock de câteva ori la rând) | `--aplica` 0 (cursele acceptate: aici s-a găsit 1684), baza completă; în plus doar excluderile; avertismente doar colația tabelelor BT și cheile străine ale tabelelor făcute întâi de cod |
| T7 | pe baza instalată, o copie a migrărilor cu una aplicată schimbată, apoi și cu lacătul rescris, apoi ștearsă, apoi retrasă | 12, 12, 12, apoi 0; baza și starea neatinse |
| T8 | o migrare de probă cu `MODIFY COLUMN`, declarată neblocantă | `--plan` și `--aplica` 11, nimic aplicat; cu `--permite-blocante`, aplicată |
| T9 | amprentele din T1, T2, baza veche, T4 | aceleași pe ambele servere |
| T10 | o bază făcută pe vechi (`install.php`, `seed.php`, `date-demo.php`) → baseline; apoi funcțiile `ensure*` ale codului comun și ale codului Bioscem (clona la „ultimul commit revizuit”), pe ea și pe o bază nouă făcută de migrări | baseline-ul nu schimbă schema, datele doar unde declară migrările; `--verifica` 0; funcțiile vechi nu mai schimbă nimic (schemă, rânduri). Testele aplicației pe o bază migrată (fum, pluginuri, SEO, comparația cu Bioscem) vin cu instalarea prin migrări (WP-05) |
| T11 | `tools/dev/verifica-migrari.php` pe repo și pe o copie cu câte o greșeală | 0 pe repo; 1, cu motivul, pe fiecare greșeală (mai jos) |

## Verificarea fișierelor de migrare (`tools/dev/verifica-migrari.php`)

Rulează în testul de fum (`smoke.sh`, la început, pe fiecare pachet) și în poarta G-mig: nu există CI, deci
aici se oprește o migrare greșită sau un DDL nou în cod. Fără server: `--fara-baza` (punctele 6 și 7 nu se fac,
spus în rezultat).

1. Registrul: forma fiecărui fișier, numărul NNNN unic în tot dosarul, dosarul = `nucleu` sau un plugin.
2. `migrari.lock` are exact migrările din cod, cu sha-ul lor (una nouă se trece cu `genereaza-fixture.php
   --asteptat`), o migrare retrasă are motivul; `ultima.php` e la zi.
3. În fișierele de migrare: niciun DDL scris de mână (totul prin `Schema`); în definiții niciun `AFTER`/`FIRST`,
   `IF [NOT] EXISTS`, `CHARACTER SET`/`CHARSET`/`COLLATE`; `stergeColoana`/`stergeTabel` doar în „contract”.
   Runner-ul le refuză și el, dar doar pe ramura care rulează; aici se văd și cele de pe ramurile pentru
   obiecte vechi (`dacaColoana`).
4. **DDL-ul din codul de pe site-uri** (`src/` fără `src/Support/Migrari/`, `scripts/`, `public/`, `views/`,
   `config/`, `bootstrap.php`, `database/schema.sql`): fiecare text DDL și fiecare definiție de coloană dintr-o
   funcție cu DDL (listele de coloane din `ErpSync::ensureSchema`, `BtIpayPayments::migreaza`) trebuie să fie
   în `tools/dev/migrari/ddl-din-cod.txt`, pe fișier și funcție, de câte ori apare (azi 371 de texte și 26 de
   definiții în 38 de funcții și fișiere: cele 36 de funcții `ensure*`/`asigura*`, `install.php`,
   `fan-tracking-sync.php`, `schema.sql`, tabelele de lucru ale sincronizării FAN, care rămân, și tiparul din
   `WordPressDumpReader`). Unul nou, încă o copie a unuia vechi sau unul mutat în altă funcție = încălcare: o
   coloană nouă se face cu o migrare (regula 2 din planul Etapei 1). Unul care a ieșit din cod = încălcare până
   la `--actualizeaza-lista`, care doar scoate rânduri (lista nu crește niciodată; `--lista-initiala` a scris-o o
   dată, în WP-04, și refuză o listă existentă).
5. `asteptat.json` există și are forma lui `Amprenta`.
6. Pe o bază goală (MariaDB, `cms_mig_v_*`): `asteptat.json` = amprenta după toate migrările.
7. Din planul unei baze goale: fiecare tabel, coloană și index pe care îl face o migrare e, în catalog
   (`config/plugins.php` → `tabele`, „tabel” sau „tabel.coloană*”), al proprietarului ei (un index: al
   tabelului sau al uneia dintre coloanele lui); fiecare tabel din `asteptat.json` are un proprietar.

Variabile doar pentru teste (pe site-uri nu există): `MIGRARI_TEST_DOSAR` (alt dosar de migrări),
`MIGRARI_TEST_OPRESTE_DUPA=k` (oprire ca un proces omorât după k operații),
`MIGRARI_TEST_OPRESTE_IN=<punct>:k` (oprire la a k-a trecere prin `date_in_tranzactie`,
`date_dupa_commit`, `bucata_in_tranzactie` sau `bucata_dupa_commit`), `MIGRARI_TEST_PAUZE` (secundele
reîncercărilor, de exemplu `0.1,0.1`), `MIGRARI_TEST_PAUZA_BUCATI_MS`, `MIGRARI_TEST_JURNAL`.
