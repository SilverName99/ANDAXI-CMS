# Pachetele de date ale site-urilor

Tot ce ține de un singur site și nu de codul comun stă în **pachetul lui de date**: harta adreselor vechi, regulile
SEO, constantele tehnice, tema, înghețarea setărilor, lista schimbărilor pentru client. Un pachet are doar date
(JSON, Markdown, CSS, imagini, fonturi și scripturile declarate ale temei), **niciodată PHP**: codul comun nu
`include`-ă nimic din el, doar citește fișiere. Formatul de aici e versiunea 1 (WP-13, DB-01; planul de-Bioscem §5).

| Unde | Ce |
|---|---|
| `site-uri/<site>/` | pachetele site-urilor existente: `bioscem`, `biovitality`, `vertij`, `grafoanaytis` |
| `tools/dev/site-demo/` | pachetul inventat al site-ului de dezvoltare, **fără temă** (testele văd nucleul neschimbat) |
| `tools/dev/site-demo-tema/` | o temă inventată, doar pentru testul temei (fiecare cheie altfel decât implicitul) |
| pe server | dosarul din `SITE_DATE_DOSAR` (`.env`; gol = `site/` în rădăcina codului, ignorat de git); în Etapa 2 `~/domains/<d>/site/pachet/` |

Codul îl citește prin `App\Support\DateSite` (fișierele JSON, cu `DateSite::json`, `::seo`, `::site`) și
`App\Support\Tema` (tema). Un fișier lipsă înseamnă valorile implicite ale nucleului; unul stricat se ignoră, cu o
linie în jurnalul PHP (un fișier greșit nu oprește site-ul), dar `tools/dev/verifica-pachet.php` nu-l lasă să ajungă
pe un server, iar `scripts/diagnostic.php --pachet` îl vede pe server (mai jos).

## Ce poate conține un pachet

| Fișier | Cine îl citește | Când |
|---|---|---|
| `pachet.json` | `verifica-pachet.php`, `diagnostic.php --pachet`, deploy-ul din Etapa 2 | manifestul (mai jos) |
| `README.md` | oamenii | — |
| `ce-se-schimba.md` | proprietarul, la adoptare | **obligatoriu** în `site-uri/<site>/`: ce vede altfel clientul pe codul comun (G-m4) |
| `redirecturi.json` | `SeoRedirects` ([SEO.md](SEO.md), „Adresele vechi”) | la fiecare cerere |
| `seo.json` | `DateSite::seo` ([SEO.md](SEO.md), „Datele unui site”) | la fiecare cerere |
| `site.json` | `DateSite::site` | la fiecare cerere: constantele tehnice care nu se schimbă niciodată pe un site existent (WP-16: `cookie_consimtamant`, numele cookie-ului acordului, `CookieConsent::nume`, implicit `cookie_consent`; `cheie_popup_inchis`, cheia din `localStorage` a ferestrei de bun venit închise, `Brand::cheiePopupInchis`, implicit `welcome_popup_inchis`; WP-17: sarea semnăturii newsletterului). Un nume care nu e simplu (`[A-Za-z0-9_-]`) se ignoră, cu un rând în jurnal |
| `tema/tema.json`, `tema/*.css` | `Tema` (mai jos) | la fiecare cerere |
| `tema/public/**` | serverul web, la `/tema/**` | copiat în `public/tema/` la instalare și la fiecare actualizare |
| `public/**` | serverul web, la aceeași adresă | copiat peste `public/` la adoptare (mai jos) |
| `setari-inghetate.json` (și corecturile `setari-inghetate-<N>.json`) | `scripts/migreaza.php --aplica --site` ([MIGRARI.md](MIGRARI.md), „Înghețarea setărilor”) | o dată, la adoptare |
| `migrari.json` | migrările ([MIGRARI.md](MIGRARI.md), „Parametrii site-ului”) | la migrări |
| `pluginuri.json` | `tools/dev/licenta.php`, verificarea de la adoptare (WP-14) | doar unelte; licența vine numai de la CRM |
| `poze-optimizate.json` | `PozeOptimizate` ([SEO.md](SEO.md), „Pozele refăcute”) | la fiecare cerere: pozele mari afișate din fișierele WebP ale pachetului (`public/`), cu mărimea pozei vechi **de pe discul serverului** (`"marimi_de_pe": "disc"`) |
| `seo-texte.json`, `avertismente-produse.json`, `seo-continut.json` | `scripts/seo-texte.php`, `scripts/avertisment-produse.php`, `scripts/seo-continut.php` | o dată, de mână (`--plan`, `--aplica`) |
| `privat/*.json` | înghețarea setărilor | **ignorat de git** (decizia OD-49): valorile private, copiate de mână pe server; nu intră în manifest |
| `continut/**` | importatorul de conținut al unui site nou (GR-20, Etapa 4) | încă nu face parte din format: verificarea îl refuză până vine importatorul cu regulile lui |

