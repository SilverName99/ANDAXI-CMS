# Migrările bazei de date

Schema bazei (tabelele, coloanele, indecșii) se schimbă dintr-un singur loc: **`scripts/migreaza.php`**,
din linia de comandă, la instalare (`scripts/install.php` rulează aceleași migrări) și la fiecare release.
Paginile nu trimit DDL: pe o bază la zi cu migrările, cele 36 de funcții `ensure*Schema` moștenite din
Bioscem ies pe loc (garda R1, mai jos); corpurile lor rămân doar pentru o bază care n-a văzut încă
runner-ul (un site de dinainte de adoptare) și se șterg după cele patru adoptări (R2, MIG-11).

Ce există: **runner-ul**, cu detectarea, reluarea și codurile de ieșire, plus testele lui (WP-02 din
planul Etapei 1); din WP-10, **sonda schemei** site-urilor live; din WP-03, **migrările de bază**
`0001`–`0024`, cu `asteptat.json`, `migrari.lock`, `ultima.php` și unealta care le compară cu schema
făcută de cod (`tools/dev/migrari/genereaza-fixture.php`); din WP-04, **testele T1–T11** pe schemele celor
cinci coduri (și pe cele live, când vine sonda) și **verificarea fișierelor de migrare**
(`tools/dev/verifica-migrari.php`, în testul de fum); din WP-05, **instalarea prin migrări**, **garda**
`Garda::stare` (cu `/health` și bannerul din admin), **pașii de activare** ai pluginurilor și **contorul de
instrucțiuni** din dezvoltare; din WP-06, **înghețarea setărilor** fiecărui site vechi (pasul
`site/<site>/setari-inghetate`, cu generatorul și verificarea lui din `tools/inghetare/`) și **parametrii
site-ului** (`migrari.json`), toate mai jos. Garda spune `la_zi` doar după pașii site-ului, deci pașii de
activare (mai jos) așteaptă și înghețarea.

## Pe scurt

```sh
php scripts/migreaza.php --plan        # ce s-ar face pe această bază (nu schimbă nimic)
php scripts/migreaza.php --aplica      # aplică ce lipsește, apoi verifică baza
php scripts/migreaza.php --verifica    # baza față de database/migrari/asteptat.json (doar nume de obiecte)
php scripts/migreaza.php --stare       # ultima_ord, ce lipsește, ce e întrerupt, dacă rulează alt runner
php scripts/migreaza.php --amprenta    # amprenta schemei acestei baze, JSON (doar structură)
php scripts/install.php                # un site nou, pe o bază goală: migrările, seed-ul neutru, primul administrator
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
| `--site=<site>` | rulează și pașii acelui site (`database/migrari/site/<site>/` și înghețarea din pachetul lui, `SITE_DATE_DOSAR`); implicit `SITE_SLUG` din `.env` (în `.env.example`). Cele două nu au voie să difere. Codul 2, înainte de orice schimbare: un pachet cu înghețare fără site ales, pachetul altui site, un `SITE_DATE_DOSAR` care nu există, un pachet fără înghețarea pe care o are codul în `site-uri/<site>/`, sau unul care nu mai are un pas pe care baza îl așteaptă (`pasi_site`; mai jos, „Înghețarea setărilor”) |
| `--permite-provizoriu` | doar pe copia de verificare (`SITE_COPIE_VERIFICARE=1` în `.env`, scris de `configureaza.php`), la repetiția adoptării: aplică și o înghețare `provizoriu` sau un parametru din `_provizoriu` (mai jos, „Înghețarea setărilor”). Fără ea, sau pe orice alt site (unde nu are efect), codul 15 înainte de orice schimbare |

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
| 2 | configurare: fără `DB_DATABASE`, conexiune refuzată, server prea vechi, PHP sub `php_min`, registru greșit (număr folosit de două ori, dosar care nu e un proprietar, fișier fără forma cerută), argument greșit; o înghețare fără forma cerută, al altui site decât `--site`, un pachet cu înghețare fără `--site`/`SITE_SLUG`; cu `--site`: `SITE_DATE_DOSAR` care nu există, un pachet fără înghețarea din `site-uri/<site>/`, sau fără un pas pe care baza îl așteaptă (`pasi_site`), ori baza așteaptă pașii altui site | repari configurarea, codul sau pachetul site-ului (un pas așteptat se pune la loc în pachet) |
| 3 | alt runner aplică migrări pe aceeași bază, sincronizarea FAN ține tabelele FAN (`lacatFan`), sau `information_schema` tot nu arată un tabel pe care altă conexiune îl schimbă chiar atunci (mai jos, la amprentă) | rulezi din nou mai târziu |
| 4 | baza nu e a unui site ANDAXI (are tabele, dar nu `settings` + `admins` + `pages` și nici `_platforma_migrari`; de exemplu un WordPress). `_verificare`, marcajul copiei de verificare, nu se numără: copia demonstrativă (`tools/verificare/incarca.php --demo`) rulează migrările pe o bază care are doar marcajul ([VERIFICARE.md](VERIFICARE.md), „Încărcarea copiei”); nici amprenta (`Amprenta::ignorat`) nu-l vede | verifici `DB_DATABASE`; nimic n-a fost atins |
| 10 | `--plan`: sunt migrări de aplicat, toate fără blocare | `--aplica` cu site-ul pornit |
| 11 | sunt migrări blocante sau de tip contract (`--plan`), refuzate fără `--permite-blocante` / `--permite-contract` (`--aplica`, cu baza neatinsă) | fereastra de mentenanță |
| 12 | o migrare aplicată pe această bază s-a schimbat în cod, a dispărut din cod, sau nu se potrivește cu `migrari.lock`; o înghețare aplicată s-a schimbat sau lipsește din pachet | o schimbare se face într-o migrare nouă; una retrasă se trece la `retrase`; o corectură a înghețării e `setari-inghetate-2.json` |
| 13 | bugetul de timp s-a terminat; starea e consecventă | rulezi din nou (continuă de unde a rămas) |
| 14 | după aplicare (sau la `--verifica`) lipsește un obiect cerut de `asteptat.json`, ori `asteptat.json` lipsește deși există migrări | citești lista `LIPSĂ` |
| 15 | lipsește un parametru al site-ului (`migrari.json` din dosarul site-ului), sau e încă provizoriu; înghețarea e provizorie, sau îi lipsesc valorile private (`privat/setari-inghetate.json`, ori e al altui fișier); `--permite-provizoriu` în afara copiei de verificare | îl scrii în pachetul site-ului; provizoriu: îl confirmi (pe copia de verificare, `--permite-provizoriu`); privat: copiezi fișierul privat lângă pachet |

## Fișierele

```
database/migrari/
  nucleu/0001_baza_nucleu.php        un dosar pe proprietar: „nucleu” sau cheia unui plugin din config/plugins.php
  erp/0010_baza_erp.php
  site/<site>/<nume>.php             pașii unui singur site (id site/<site>/<nume>), doar cu --site
  migrari.lock                       {"migrari": {id: sha256}, "retrase": {id: motiv}}: ce s-a publicat
  asteptat.json                      amprenta schemei după toate migrările, pe o bază goală
  ultima.php                         ultima migrare, amprenta codului și id-urile migrărilor, pentru garda (WP-05)
site-uri/<site>/setari-inghetate.json   înghețarea setărilor site-ului (pasul site/<site>/setari-inghetate, WP-06);
                                     corecturile de după adoptare: setari-inghetate-2.json, -3 …
site-uri/<site>/migrari.json         parametrii site-ului (Schema::parametruSite)
site-uri/<site>/privat/              valorile private ale înghețării (OD-49), în afara git-ului
src/Support/Migrari/                 Registru, Runner, Schema, Server, Amprenta, Executie, Eroare, ParametruLipsa;
                                     Consola (ce au în comun migreaza.php și install.php), Garda (garda de la fiecare cerere),
                                     Inghetare (fișierul înghețării: forma, valorile private, aplicarea)
scripts/migreaza.php                 linia de comandă
scripts/install.php                  o bază nouă: migrările, seed-ul neutru, setările, pașii de activare, primul administrator
config/activari.php                  pașii de activare ai pluginurilor (src/Support/Activari.php)
storage/logs/migrari.log             câte un rând pe operație executată (nume de obiecte, clasa, durata); al site-ului
                                     (App\Support\Paths: în așezarea din Etapa 2, site/storage/logs/migrari.log)
tools/sonda/amprenta-schema.php      sonda schemei unui site live (WP-10, MIG-08), rulată de proprietar
tools/dev/migrari/din-sonda.php      local: sonda → fixture-ul și extra-<cod>.txt, în tools/dev/migrari/vechi/privat/
tools/dev/migrari/genereaza-fixture.php   local: schema pe care o face codul unui site; asteptat.json, migrari.lock,
                                     ultima.php; verificarea migrărilor de bază față de cod; fixture-urile din vechi/ (mai jos)
tools/dev/migrari/vechi/             fixture-urile testelor: <cod>.sql (schema codului cms, bs, bv, vt, gr), extra-<cod>.txt,
                                     variante.sql + extra-variante.txt (o bază veche, inventată); privat/ = sonda live, în afara git-ului
tools/dev/migrari/excluderi.php      ce nu primește o instalare nouă din schema codului (folosit de genereaza-fixture și de teste)
tools/dev/migrari/ddl-din-cod.txt    lista DDL-ului vechi din cod, permis până la R2 (verifica-migrari.php)
tools/dev/migrari/pe-vechi.php       local: o bază făcută ca înainte de migrări (install.php de atunci + funcțiile ensure*)
tools/dev/test-migrari.php           testele (runner-ul și T1–T11; T1–T11 stau în tools/dev/migrari/test-baza.php)
tools/dev/test-instalare.php         testele instalării, ale gărzii, ale pașilor de activare și ale contorului de instrucțiuni
tools/dev/verifica-migrari.php       verificarea fișierelor de migrare și a DDL-ului din cod (T11, în testul de fum)
tools/inghetare/genereaza-setari.php generatorul înghețărilor (WP-06); lib/ = comparația și cititorul unui cod
tools/inghetare/verifica-inghetare.php   verificarea înghețărilor (în testul de fum și în G-mig)
tools/dev/test-inghetare.php         testele generatorului și ale verificării (pasul în runner: secțiunea I din test-migrari.php)
```

- **id** = `<proprietar>/<NNNN>_<nume>`. **NNNN** e unic în tot dosarul (oricare proprietar) și dă
  ordinea. Numerele sunt rezervate pe pachete de lucru în planul Etapei 1 (§4.3), deci o migrare nouă poate
  avea un număr **mai mic** decât una deja publicată (de exemplu `catalog/0026` din WP-19 după
  `magazin/0032` din WP-R). Pe o bază nouă ordinea e cea a numerelor; pe un site care are deja migrările mai
  mari, runner-ul o aplică după ele. De aceea o migrare nu are voie să depindă de una cu număr mai mare (T1,
  pe o bază goală, ar pica), iar garda nu se bizuie doar pe `ultima_ord` (mai jos, „Garda”).
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
| `parametruSite($cheie)` | codul 15, înainte de orice schimbare | valoarea din `migrari.json` din dosarul site-ului (`SITE_DATE_DOSAR`; mai jos, „Parametrii site-ului”); una din `_provizoriu`: codul 15, afară de `--permite-provizoriu` pe copia de verificare |
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
  conținutul de pornire e în seed-ul neutru al instalării sau în pașii de activare ai pluginurilor (mai jos).
- **Fără valori din rânduri** în mesaje, avertismente sau jurnal.

## Cum rulează `--aplica`

1. Conexiunea, cu setările lui `Database::connection` (utf8mb4, fusul orar al aplicației), și
   `lock_wait_timeout` scurt. Serverul sub MariaDB 10.3 / MySQL 8.0 → codul 2. `sql_mode` rămâne al
   serverului (cel cu care scrie și site-ul) și se scrie în raport și în jurnal.
2. Baza e a unui site ANDAXI (codul 4 altfel), apoi lacătul `platforma_migrari:<MD5(baza)>`
   (serverul e împărțit între site-uri; codul 3 dacă îl ține altcineva).
3. Registrul și `migrari.lock`; o migrare aplicată schimbată sau dispărută → codul 12. Pachetul site-ului care nu se
   potrivește cu rularea (cu `--site`: dosarul lipsă, fără înghețarea din `site-uri/<site>/`, fără un pas pe care baza îl
   așteaptă în `pasi_site`) → codul 2.
4. **Simularea** tuturor migrărilor de aplicat, în ordine, pe un model comun (a doua vede ce creează
   prima): planul, valorile repetate sub un index unic, NOT NULL fără DEFAULT, parametrii site-ului.
   O migrare blocantă (declarată, sau cu o operație clasată „copie”) sau de tip contract fără voie →
   codul 11 **înainte de orice schimbare** (nici tabelele de urmărire nu se creează).
5. Tabelele de urmărire (singurul DDL în afara migrărilor).
6. Fiecare migrare: întâi cele întrerupte, apoi cele noi, după număr; pașii site-ului la urmă (întâi
   înghețarea setărilor, apoi corecturile ei, apoi pașii PHP ai site-ului). Cât timp un pas al site-ului n-a
   terminat, `_platforma_stare.pasi_site` spune asta gărzii (mai jos, „Înghețarea setărilor”).
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
`ultima_rulare`; `pasi_site` = `{"site": "<site>", "pasi": [id-urile pașilor așteptați]}`, doar cât un pas al site-ului
n-a terminat). `ultima_ord` singur nu spune că baza e la zi: o migrare nouă poate avea un număr sub el (numerele
rezervate), deci cine întreabă „e la zi?” (CRM-ul, `actualizeaza.sh`) ia răspunsul gărzii (`/health`, `schema:`).
Prefixul
`_platforma_` e al runner-ului; tabelele lui nu intră în amprentă. Ele călătoresc în copia bazei, deci o bază
restaurată continuă exact de unde era.

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

| După baseline (pachetele de lucru, în numerele lor din planul Etapei 1) | Ce conține |
|---|---|
| `magazin/0032_avertismente_produse` (WP-R, 20 octombrie) | avertismentul pe produs, din Bioscem `0582ce9`: `product_warnings` (titlul, textul și eticheta bifei pe produs) și `order_product_warnings` (ce a confirmat clientul pe comandă), `product_warnings_schema_version` = `1` în setări, dacă nu e deja; **adoptivă**: pe Bioscem tabelele le face codul la prima salvare a unui avertisment, deci migrarea le găsește coloană cu coloană; colația ca la tabelele BT (la cod, a serverului; la migrare `utf8mb4_unicode_ci`) |

O migrare de după baseline e o migrare obișnuită: pe o bază nouă o aplică `--aplica` după baseline, pe un site
vechi la adoptare, odată cu baseline-ul. Testele o știu: T4 și T10 (`tools/dev/migrari/test-baza.php`) cer schema
neschimbată pe o bază făcută de codul de dinainte de migrări, în afară de tabelele noi ale migrărilor de după
baseline, iar `genereaza-fixture.php --verifica` le aplică după baseline pe baza făcută de cod.

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
un index. Din WP-05 `install.php` rulează migrările, deci schema „codului” acestui repo e cea a instalării de
dinainte: `scripts/install.php` și `database/schema.sql` de la commit-ul din capul lui `vechi/cms.sql`
(`fc8a5e1`), plus funcțiile `ensure*` din arborele de lucru (cu garda lor, care pe o bază fără registru le
lasă să lucreze). Așa arată un site vechi pe codul comun, înainte de baseline; `--repo=` ia alt cod, întreg.

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

## Instalarea (`scripts/install.php`)

Un site nou se instalează pe o bază **goală** (`DB_*` și `ADMIN_DEFAULT_PASSWORD` în `.env`):

1. **migrările**, exact ca `migreaza.php --aplica` (aceeași conexiune și aceleași opțiuni, din
   `App\Support\Migrari\Consola`; `SITE_SLUG` din `.env` rulează și pașii acelui site);
2. **seed-ul neutru**: categoriile de blog „Noutăți” (`noutati`) și „Evenimente” (`evenimente`, pe care o
   caută `NextEvent`), doar într-un tabel gol. Nimic din conținutul Bioscem: fără „Info pacienți”/„Info
   medici”, fără lista implicită de newsletter (o face `NewsletterService::defaultListId` la prima abonare);
3. **setările implicite** (`Settings::DEFAULTS`) scrise în bază;
4. **pașii de activare** ai pluginurilor pornite de licența de acum (mai jos);
5. la urmă **primul administrator**, cu rolul în forma de azi (`role` + `roles_json`, cum îl aducea codul
   vechi la prima pagină de admin și cum îl scrie Setări → Administratori).

Pe o bază care **are deja administratori** refuză: „Baza există (are administratori); nu o instalez din nou
și n-am scris nimic”, codul 1. Un site existent își aduce schema la zi cu `migreaza.php --aplica`, iar
setările îi rămân ale lui: scrierea setărilor implicite peste ele, înaintea înghețării (mai jos), ar schimba
ce vede clientul (DB-23 din planul de-Bioscem). Cu un pachet care are înghețare (`SITE_DATE_DOSAR`),
`SITE_SLUG` e obligatoriu (fără el, ca și cu pachetul altui site, un `SITE_DATE_DOSAR` care nu există sau un fișier de
înghețare stricat: codul 1, cu motivul runner-ului pe stderr, baza neatinsă), iar înghețarea rulează la pasul 1, înaintea
setărilor implicite: pe o bază nouă a unui site vechi (de exemplu repetiția de pe verificare) valorile codului
vechi câștigă. Administratorul se scrie ultimul, deci o instalare oprită la
jumătate (bugetul de timp al migrărilor, un proces omorât) se rulează din nou și continuă. Orice problemă
iese cu **1** și mesajul pe stderr (fără parolă, PHP sub `php_min`, baza nu răspunde, o bază străină, o
migrare care n-a mers: raportul runner-ului); `seed.php` la fel (fără bază sau fără `seed.sql`: 1). Până la
WP-05 amândouă ieșeau cu 0 la baza căzută („Erori moștenite” 4 din [DEZVOLTARE.md](DEZVOLTARE.md)).

## Garda: nicio cerere nu mai trimite DDL

`App\Support\Migrari\Garda::stare(PDO)` spune cum stă baza față de migrările din cod
(`database/migrari/ultima.php`: numărul ultimei migrări, amprenta codului și id-urile tuturor migrărilor, scris de
`genereaza-fixture.php --asteptat`), ținut minte pe conexiune:

| Stare | Când |
|---|---|
| `la_zi` | fiecare migrare din cod e aplicată sau detectată pe bază. Pe drumul obișnuit (codul e cel cu care a rulat ultima oară runner-ul: `_platforma_stare.amprenta` = amprenta din `ultima.php`): `ultima_ord` ≥ ultima migrare din cod, cu o singură citire după cheia primară. Când amprenta diferă (cod nou urcat, sau codul întors la o versiune de dinainte), garda citește și migrările terminate din `_platforma_migrari`: fiecare id din `ultima.php` trebuie să fie acolo. O bază înaintea codului (după o revenire la codul de dinainte) e tot la zi: migrările doar adaugă |
| `in_urma` | runner-ul a lucrat pe bază, dar o migrare din cod nu e terminată pe ea (cod nou urcat, `--aplica` nerulat sau oprit; și una cu numărul sub `ultima_ord`, venită după ea), `_platforma_stare` fără `ultima_ord`, sau pașii site-ului (înghețarea) n-au terminat (`pasi_site`) |
| `fara_registru` | baza n-a văzut niciodată runner-ul (fără `_platforma_stare`): un site pe codul vechi, de dinainte de adoptare |
| `eroare` | fără conexiune, conexiunea căzută, fără drept de citire, sau lipsește `ultima.php` (ori are forma de dinainte, fără id-uri) |

Unde se folosește:

- **R1, în capul celor 36 de funcții** `ensure*Schema` / `asigura*Schema*` (cele 16 din cele două
  controllere și cele 20 din `src/Support`): `if (\App\Support\Migrari\Garda::stare($db) === 'la_zi') { return; }`.
  Pe o bază la zi nu mai rulează nimic din ele: nici DDL-ul (131 de instrucțiuni pe dashboard, 175 pe lista de
  comenzi înainte), nici scrierile „de reparat” de la fiecare cerere (rolurile adminilor, galeria veche,
  recenziile din tabelul vechi, legăturile de blog, numele cupoanelor: le fac o singură dată migrările de date
  0020–0024), nici conținutul pe care îl puneau la loc. Pe `fara_registru`, `in_urma`
  sau `eroare` lucrează ca înainte, ca un site încă neadoptat să meargă exact ca azi. `scripts/fan-tracking-sync.php`
  nu mai trimite cele trei ALTER-uri la fiecare rulare. Codul care scrie azi rânduri le scrie complete
  (recenzia cu sursa, adminul cu `roles_json`, cuponul cu nume); un rând scris de codul vechi după ce
  migrările de date au rulat ar cere o migrare de date nouă (pe site-urile vechi codul vechi nu mai rulează
  după adoptare).
- **Ce ștergea adminul și readuceau funcțiile** (schimbări pentru client, în `site-uri/<site>/ce-se-schimba.md`):
  pe o bază la zi o ștergere rămâne ștearsă. Pe codul Bioscem, la următoarea pagină din admin (sau de produs):
  pagina `/acorduri-gdpr` ștearsă revenea (acum: pasul de activare, mai jos, o face doar dacă lipsește cu
  totul); toate categoriile de blog șterse reveneau ca cele patru ale Bioscem (Noutăți, Evenimente, Info
  pacienți, Info medici; `ensureOptionalSchema` le punea într-un tabel gol); o recenzie copiată din tabelul
  vechi `reviews` și ștearsă din admin se copia din nou (acum: o dată, `recenzii/0023`). Altele nu: galeria se
  reface din `gallery_json` doar într-un câmp gol, iar adminul scrie o galerie golită `[]`; rolurile adminilor,
  numele cupoanelor, sursa recenziilor și legăturile de blog le scrie codul de azi deja complete; insignele,
  câmpurile extra, șabloanele, valorile câmpurilor și limitele cupoanelor se mai puteau reface doar din coloane
  vechi pe care schema codului Bioscem nu le are (`label_popular`, `input_type`, `html_template`, `value_text`,
  `min_items` …; doar varianta inventată din `variante.sql`). Dacă sonda (WP-10) arată una dintre ele pe un site
  live, rândul ei intră în „ce se schimbă” al site-ului. Dovada (T10): pe o bază la zi, cu categoriile șterse și
  o recenzie copiată și ștearsă, funcțiile codului comun nu scriu nimic, iar ale codului Bioscem le readuc.
- **`/health`**: „ok” și, pe al doilea rând, `schema: <stare>`; cu `in_urma` răspunde **503** („indisponibil”),
  ca verificarea de după un deploy (`actualizeaza.sh`, Etapa 2) să întoarcă codul la loc. La fel pe copia de
  verificare, cu o copie încărcată (fără copie, poarta răspunde „ok” fără bază; [VERIFICARE.md](VERIFICARE.md)).
- **`/_andaxi/ping`** (WP-07, `App\Support\Ping`; [DEZVOLTARE.md](DEZVOLTARE.md), „Unde scrie codul”): JSON cu
  `"schema": "<stare>"` (aceeași gardă, pe conexiunea lui, limitată la 2 secunde) și versiunea codului; răspunde
  200 și cu `in_urma` (site-ul merge), 503 doar fără bază. E pentru monitorul CRM-ului; cine decide un deploy
  citește `schema` sau `/health`.
- **Bannerul din admin**: administratorul general vede un banner roșu, cu ce e de făcut, când baza nu e
  `la_zi` (`data-schema="<stare>"`). Ceilalți administratori nu-l văd.

Corpurile funcțiilor se șterg după cele patru adoptări (R2, MIG-11), odată cu `tableHasColumn`,
`BtIpayPayments::areTabele`, căutările în tabelul vechi `reviews` și `database/schema.sql` (azi doar
documentație: nu-l mai rulează nimeni). Tot atunci se șterge, din `Auth::attempt`, comparația în clar a unei parole de
admin salvate necriptat (WP-12b, `App\Support\ParolaAdmin`), după ce numărătoarea WP-10 (`admini.parola_necunoscuta`)
arată 0 pe toate cele patru site-uri.

**Contorul de instrucțiuni (doar dezvoltare).** Cu `CMS_DEV_NUMARA_SQL=1` în mediul serverului de dezvoltare,
`Database::connection` întoarce un `App\Support\ContorSql`: fiecare cerere scrie un rând JSON în
`$CMS_DEV_DIR/numara-sql.jsonl` (metoda, calea, câte instrucțiuni, câte de schemă și începutul lor, câte
citiri din `information_schema`). Pe o bază făcută de `install.php`: 0 instrucțiuni de schemă pe dashboard
(10 instrucțiuni în total), lista de comenzi (19), pagina de produs (18) și trimiterea unei comenzi (26); pe
o bază făcută ca înainte, dashboard-ul trimite 110 din 144, iar după baseline 0 (`tools/dev/test-instalare.php`,
E). Testul de fum numără toate cererile lui (pe fiecare pachet): niciuna nu are voie să trimită DDL. Pe
site-uri variabila nu există.

## Pașii de activare ai pluginurilor

Schema e aceeași pe orice site (decizia OD-02), dar unele pluginuri au nevoie de conținut când pornesc. Acela
nu stă în migrări și nu se mai pune la fiecare cerere: e un **pas de activare** (`config/activari.php`, rulat
de `App\Support\Activari::ruleaza`), o singură dată pe bază, când pluginul devine „pornit”:

| Plugin | Pas | Ce face |
|---|---|---|
| `acorduri_gdpr` | `pagina_acorduri_gdpr` | pagina `/acorduri-gdpr` cu `{{gdpr_agreements_form}}`, **doar dacă nu există** (nici ștearsă): o pagină ștearsă de admin rămâne ștearsă (codul vechi o readucea la fiecare pagină din admin cât pluginul era vizibil) |
| `erp` | `prag_trimitere_erp` | `erp_trimite_din` = acum, **doar dacă cheia lipsește cu totul** din `settings`: o valoare goală (înghețarea Bioscem: fără prag) rămâne. Pornit pe un site care nu l-a avut, ERP-ul nu mai primește tot istoricul „pending” (G-M8). Îl respectă, din WP-12 (E1), `retryPending`, contoarele din Setări → ERP ANDAXI și Admin → Comenzi; cât timp cheia lipsește (pasul n-a rulat încă), nicio comandă nu pleacă automat ([PLUGINURI.md](PLUGINURI.md), `erp`) |

Ce s-a făcut pe o bază stă chiar în ea, în setarea `activari_pluginuri` (pluginul → momentul): un plugin
„pornit” care nu e acolo își rulează pașii și intră acolo (la o eroare rămâne pe dinafară și se încearcă din
nou); unul „oprit” iese, deci o repornire rulează pașii din nou (sunt idempotenți); „se oprește” nu schimbă
nimic. Fiecare pas rulat scrie un rând în jurnalul de activitate („Plugin pornit: pas de activare”, fără
administrator).

**Doar pe o bază la zi cu migrările** (garda: `la_zi`). Pe una `fara_registru`, `in_urma` sau `eroare` pașii
nu scriu nimic, nici înregistrarea, și așteaptă prima rulare de după `scripts/migreaza.php --aplica`: o copie
de dinainte de adoptare restaurată sau încărcată pe verificare fără runner, codul comun urcat înaintea lui
`--aplica`, o adoptare oprită la jumătate (bugetul de timp), inclusiv una oprită între ultima migrare și
înghețarea setărilor (`pasi_site`, mai jos). Altfel pasul ERP-ului ar scrie momentul de atunci în
`erp_trimite_din` înaintea înghețării setărilor site-ului, care scrie doar o cheie lipsă și nu l-ar mai putea
face gol: comenzile ERP încă netrimise de dinainte (Bioscem, Biovitality) n-ar mai pleca
după WP-12 (E1). Pe o astfel de bază funcțiile `ensure*` lucrează ca înainte și readuc ele pagina
`/acorduri-gdpr`, deci nu lipsește nimic. Starea gărzii se citește doar când e ceva de făcut. Pașii rulează:

- la **fiecare cerere** (`public/index.php`, după stările pluginurilor): setarea se citește oricum
  (`Settings::all`), deci fără nimic de făcut nu costă nicio interogare; o eroare nu oprește pagina;
- la **instalare** (`install.php`, pentru pluginurile licenței de atunci);
- în **cronul ERP** (`scripts/erp-sync.php`), înaintea oricărei trimiteri: o licență care pornește ERP-ul fără
  nicio cerere web după scrie întâi pragul;
- în **`tools/dev/licenta.php`** (dezvoltare), după fiecare licență pusă, ca la o licență nouă venită de la CRM
  (Etapa 2: sincronizarea licenței îi cheamă la fel); `licenta.php activari` îi rulează singuri (de exemplu
  după baseline-ul din `instaleaza.sh --pe-vechi`), iar pe o bază care nu e la zi spune că așteaptă.

**Nu** în `migreaza.php` (planul îl pomenea acolo): runner-ul face doar schema, aceeași oricare ar fi licența,
iar rezultatul lui nu are voie să depindă de pluginuri (testele T1–T11 îl cer). De ce în bază și nu în
`stare-aplicata.json` (dosarul licenței, unde `Plugins` notează schimbările de stări): o bază nouă, una
restaurată dintr-o copie mai veche sau una schimbată sub același dosar (dezvoltarea) își primesc pașii la
prima rulare, iar o cerere care vede schimbarea fără bază (baza căzută) nu-i pierde.

## Înghețarea setărilor (WP-06, MIG-09, DB-02)

O setare care lipsește din tabelul `settings` al unui site ia valoarea din cod: `Settings::DEFAULTS` sau rezerva
scrisă lângă citire (`$settings['x'] ?? 'y'`). Codul comun nu are aceleași valori ca codul vechi al fiecărui site
(fereastra de bun venit pornită, destinatarii formularului de contact ai Bioscem, numele magazinului …), deci la
adoptare un site ar vedea altceva exact unde n-a scris niciodată o valoare. **Înghețarea** scrie, o singură dată, la
adoptare, valorile pe care codul vechi le folosea efectiv, doar acolo unde codul comun ar folosi altceva și doar unde
baza n-are deja rândul: o setare pe care site-ul o are e a clientului și nu se atinge.

### Fișierul: `site-uri/<site>/setari-inghetate.json`

Pe server stă în pachetul site-ului (`SITE_DATE_DOSAR`). Îl scrie generatorul (mai jos), determinist; îl citește
`App\Support\Migrari\Inghetare` (runner-ul, generatorul și verificarea folosesc aceeași clasă). Formatul 2:

```json
{
    "_despre": "…",
    "format": 2,
    "site": "biovitality",
    "sursa": "Mutare-Site-Biovitality@cc231e4",
    "provizoriu": "de ce sursa nu e încă cea confirmată (lipsește după confirmare)",
    "amprenta_nucleu": "<sha256 al valorilor codului comun la generare>",
    "setari": {
        "store_name": {"valoare": "Biovitality Shop", "mod": "lipsa_sau_gol", "din": "cod", "sursa": "…", "nota": "…"},
        "contact_form_recipients": {"valoare": "adresa1@exemplu.ro", "mod": "lipsa_sau_gol", "din": "implicit", "privat": true, "sursa": "Settings::DEFAULTS"},
        "welcome_popup_enabled": {"valoare": "0", "mod": "lipsa", "din": "manual", "sursa": "…", "vechi": "lipsește", "nucleu": "implicit \"1\""}
    },
    "fara_inghetare": {
        "bt_ipay_enabled": "de ce nu se îngheață (forma scurtă)",
        "contact_email": {"motiv": "…", "vechi": "…", "nucleu": "…"}
    }
}
```

| Câmp | Ce înseamnă |
|---|---|
| `mod` | `lipsa` (implicit; și forma scurtă `"cheie": "valoare"`): se scrie doar dacă rândul lipsește (`INSERT IGNORE`). `lipsa_sau_gol`: și peste o valoare goală (`TRIM(value) = ''`, doar spații, ca `TRIM` al serverului), unde codul vechi cădea tot pe valoarea lui când găsea gol |
| `din` | `implicit` (din `Settings::DEFAULTS` al codului vechi) și `cod` (o singură rezervă în codul vechi) le scrie generatorul; `manual` (valoarea scrisă de mână) și `extras` (scoasă din codul vechi prin `extrage`) sunt decizii |
| `privat` | valoarea adevărată e o dată a clientului (o adresă de email, un telefon, un nume): în git e un înlocuitor inventat (`adresa1@exemplu.ro`, `0700 000 001`, `Valoare demo`), iar valoarea adevărată stă în `privat/setari-inghetate.json` (mai jos; decizia OD-49) |
| `sursa` | de unde vine valoarea (pentru om); la o decizie e obligatorie |
| `vechi`, `nucleu` | ce arătau cele două coduri când s-a luat decizia (mai jos, „Deciziile”) |
| `nota` | o explicație, păstrată de la o generare la alta |
| `fara_inghetare` | cheile la care codurile diferă, dar nu se îngheață nimic, fiecare cu motivul (o funcție nouă oprită oricum, un text care nu se arată, aceeași comportare) |
| `provizoriu` | sursa nu e încă cea confirmată ca live (raportul „live față de repo” din Etapa 0): pasul se oprește cu codul **15**, afară de `--permite-provizoriu` pe copia de verificare (`SITE_COPIE_VERIFICARE=1`; repetiția adoptării). Pe orice alt site opțiunea nu are efect: tot 15, cu motivul |
| `inchis` | fișierul pleacă pe site-ul live (se pune înaintea adoptării, nu după: ar schimba sha-ul); generatorul nu-l mai rescrie, verificarea nu-l mai cere reprodus |

Valorile private, `privat/setari-inghetate.json` lângă fișier (ignorat de git: `/site-uri/*/privat/` în
`.gitignore`), au forma `{"_despre", "site", "sursa", "pentru": "<sha256 al fișierului de mai sus>", "setari":
{cheie: valoarea adevărată}}`. Le scrie generatorul (refuză o cale din repo pe care git n-o ignoră); pe server se
copiază de mână lângă pachet, ca `.env` (nu vin din git). Fără ele, sau cu `pentru` al altui fișier (generat din nou
de atunci), pasul se oprește cu codul **15** înainte de orice scriere: nu se scrie niciodată un înlocuitor pe un site.

### Pasul `site/<site>/setari-inghetate`

Cu `--site=<site>` (sau `SITE_SLUG`), registrul face din fișierul pachetului un pas al site-ului, ca unul din
`database/migrari/site/<site>/`: id `site/<site>/setari-inghetate`, tip `site`, sha256 al fișierului (cu sfârșituri
de rând LF; fișierul privat nu intră, el poate lipsi după adoptare). Rulează înaintea pașilor PHP ai site-ului, după
toate migrările (tabelul `settings` îl face `nucleu/0001`). Pentru fiecare cheie, `Schema::setare` (doar în pașii
site-ului) se uită întâi în `settings`: planul arată `se scrie: „…”`, `se completează (e goală): „…”` sau `există`
(o valoare privată apare doar ca „valoare privată”), iar aplicarea face exact atât, cu un rând de jurnal pe cheie.
Rândul din `_platforma_migrari` e `aplicata` (a scris ceva) sau `detectata` (totul exista).

- **Aplicat o dată, neschimbat după:** un fișier cu alt sha decât cel aplicat, sau care lipsește din pachet, oprește
  runner-ul cu codul **12**. O corectură de după adoptare e un fișier nou, `setari-inghetate-2.json` (apoi `-3` …),
  scris de mână, în aceeași formă: pasul lui, `site/<site>/setari-inghetate-2`, rulează după cel de bază.
- **Pachetul și site-ul se potrivesc** (altfel codul **2**, înainte de orice schimbare; altfel adoptarea ar trece fără
  înghețare): fișierul altui site (`"site"` altul decât `--site`); un pachet cu înghețare, dar fără `--site`/`SITE_SLUG`;
  cu `--site`, un `SITE_DATE_DOSAR` care nu există (o cale greșită, un pachet mutat) sau un pachet fără înghețare, deși
  codul are una pentru acel site în `site-uri/<site>/setari-inghetate.json` (alt dosar, un pachet incomplet). Un pas PHP
  cu numele `setari-inghetate*.php` = codul 2 (numele e al pasului din pachet).
- **Garda așteaptă pașii site-ului:** cât timp un pas al site-ului nu e terminat, rularea scrie, înaintea primei
  migrări, `_platforma_stare.pasi_site` = `{"site": "<site>", "pasi": [id-urile pașilor așteptați]}` și o șterge abia
  când **fiecare pas din listă** e `aplicata`/`detectata` (nu doar când rularea de acum nu mai vede niciunul). Garda citește
  rândul în aceeași interogare cu `ultima_ord` și spune `in_urma` cât există. O adoptare oprită de bugetul de timp
  între ultima migrare și înghețare rămâne deci `in_urma`: pașii de activare (pragul ERP) nu scriu nimic înaintea
  înghețării, iar `/health` răspunde 503 până la `--aplica` din nou. `--stare` arată rândul. O rulare cu `--site` care nu
  poate face pașii din listă (baza așteaptă pașii altui site, sau pachetul nu mai are un pas așteptat: un dosar greșit, un
  fișier scos) e refuzată cu codul 2, înainte de orice schimbare, iar cheia rămâne: **un pas așteptat nu se scoate din
  pachet** (o corectură care nu mai trebuie se aplică și se îndreaptă cu una nouă). O rulare fără `--site` n-o atinge și o
  spune într-un avertisment.
- **Instalarea** (`install.php`, cu `SITE_SLUG`) rulează pasul ca orice migrare, înaintea setărilor implicite.

### Generatorul (`tools/inghetare/genereaza-setari.php`)

```sh
tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate               # cele patru site-uri, din clonele lor
tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=bioscem
tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=vertij --repo=<clonă>@<commit>
tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=biovitality --provizoriu="<motiv>"   # sau --confirmat
tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate --verifica    # nu scrie: e la zi?
```

Codul vechi e clona site-ului (`CMS_DEV_REPO_BS`, `_BV`, `_VT`, `_GR` din `tools/dev/comun.sh`) la „ultimul commit
revizuit” din [de-portat.md](de-portat.md), copiat cu `git archive` în `$CMS_DEV_DIR/inghetare/` (clona nu se
atinge). Fiecare cod (cel vechi și acesta) se citește într-un proces PHP separat (`lib/citeste-cod.php`):
`Settings::DEFAULTS` și, cu tokenizer-ul, fiecare rezervă `$settings['x'] ?? <text>` (și `$xxxSettings[…]`,
`$this->settings[…]`) din `src/`, `views/`, `scripts/`, `config/`, `public/` și `bootstrap.php`. Valoarea efectivă a
unei chei într-un cod e: implicitul, altfel rezerva (una singură), altfel „ambiguu” (mai multe rezerve diferite sau o
expresie), altfel lipsă; lipsa și textul gol sunt același lucru. Unde cele două coduri diferă:

- **codul vechi are o valoare sigură** (implicit sau o singură rezervă): intrarea o scrie generatorul, cu valoarea lui
  (`din`: `implicit`/`cod`); modul și nota se păstrează de la o generare la alta;
- **altfel** (codul vechi nu avea setarea, sau o citea cu rezerve diferite): o **decizie**, scrisă de mână în fișier:
  o setare `din: manual` (cu `valoare`), `din: extras` (cu `extrage`: `{"fisier": "<cale în codul vechi>", "tipar":
  "<regex cu un grup>"}` sau `{"apel": "App\\…::metoda", "argumente": […], "cale": "…", "json": true}`, rulat în
  procesul codului vechi), sau o intrare în `fara_inghetare` cu motivul. O decizie lipsă = eroare, fișierul nu se scrie.

**Deciziile** sunt legate de ce arătau atunci cele două coduri (`vechi`, `nucleu`, scrise de generator): o valoare
nouă a codului comun sau alt commit al codului vechi face din decizie o eroare („decizie de refăcut”, cu valorile de
atunci și de acum). Se confirmă ștergând `vechi`/`nucleu` din intrare (generatorul le scrie din nou) sau se schimbă.

**Datele private (OD-49):** o valoare cu o adresă de email care nu e `@exemplu.ro` sau cu un număr de telefon, ori o
intrare marcată `privat`, intră în git cu un înlocuitor, iar valoarea adevărată în `privat/`. O valoare scrisă de mână
nu poate fi privată (ar sta în git): vine din codul vechi, cu `extras`. Nicio valoare privată nu apare în ieșirea
uneltelor.

**`amprenta_nucleu`** = sha256 al valorilor codului comun (implicitele și rezervele) la generare. **Orice schimbare a
lui `Settings::DEFAULTS` sau a unei rezerve din codul comun cere `genereaza-setari.php --toate`** în același commit
(cu deciziile noi, dacă le cere): verificarea, în testul de fum și în G-mig, pică altfel, chiar fără clonele codurilor
vechi. Un fișier `inchis` nu se mai generează: `--toate` îl sare (`SĂRIT`), `--site=<el>` e o eroare.

Azi: **Bioscem** din `4254ca4` (implicitele codului Bioscem sunt exact ale codului comun: doar `erp_trimite_din` = gol,
decizia G-M8, ca pasul de activare al ERP-ului să n-o atingă); **Biovitality**, **vertij** și **grafoanaytis** din
capetele ramurilor din de-portat.md, `provizoriu` până la raportul „live față de repo” din Etapa 0 (apoi `--confirmat`,
eventual cu `--repo=` pe commit-ul confirmat, trecut întâi în de-portat.md).

### Verificarea (`tools/inghetare/verifica-inghetare.php`)

În testul de fum (`smoke.sh`, la început) și în poarta G-mig; `--fara-clone` sare doar reproducerea, spus în rezumat
(fără ea, o clonă lipsă e un eșec). Pentru fiecare site: (1) fișierul și corecturile lui se citesc ca în runner și
sunt ale acelui site; (2) nicio dată privată în git (nicio adresă în afara `@exemplu.ro`, niciun telefon, fiecare
valoare `privat` e un înlocuitor); (3) `sursa` = „ultimul commit revizuit” din de-portat.md; (4) `amprenta_nucleu` =
valorile de acum ale codului comun; (5) generatorul dă acum exact același fișier (și același fișier privat, dacă există
local), deci nicio decizie nu lipsește și niciuna nu e veche; (6) `migrari.json`, mai jos. Un fișier `inchis` nu face
(4) și (5).

### Parametrii site-ului (`site-uri/<site>/migrari.json`)

Răspunsurile pe care le știe doar site-ul, citite de o migrare cu `Schema::parametruSite('<cheie>')`: lipsa lor oprește
runner-ul cu codul **15** în simulare, înainte de orice schimbare. Cheile cu `_` sunt note. Un parametru încă
neconfirmat stă și în `_provizoriu` (cheie → motiv): codul 15, afară de `--permite-provizoriu` pe copia de verificare
(`SITE_COPIE_VERIFICARE=1`). Verificarea cere JSON,
doar chei citite de migrările din `database/migrari/`, valori permise și chei din `_provizoriu` care există.

| Parametru | Migrarea | Valori | Site-uri |
|---|---|---|---|
| `link_plata.procesator_linkuri_existente` | `link_plata/0009`: procesatorul linkurilor de plată vechi, pus în același ALTER care adaugă coloana | `euplatesc`, `btipay`, `stripe` | Biovitality `stripe` (codul BV face linkuri doar cu Stripe); grafoanaytis `stripe`, provizoriu până la OD-11 (numărătoarea G2); Bioscem și vertij nu-l cer (Bioscem are coloana, VT n-are tabelul) |

### Pe server, la adoptare

Întâi, pe fiecare site live, **extensia sodium** (văzut pe site-ul de verificare, 21 oct 2026). Licența
(`Licenta::verifica`, semnătura Ed25519) are nevoie de sodium în PHP-ul site-ului (web) și în cel al liniei de comandă.
Pe Hostinger fiecare website are lista lui de extensii, iar codul vechi n-a avut nevoie de sodium, deci pe site-urile
live probabil lipsește. Fără el, un site adoptat abia acum (fără `stare-aplicata.json`) merge doar cu nucleul: magazinul
și celelalte pluginuri oprite, cronurile pluginurilor ies cu codul 2 ([PLUGINURI.md](PLUGINURI.md), regula 4).
- **Web**: hPanel → acel website → PHP Configuration → PHP extensions → bifezi sodium, înainte de urcarea codului comun
  (codului vechi nu-i schimbă nimic). Repetiția de pe verificare nu dovedește asta: site-ul de verificare are bifa lui.
  Bifa se vede în hPanel, la site-ul live însuși; o verificare automată pe site-ul live, înainte de comutare, intră în
  deploy-ul din Etapa 2 (planul Etapei 1, §0c, predarea (c)). După urcare, în admin nu trebuie să apară „Licența lipsește”.
- **Linia de comandă**: `/opt/alt/php83/usr/bin/php` nu încarcă sodium singur (bifa din hPanel e doar pentru web), deci
  comenzile se dau cu `PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so"` și `$PHP scripts/migreaza.php …`,
  `$PHP scripts/diagnostic.php --licenta`, iar liniile de cron din hPanel cu același `-d extension=sodium.so`.
  `diagnostic.php --licenta` arată doar sodium-ul liniei de comandă, nu pe al site-ului.

1. Pachetul site-ului (cu `setari-inghetate.json` și `migrari.json`) în `SITE_DATE_DOSAR`, iar `privat/` lângă el,
   copiat de mână din dezvoltare (deploy-ul din Etapa 2 nu are voie să-l șteargă); `SITE_SLUG` în `.env`. Fișierele
   publice ale pachetului în docroot: `tema/public/` în `public/tema/`, `public/` peste `public/`
   ([PACHETE.md](PACHETE.md), „Pe server”). Apoi `$PHP scripts/diagnostic.php --pachet`: ultimul rând trebuie să fie
   `rezultat     : ok` (manifestul `pachet.json`, site-ul = `LICENTA_SITE` = `SITE_SLUG`, fiecare sha256, tema, copiile publice).
2. Repetiția pe verificare, pe o copie a bazei live: `php scripts/migreaza.php --plan --permite-provizoriu`, apoi
   `--aplica --permite-provizoriu`; planul arată fiecare cheie scrisă. Copia o încarcă încărcarea din WP-08
   ([VERIFICARE.md](VERIFICARE.md)). `configureaza.php` scrie `SITE_COPIE_VERIFICARE=1` (fără el `--permite-provizoriu`
   nu are efect) și pachetul Bioscem: `SITE_SLUG=bioscem`, `SITE_DATE_DOSAR=<dosarul codului>/site-uri/bioscem`.
   Pentru Bioscem `--permite-provizoriu` nu schimbă nimic (înghețarea lui nu e provizorie). **Repetiția pentru
   Biovitality, vertij sau grafoanaytis** cere în `.env`-ul copiei, înainte de `--plan`, ambele schimbate:
   `SITE_SLUG=<site>` și `SITE_DATE_DOSAR=<dosarul codului>/site-uri/<site>`, plus `site-uri/<site>/privat/` copiat
   din dezvoltare lângă pachet. Cu doar una dintre ele runner-ul refuză (`--site` altul decât `SITE_SLUG`, sau pachetul
   altui site: codul 2), deci nu se poate repeta din greșeală înghețarea Bioscem pe copia altui site. După repetiție
   se pun la loc valorile Bioscem.
3. Sursa confirmată (`--confirmat`), fișierul `inchis`, commit; pe site-ul live `--plan`, apoi `--aplica` (fără
   `--permite-provizoriu`). De acum fișierul nu se mai schimbă (codul 12): o corectură e `setari-inghetate-2.json`.
4. Pe site-ul live, `--aplica` rulează **imediat după urcarea codului comun** și se reia până termină (codul 13,
   bugetul de timp: rulezi din nou). Până la pasul site-ului (înghețarea setărilor) cheia `erp_trimite_din` lipsește,
   iar fără ea ERP-ul nu primește nimic automat (WP-12 E1; [PLUGINURI.md](PLUGINURI.md), `erp`): comenzile noi pleacă
   la plasare, ca înainte, dar cronul `erp-sync.php` și „Retrimite” nu reîncearcă nicio trimitere eșuată (cronul scrie
   „Pragul trimiterii (erp_trimite_din) lipsește”, Setări → ERP ANDAXI arată nota „nu e încă scris”). După `--aplica`
   cheia e scrisă (gol la Bioscem) și cele rămase pleacă la rularea următoare a cronului.
5. **Adresa vizitatorului** (WP-12b; se pregătește înaintea urcării codului de la pasul 4): codul comun crede
   `X-Forwarded-For` doar de la un proxy din `IP_PROXY_DE_INCREDERE` (`.env`, gol implicit), ca limitele checkout-ului
   și ale înregistrării să nu poată fi ocolite. Dacă site-ul are un CDN sau un proxy în față (CDN-ul Hostinger, Cloudflare), rețelele lui
   intră în `IP_PROXY_DE_INCREDERE`; altfel toți vizitatorii ar împărți aceeași limită
   ([VERIFICARE.md](VERIFICARE.md), „Adresa vizitatorului”).

Comparația calculelor de pe copie (`tools/verificare/compara-calcule.php`, WP-11; [VERIFICARE.md](VERIFICARE.md),
„Comparația calculelor”) cere baza la zi cu codul comun: dacă garda spune `in_urma` (cod nou urcat după încărcare),
rulează ea întâi `scripts/migreaza.php --aplica --site=<profil>` (doar pe copie, cu toate gărzile ei) și după el cere
`la_zi`; altă stare, sau local (`--local`), e refuz. Codul vechi nu are migrări, deci vede aceeași bază.

Erorile PHP ale comenzilor (`migreaza.php`, `install.php`, cronurile) apar în terminal, pe stderr, ca până acum: o
eroare fatală din afara raportului runner-ului (memoria terminată, de exemplu) se vede acolo, cu codul de ieșire 255.
În așezarea de azi linia de comandă nu scrie în `storage/logs/php.log` (acolo scriu doar paginile, când serverul web
nu are alt jurnal); în Etapa 2 (`bin/platforma --site=`) le scrie și acolo ([DEZVOLTARE.md](DEZVOLTARE.md), „Unde
scrie codul”, jurnalul erorilor PHP).

## O migrare nouă, pas cu pas

1. Numărul (NNNN) e cel rezervat pentru pachetul de lucru în planul Etapei 1 (§4.3), chiar dacă e sub ultimul
   publicat (mai sus, „Fișierele”: nu are voie să depindă de o migrare cu număr mai mare); dosarul e proprietarul:
   `nucleu` sau cheia pluginului din `config/plugins.php`, care trebuie să aibă tabelul sau coloana în
   `tabele` (verificarea fișierelor pică altfel).
2. Fișierul, cu exact cele patru chei (`descriere`, `tip`, `blocanta`, `sus`) și doar metodele din `Schema`
   (mai sus): fără SQL de schemă scris de mână, fără `AFTER`, fără colație, nicio ștergere în afara unei
   migrări „contract”, nicio valoare din rânduri în mesaje.
3. `tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat`: `asteptat.json`, `migrari.lock`,
   `ultima.php` (garda îl citește, cu id-ul noii migrări; o migrare publicată nu se mai schimbă, o corectură e o
   migrare nouă).
4. O migrare de date își trece tabelele în `$tabeleDate` din `tools/dev/migrari/test-baza.php` (altfel T4 și
   T10 nu știu ce are voie să schimbe) și are un caz de rânduri inventate; o coloană nouă pe care o citește
   codul vechi al unui site se face și acolo, adoptiv (detectată, nu creată a doua oară).
5. Porțile: `tools/dev/porti.sh --wp=<WP>` (G-mig și verificarea fișierelor; G-cmp arată orice schimbare față
   de Bioscem). În cod nu se adaugă DDL nicăieri: `verifica-migrari.php` pică pe orice text DDL sau definiție
   de coloană nouă din codul de pe site-uri.

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
iar sărit mereu, codul 3; garda cu o migrare nouă numerotată sub una deja aplicată (`in_urma` până o aplică
runner-ul, apoi `la_zi`, tot `la_zi` cu codul de dinainte, al cărui runner iese cu 12); plus sesiunile din linia de
comandă.

**Migrările adevărate** (M, T1–T11 din migrari.md §4; WP-04; în `tools/dev/migrari/test-baza.php`), pe
MariaDB și pe MySQL 8:

| Test | Ce face | Trece când |
|---|---|---|
| T1 | o bază goală → `--plan`, `--aplica`; plus `genereaza-fixture.php --verifica` (G-baza, mai sus) | planul 10 fără avertismente, nimic scris; aplicarea 0 (grupul B „aplicata”, C „detectata”), fără avertismente; amprenta = `asteptat.json`; `_platforma_stare` la zi; G-baza trece |
| T2 | fiecare `vechi/<cod>.sql` (cms și bs cu versiunile de schemă pe care le scrie codul Bioscem) → `--plan`, `--aplica`, `--verifica`; plus `--verifica-fixture-uri`, o dată cu clonele de la locul lor și o dată cu `CMS_DEV_REPO_*` spre legături cu alt nume (sursa scrisă în fixture-uri e numele repo-ului, nu al dosarului clonei) | grupul B are exact numerele din migrari.md §2.2 (cms, bs: nimic; bv, gr: 3 tabele, 24 de coloane, valoarea implicită a procesatorului; vt: 8 tabele, 57 de coloane, un index), nicio copiere de tabel; aplicarea face exact ce arăta planul; nimic șters sau schimbat din ce exista, adăugat exact ce lipsea; în plus exact `extra-<cod>.txt`; avertismentele = diferențele de definiție de dinainte; bv și gr fără parametrul procesatorului: 15, baza neatinsă |
| T2, baza veche | `cms.sql` + `variante.sql`, o dată prin funcțiile `ensure*` ale codului (o trecere, ca o cerere), o dată prin migrări | datele (roluri, galerie, insigne, câmpuri extra, șabloane, valori, cupoane, recenzii, legături de blog, `row_key`-ul străzilor, versiunile de schemă) ies la fel, fiecare citire schimbată; procesatorul linkurilor = parametrul site-ului; în plus exact `extra-cms` + `extra-variante`; scos doar `uniq_fan_streets`; copiile sincronizării FAN neatinse |
| T2-live | schema live (sonda, mai sus) sau sonda simulată | ca T2, fără numerele fixe ale planului |
| T3 | a doua aplicare, după T1 și după fiecare T2 | nicio migrare rulată, aceleași rânduri (CHECKSUM TABLE), aceeași amprentă; `--plan` 0 |
| T4 | migrările până la penultima (și până la finalul grupului B), `seed.php` și `tools/dev/date-demo.php` (prin `tools/dev/php.sh`, pe baza testului, cu licența „toate” a testului, ca în T10), apoi restul | amprenta = `asteptat.json`; tabelele schimbate ⊆ tabelele migrărilor rămase (o migrare de date nouă își trece tabelele în `$tabeleDate` din test) |
| T5 | oprit (`MIGRARI_TEST_OPRESTE_DUPA`) după fiecare operație a instalării noi și a bazei vechi, și chiar în/după commit-ul unor bucăți și instrucțiuni de date (`MIGRARI_TEST_OPRESTE_IN`), apoi reluat o singură dată (câte 4 în paralel, pe baze separate) | reluarea iese cu 0 (fără a doua încercare); aceeași amprentă, aceleași date, nimic „in_curs”, fiecare operație numărată o dată |
| T6 | funcțiile `ensure*` ale codului după primele 4 migrări, și în același timp cu `--aplica` (1, 2, 3 procese; `--aplica` cu pauzele de pe site-uri la 1205/1213, pe MySQL 8 un ALTER al codului și unul al migrării dau uneori deadlock de câteva ori la rând) | `--aplica` 0 (cursele acceptate: aici s-a găsit 1684); fiecare trecere a funcțiilor iese cu 0 și fără nicio excepție (procesul-copil le prinde în „erori”; pe un site ar fi o cerere a codului vechi căzută); baza completă; în plus doar excluderile; avertismente doar colația tabelelor BT și cheile străine ale tabelelor făcute întâi de cod |
| T7 | pe baza instalată, o copie a migrărilor cu una aplicată schimbată, apoi și cu lacătul rescris, apoi ștearsă, apoi retrasă | 12, 12, 12, apoi 0; baza și starea neatinse |
| T8 | o migrare de probă cu `MODIFY COLUMN`, declarată neblocantă | `--plan` și `--aplica` 11, nimic aplicat; cu `--permite-blocante`, aplicată |
| T9 | amprentele din T1, T2, baza veche, T4 | aceleași pe ambele servere |
| T10 | cu licența „toate” dintr-un dosar al testului (`CMS_DEV_LICENTA_DOSAR`, ca procesul-copil al funcțiilor: oricare ar fi licența locală, pe care n-o atinge): (a) o bază făcută ca înainte (`tools/dev/migrari/pe-vechi.php`, `seed.php`, `date-demo.php`) → baseline; (b) o bază făcută de `install.php`, apoi `seed.php`, `date-demo.php`; (c) o bază făcută doar de migrări; pe fiecare, funcțiile `ensure*` ale codului comun și ale codului Bioscem (clona la „ultimul commit revizuit”) | (a) baseline-ul nu schimbă schema, datele doar unde declară migrările; `--verifica` 0; (b) `install.php` 0: schema = `asteptat.json`, `--plan` 0, seed-ul neutru, pagina `/acorduri-gdpr`, adminul cu `roles_json`, setările, fără listă de newsletter; a doua oară 1, nimic scris; funcțiile Bioscem pe o instalare nouă scriu doar lista implicită de newsletter (`$randuriBioscemPeInstalareNoua`); (a), (b) cu date demo: funcțiile ambelor coduri, fără nicio excepție, nu schimbă nimic (schemă, rânduri); (c) ale codului comun nu scriu nimic (garda), ale Bioscem doar rândurile declarate (`$randuriHelperiPeBazaNoua`: pagina, cele patru categorii ale Bioscem, lista de newsletter). Testele aplicației pe ambele baze (fum, pluginuri, SEO, comparația cu Bioscem): porțile G-inst și G-vechi din `porti.sh` |
| T11 | `tools/dev/verifica-migrari.php` pe repo și pe o copie cu câte o greșeală (și cu o definiție doar cu tipul, una într-o constantă a clasei, una într-un fișier fără DDL) | 0 pe repo; 1, cu motivul, pe fiecare greșeală (mai jos); 0 pe texte obișnuite care încep cu numele unui tip (`'text'`, `'date'`, „Date invalide.”, `DATE(o.created_at)`) într-o metodă nouă din `AdminController` |

**Instalarea, garda, pașii de activare, contorul** (WP-05): `tools/dev/php.sh tools/dev/test-instalare.php`
(poarta **G-inst**, și cu PHP-ul minim; doar MariaDB, pe baze `cms_inst_*` făcute și șterse acolo, cu licența
într-un dosar al testului și un server pe `CMS_DEV_PORT`+8): `install.php` și `seed.php` fără server (1, pe
stderr), pe o bază goală, a doua oară, oprit la jumătate și reluat, pe o bază străină, fără licență; garda în
fiecare stare (și o migrare din cod cu numărul sub `ultima_ord`, încă nerulată) și o singură citire pe conexiune; `/health` și bannerul; pașii de activare (prima cerere după
licență, o singură dată, pagina ștearsă rămâne ștearsă, ERP oprit și pornit cu cheia goală sau lipsă);
contorul pe dashboard, comenzi, produs și o comandă trimisă, plus dashboard-ul unei baze ca înainte, înainte și
după baseline. Raport: `storage/logs/dev/test-instalare-raport.txt`. Poarta **G-vechi** rulează comparația cu
Bioscem (ambele licențe), testele pluginurilor, SEO și testul de fum pe fiecare pachet pe o bază făcută ca
înainte și trecută prin baseline (`tools/dev/instaleaza.sh --pe-vechi`, baza `cms_dev_vechi`, ștearsă la final).
Din WP-06, secțiunea F: `install.php` cu un pachet de site inventat (înghețarea înaintea setărilor implicite, valorile
private din `privat/`), fără `SITE_SLUG`, cu pachetul altui site sau cu o corectură stricată (1, motivul pe stderr, baza
neatinsă), o adoptare oprită între ultima migrare și înghețare (`--pana-la`: garda `in_urma`, pasul de activare al
ERP-ului nu scrie; `--aplica --site` fără dosarul pachetului sau cu un dosar fără înghețare: 2, cheia `pasi_site`
rămâne, `licenta.php activari` tot nu scrie pragul; după `--aplica` cu pachetul găsește cheia goală).

**Înghețarea** (WP-06): în runner, secțiunea I din `test-migrari.php` (pe MariaDB și MySQL 8): a doua aplicare nu
face nimic, o valoare existentă nu se suprascrie niciodată, `lipsa_sau_gol` completează doar textul gol (din spații),
planul cu fiecare cheie, fișierul schimbat după aplicare sau scos din pachet (12), corectura `-2` după cea de bază,
pachetul altui site sau fără site (2), provizoriu și valorile private lipsă sau ale altui fișier (15, nimic scris; și
`--permite-provizoriu` fără `SITE_COPIE_VERIFICARE=1`), `pasi_site` scris cu pașii așteptați și șters abia după ei; o
rulare cu `--site` care nu-i vede (dosarul pachetului lipsă, alt dosar, alt site; și forma veche a cheii) și o primă
adoptare cu dosarul lipsă sau fără înghețarea din `site-uri/bioscem/` (2, nimic scris); fără `--site`, avertismentul. Generatorul și verificarea: `tools/dev/php.sh tools/dev/test-inghetare.php` (în G-mig,
și cu PHP-ul minim; fără bază): formatul pe fișiere greșite, fișierul privat și datele private (F: emailuri,
telefoane, nu și bucăți dintr-un sha în hex), registrul (R: ordinea corecturilor, numele greșite, pachetul altui
site), cititorul de cod (C: implicitele, rezervele, expresiile, `extrage`), generatorul pe un cod vechi inventat (G:
deciziile cerute și legate de valori, privat/, provizoriu, fișierul închis, determinismul) și verificarea pe
pachetele din repo și pe copii stricate (V: date private, sursa, `migrari.json`, un fișier închis, o setare nouă în
codul comun, clona lipsă).

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
   `config/`, `bootstrap.php`, `database/schema.sql`): fiecare text DDL și fiecare definiție de coloană (listele
   de coloane din `ErpSync::ensureSchema`, `BtIpayPayments::migreaza`) trebuie să fie în
   `tools/dev/migrari/ddl-din-cod.txt`. O definiție e un tip SQL urmat de lungime sau de un atribut
   (`'VARCHAR(40) NULL'`, `'INT NOT NULL DEFAULT 0'`; nu o funcție SQL, `'DATE(o.created_at) >= ?'`), numărată
   oriunde în acest cod, nu doar lângă DDL (o listă de coloane ținută într-o constantă, o proprietate, altă metodă
   sau alt fișier ajunge la fel la un ALTER), sau doar tipul: cu majuscule (`'DATETIME'`) oriunde, cu orice litere
   (`'datetime'`) într-o funcție cu DDL. Un tip singur cu litere mici în afara unei funcții cu DDL nu se numără:
   nu se deosebește de tipurile câmpurilor din formulare (`'text'`, `'date'`: peste o sută azi), deci SQL-ul din
   cod se scrie cu majuscule. Lista e pe fișier și funcție (în afara metodelor: „Clasa (clasă)”), de câte ori
   apare (azi 301 de texte și 26 de definiții în 36 de funcții și fișiere: funcțiile `ensure*`/`asigura*`
   care au DDL, `schema.sql`, tabelele de lucru ale sincronizării FAN, care rămân, și tiparul din
   `WordPressDumpReader`; toate cele 26 de definiții sunt în funcții cu DDL; din WP-05 `install.php` și
   `fan-tracking-sync.php` nu mai au DDL: 70 de rânduri scoase cu `--actualizeaza-lista`). Unul nou, încă o copie a unuia vechi sau unul mutat în altă funcție = încălcare: o
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