Doar aceste locuri și aceste tipuri: în rădăcină `.json` și `.md` (un nume necunoscut e doar ATENȚIE); în `tema/`
doar `tema.json` și blocuri `.css`; în `tema/public/` imagini (`svg png jpg jpeg webp gif avif ico`), fonturi `woff2`,
`.css` și **doar scripturile declarate** în `tema.json` → `js_final`; în `public/` doar imagini. Nicăieri `.php` (și
`.phtml`, `.phar` …), `.htaccess`, fișiere ascunse, legături simbolice sau fișiere peste 1 MB.

## Manifestul (`pachet.json`)

```json
{
    "format": 1,
    "site": "bioscem",
    "fisiere": {"redirecturi.json": "<sha256>", "seo.json": "<sha256>", "…": "…"},
    "obligatorii": ["redirecturi.json", "seo.json"]
}
```

- `site`: numele site-ului; pe server trebuie să fie `LICENTA_SITE` și `SITE_SLUG` din `.env`, ambele (pe copia de verificare
  licența e a copiei, `verificare…`, deci acolo doar `SITE_SLUG`; pachetele de dezvoltare au `demo`).
- `fisiere`: **fiecare** fișier al pachetului (fără manifest și fără `privat/`), cu sha256-ul lui. Un fișier lipsă,
  unul în plus sau unul schimbat se văd imediat.
- `obligatorii`: fișierele fără de care site-ul s-ar schimba fără să spună nimic (implicit cele citite la fiecare
  cerere care există în pachet: `redirecturi.json`, `seo.json`, `site.json`, `tema/tema.json`, `poze-optimizate.json`).

Îl scrie `tools/dev/php.sh tools/dev/verifica-pachet.php --actualizeaza-manifest` (determinist: aceleași fișiere, aceiași
octeți), după orice schimbare voită într-un pachet; `tools/inghetare/genereaza-setari.php` îl rescrie singur când
rescrie înghețarea. Clasa `App\Support\PachetSite` îl calculează și îl verifică (aceeași regulă în unealtă și pe server).

## Tema (`tema/tema.json`)

Fără `tema.json` (sau cu fiecare cheie la valoarea ei implicită) **paginile ies octet cu octet ca înainte**: fiecare
cheie are ca implicit exact ce făcea nucleul. Dovada: comparația cu codul Bioscem (G-cmp), care, pe lângă paginile ei,
are cazul „vederi” (`tools/dev/compara-cu-baza.php`: paginile din bază „404” și „checkout/succes” nepublicate și un antet
cu `{{mobile_menu}}`, deci vederile de rezervă și sertarul de meniu, octet cu octet ca la Bioscem), și testul temei
(`test-pluginuri.php` T3: fără temă = `tema.json` gol = fiecare cheie scrisă la implicit, cu semnele codului de dinainte).
O temă aduce doar ce cheile de mai jos permit; tot restul înfățișării unui site (antetul, subsolul, paginile) e conținut
în baza lui.

| Cheie | Implicit | Ce face (punctul din `views/layout.php` sau vederea) |
|---|---|---|
| `css` | `[]` | foile de stil ale temei (`/tema/…css`), după `app.css`, înaintea CSS-ului din Design |
| `incarca_app_css` | `true` | `false`: paginile publice nu încarcă `/assets/css/app.css` (adminul îl încarcă din layoutul lui) |
| `fonturi` | `[]` | fonturile din nucleu: `/assets/vendor/fonturi/<familie>/<familie>.css` (`rubik`, `dm-sans`, `playfair`; OFL) |
| `fonturi_google` | `true` | `false`: fără legătura spre Google Fonts (DM Sans și Playfair); pentru Bioscem rămâne până la SEO B6 |
| `bootstrap` | `false` | `true`: Bootstrap 5.3.8 din nucleu (`/assets/vendor/bootstrap/`, MIT), CSS-ul în `<head>`, JavaScript-ul cu `defer` înainte de `</body>` |
| `js_final` | `[]` | scripturile temei (`/tema/…js`), cu `defer`, în ordine, înainte de `</body>` |
| `main` | `"container"` | `"lat"`: `<main class="site-container">`, fără `.container` (secțiunile își pun singure containerul) |
| `flash` | `"panou"` | `"bootstrap"`: mesajele ca `.alert` (Bootstrap) într-un `.container` propriu |
| `buton_sus` | `false` | `true`: butonul „Înapoi sus” (ancora spre `#inceput-pagina`, imediat după `<body>`, apare după un ecran derulat); înfățișarea (`.sus`) o dă CSS-ul temei |
| `culoare_principala` | `"#2f8d5b"` | culoarea butoanelor paginilor de rezervă (404, linkul de plată, verificarea plății BT) și a bannerului de cookie-uri și a popup-ului |
| `logo_meniu_mobil` | (lipsă) | `{"candidati": [...], "implicit": "...", "alt": "..."}`: logo-ul sertarului `{{mobile_menu}}`, primul candidat care există ca fișier (`/uploads/…`, `/tema/…`, `/assets/…`), altfel `implicit`; fără cheie, ca azi |
| `iconite_categorii` | (lipsă) | `[["cuvânt", "<path …/>"], …]`: iconițele din `{{product_categories}}`, încercate în ordine (cuvântul în numele categoriei, cu litere mici, fără diacritice) înaintea celei implicite; fără cheie, lista de azi a nucleului |
| `css_inline` | `{}` | `{"pagina_succes": "pagina-succes.css"}`: blocul `<style>` al paginii de mulțumire (`views/site/checkout-success.php`) înlocuit cu fișierul din `tema/` |

Reguli:

- **Fișierele publice ale temei** stau în pachet în `tema/public/` și se servesc la `/tema/…`, cu `?v=<data fișierului>`
  (`Tema::url`), ca o temă schimbată să ajungă imediat la vizitatori. Pe server stau în `public/tema/` (ignorat de git),
  copiate din pachet la instalare și la fiecare actualizare, și numai de acolo intră în pagină: o foaie, un script sau un
  logo al temei care nu a ajuns în `public/tema/` nu se pune în pagină (serverul web n-ar avea ce servi; `diagnostic.php
  --pachet` spune ce lipsește). În dezvoltare `tools/dev/router.php` le servește direct din pachetul ales
  (`SITE_DATE_DOSAR`), doar din `tema/public/` și doar tipurile de mai sus, și o spune aplicației prin constanta
  `ANDAXI_DEV_TEMA_DIN_PACHET` (`Tema::fisierPublic`).
- **SVG-ul** care ajunge în pagini trece printr-o listă albă (`App\Support\SvgSigur`): iconițele (`fragment`) doar
  `path circle rect line polyline polygon ellipse` cu atributele de geometrie, fișierele `.svg` (`problemaFisier`) fără
  `script`, `foreignObject`, `image`, `a`, `style`, atribute `on…`, `href`/`url()` în afara fișierului, DOCTYPE sau entități.
- **Un bloc `css_inline`** nu are voie să conțină `</style`; nicio foaie a pachetului nu încarcă ceva de pe alt server,
  ca adresa vizitatorului să nu plece la un terț înainte de acordul pentru cookie-uri: niciun `@import` (foile se declară
  în `css`), nicio adresă cu schemă (alta decât `data:`) sau care începe cu `//` în `url()`/`src()` și niciun șir care
  începe cu `http:`, `https:` sau `//` (`image-set()`). Foaia se citește ca de browser: comentariile se sar, escape-urile
  (`\68ttps`, `u\72l(`) se decodează.
- **O valoare greșită** nu oprește site-ul: cheia rămâne la implicit (un element greșit dintr-o listă se scoate) și se
  scrie un rând în jurnalul PHP. `verifica-pachet.php` refuză pachetul (`Tema::valideaza`).
- **Fonturile și Bootstrap-ul** sunt ale nucleului (`public/assets/vendor/`, proveniența în `VERSIUNE.txt`, licențele
  alături): o temă le cere prin chei, nu le copiază în pachet.
- O temă schimbă CSS-ul, deci se dovedește cu stilurile calculate (G-vizual, `tools/dev/capturi-publice.sh`;
  [DEZVOLTARE.md](DEZVOLTARE.md), „Bioscem nu se schimbă”), nu doar cu HTML-ul.

Exemplu, tema grafoanaytis (GR-19, în Etapa 4; valorile din planul portului, §7):

```json
{
  "css": ["/tema/css/tokens.css", "/tema/css/catalog.css", "/tema/css/produs.css"],
  "incarca_app_css": false,
  "fonturi": ["/assets/vendor/fonturi/rubik/rubik.css"],
  "fonturi_google": false,
  "bootstrap": true,
  "js_final": ["/tema/js/imagini.js", "/tema/js/miscare.js"],
  "main": "lat",
  "flash": "bootstrap",
  "buton_sus": true,
  "culoare_principala": "#0f766e"
}
```

## Fișierele publice la o adresă fixă (`public/`)

Unele fișiere ale unui site au deja o adresă pe care o știu paginile lui, Google și cache-urile, de exemplu variantele
WebP ale logo-ului Bioscem (`/assets/img/bioscem-logo-2-212.webp`, Bioscem `a14e803`, în `seo.json` → `logo_antet`).
Adresa nu se poate muta sub `/tema/` fără să schimbe paginile, deci pachetul le ține în `public/` cu aceeași cale:
la adoptare se copiază peste `public/` al site-ului (în Etapa 2, în docroot). Doar imagini, niciuna peste un fișier al
nucleului (verificarea compară cu fișierele din git), în `uploads/` (pozele clientului) sau în `tema/`.
`tools/dev/compara-cu-baza.sh` le pune în `public/` doar pe durata comparației.

**Pozele refăcute** (`poze-optimizate.json`, Bioscem `e2c97dc`, `070e1af`): fișierele WebP noi stau tot aici
(`public/assets/img/optimizate/`), iar lista spune pentru fiecare poză veche din `/uploads/` mărimea ei în octeți. Lecția
Bioscem `070e1af`: mărimea se ia **pe server, de pe disc** (`stat -c %s`), niciodată descărcând poza (CDN-ul Hostinger
trimite pozele refăcute, cu alți octeți; cu mărimile luate așa nicio poză nu trecea pe WebP). Verificarea cere
`"marimi_de_pe": "disc"`, poze vechi în `/uploads/` cu extensie de imagine, mărimi întregi pozitive și fiecare fișier nou în
`public/`, WebP adevărat și mai mic decât poza veche; `scripts/diagnostic.php --pachet` compară pe server fiecare mărime cu
fișierul de pe disc. `tools/dev/compara-cu-baza.sh` pune pe durata comparației poze vechi inventate, de exact mărimea din
listă.

## Verificarea (`tools/dev/verifica-pachet.php`)

```sh
tools/dev/php.sh tools/dev/verifica-pachet.php                         # site-uri/*, tools/dev/site-demo, tools/dev/site-demo-tema
tools/dev/php.sh tools/dev/verifica-pachet.php --actualizeaza-manifest # după o schimbare voită într-un pachet
tools/dev/php.sh tools/dev/verifica-pachet.php --autotest              # fiecare greșeală plantată într-o copie e prinsă
```

Rulează în testul de fum (pasul 1, și autotestul), deci în porțile G-smoke și G-php. Verifică locul și tipul fiecărui
fișier, conținutul (JSON valid, UTF-8, CSS fără `</style`, fără `@import` și fără alt server, SVG din lista albă), `tema.json`
(`Tema::valideaza`), lista pozelor refăcute (`poze-optimizate.json`, mai sus), înghețarea setărilor (se citește ca la runner-ul migrărilor și e a site-ului; restul îl verifică
`tools/inghetare/verifica-inghetare.php`), manifestul (`PachetSite::verifica`) și `ce-se-schimba.md` la fiecare site.
Autotestul plantează,
pe rând, într-o copie a temei demo: un `.php`, un `.phtml`, un `.htaccess`, o legătură simbolică, patru SVG-uri
periculoase, un script nedeclarat, un fișier de peste 1 MB, două iconițe periculoase, un bloc cu `</style`, un font de la
Google și alte unsprezece forme ale unei cereri spre alt server (`image-set()` cu un șir, `url()` cu escape, numele
`u\72l(`, `//`, `\/`, `\\`, `http:` fără `//`, un tab în schemă, `@import` scris cu escape, `@import` al unui
`data:text/css`, un comentariu cu apostrof înaintea unui `image-set()`; o foaie cu adrese locale, un SVG `data:` și un șir
obișnuit trece),
o cheie necunoscută, o foaie lipsă, un fișier schimbat sau nou față de manifest, manifestul lipsă, un pachet de
client fără `ce-se-schimba.md`, un fișier peste unul al nucleului sau în `uploads/`, `continut/`, un fișier ascuns, un
JSON stricat, altceva decât JSON în `privat/`, o înghețare pe care runner-ul n-o citește și trei liste de poze
refăcute greșite (fără `"marimi_de_pe": "disc"`, fără fișierul nou, cu mărimea scrisă ca text; una bună trece).

## Pe server

- **Versiunile de cod** (`git archive`, Etapa 2) nu conțin `site-uri/` și nici `tools/` (`.gitattributes`,
  `export-ignore`): un site primește doar pachetul lui, separat, ca datele unui client să nu ajungă pe serverul altuia.
  Pachetul unui site se scoate cu `git archive <tag>:site-uri/<site>` (subarborele; regula de mai sus nu se aplică în
  el). Site-ul de verificare ia codul cu `git clone`, deci are și `site-uri/`, și `tools/`.
- **La adoptare** ([MIGRARI.md](MIGRARI.md), „Pe server, la adoptare”, pasul 1): pachetul în `SITE_DATE_DOSAR`,
  `tema/public/` în `public/tema/`, `public/` peste `public/`, apoi `$PHP scripts/diagnostic.php --pachet`, al cărui
  ultim rând trebuie să fie `rezultat     : ok` (manifestul, site-ul = `LICENTA_SITE` = `SITE_SLUG`, ambele obligatorii,
  fiecare sha256, tema, copiile
  publice; altfel iese cu 1 și spune ce lipsește sau diferă). Un pachet lipsă sau stricat nu are voie să treacă
  neobservat: valorile ar reveni la cele implicite (de exemplu numele cookie-ului de acord: fiecare vizitator ar vedea
  din nou bannerul). De aceea `site.json` și `tema/tema.json` sunt obligatorii în pachetul Bioscem, iar `/health` arată
  pe al treilea rând `pachet: <primele 12 caractere ale sha256-ului lui pachet.json>` sau `pachet: lipsa` (WP-16).
- **Deploy-ul din Etapa 2** (`actualizeaza.sh`) înlocuiește pachetul dintr-o bucată și refuză comutarea dacă
  verificarea pică (planul de-Bioscem §5.2).

## Ce urmează

- WP-14: restul pachetului Bioscem (`pluginuri.json`, `README.md`), baza de dezvoltare făcută ca a Bioscem
  (`cms_dev_bioscem`) și comparația cu pachetul ei (`--site=bioscem`), faza emailurilor.
- **Făcut în WP-16:** identitatea Bioscem a ieșit din nucleu în pachetul ei: logo-ul sertarului (`logo_meniu_mobil`),
  iconițele (`iconite_categorii`), blocul paginii de mulțumire (`tema/pagina-succes.css`, `css_inline.pagina_succes`),
  cookie-ul și popup-ul (`site.json`); BV și GR au `site.json` cu cookie-ul lor (`bv_cookie_consent`). Testul pachetului
  (G-pachet, `tools/dev/test-pachet-site.php bioscem|neutru`) compară valorile pachetului Bioscem cu cele scrise în
  codul Bioscem și caută numele unui client în paginile, emailurile și implicitele nucleului cu pachetul demo.
- WP-17: sarea semnăturii newsletterului (`site.json`). SEO B6: fonturile locale pentru Bioscem (`fonturi`).
- Etapa 4: tema grafoanaytis (GR-19) și conținutul de pornire (`continut/`, GR-20).
