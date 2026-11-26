# Securitatea nucleului

Reparațiile de securitate ale codului comun, în ordinea în care au venit. Fiecare are testele ei (rulate în testul de
fum și în porțile pachetului de lucru) și, unde schimbă ce vede cineva, un rând în `site-uri/*/ce-se-schimba.md`.

Reparațiile mai vechi, ale pasului WP-12/WP-12b (parolele adminilor după felul lor, id nou de sesiune la logare, adresa
vizitatorului doar din `App\Support\IpClient`), sunt descrise în [DEZVOLTARE.md](DEZVOLTARE.md) („Erori moștenite”) și în
[VERIFICARE.md](VERIFICARE.md); testul lor e `tools/dev/test-securitate.php`.

## Revizuirea din 18 noiembrie 2026 (N1–N5)

O revizuire independentă a găsit cinci probleme în nucleu. Toate sunt reparate aici; testul comun e
`tools/dev/test-securitate-admin.php` (în testul de fum, pe licența „toate”), plus verificările din browser
(`tools/dev/*`, previzualizările adminului) și diferențialul HTTP al paginilor publice (bază vs. HEAD: 0 diferențe în
afara antetelor noi de la N5).

### N1 — un rol parțial putea ajunge administrator general prin JavaScript

Adminii cu rol parțial (magazin, blog, emailuri) puteau salva HTML/JS în șabloane (produs, blog), pagini, design,
newslettere și formulare opt-in. Codul acela rula în două locuri **pe originea adminului**:

- **a)** în previzualizările din editoarele de admin, puse în `<iframe srcdoc>` fără izolare;
- **b)** pe paginile publice care împart originea și sesiunea cu `/admin` (pagina produsului cu șablonul lui, pagina
  personalizată), deci un administrator general care deschidea site-ul rula codul scris de un rol inferior.

**a) Previzualizările sunt izolate.** Fiecare `<iframe>` de previzualizare care randează HTML scris de un om are acum
`sandbox="allow-scripts"` **fără** `allow-same-origin`: cadrul primește o origine opacă, deci scripturile din el rulează
(previzualizarea arată exact ca pagina reală), dar nu pot atinge nici documentul adminului, nici cookie-urile, nici
sesiunea. Editoarele atinse: `views/admin/product-template-editor.php`, `blog-template-editor.php`, `page-editor.php`,
`design-site.php`, `emails.php` (opt-in, newsletter), `emails/builder.php` și previzualizarea pașilor de newsletter
(`public/assets/js/admin/newsletter-pasi.js`). (`blog-post-editor.php` folosea deja `sandbox="allow-scripts"`.)

Unde codul previzualizării citea documentul cadrului (înălțimea, nodurile de text cu un cod cu parametri, actualizarea
live), nu mai poate: comunică prin `postMessage`, cu verificare strictă a originii (`"null"` = originea opacă) și a
sursei (fereastra chiar a cadrului de previzualizare). Înălțimea o trimite cadrul; chenarele codurilor cu parametri din
editorul paginii (`{{products_by_template:…}}`, `{{category_event_rows:…}}`…) se cer și se trimit tot prin mesaje.
Fiecare previzualizare randează și se actualizează ca înainte, inclusiv scripturile din ea.

Un lucru pe care originea opacă îl schimbă: browserul cere fonturile cu CORS, deci un cadru izolat nu primește fonturile
servite de site (`/assets/vendor/fonturi/…`, `/tema/…`), iar fișierele lor nu trimit `Access-Control-Allow-Origin`.
Previzualizarea din Antet, subsol și meniu (`design-site.php`, 23 noiembrie 2026; [PACHETE.md](PACHETE.md), „Tema”) le
primește de la pagina adminului: cadrul cere fonturile (`postMessage`), pagina verifică sursa (fereastra cadrului) și
originea (`"null"`), citește `@font-face` din foile site-ului de pe originea ei și îi trimite doar fișierele fonturilor,
pe care cadrul (care primește mesaje doar de la `parent`) le adaugă cu `FontFace`. Izolarea rămâne aceeași: cadrul nu
primește nimic altceva și nu atinge pagina adminului.

**b) Doar administratorul general poate salva cod activ.** O gardă centrală a routerului
(`App\Http\GardaCodActiv`, după `GardaPluginuri`) prinde orice POST din admin al unui admin logat care **nu** e
administrator general, pe o adresă la care rolul lui are voie. Din tot ce trimite formularul (și corpul JSON, câmpurile
`_b64`, blocurile JSON) se citesc „părțile care pot rula” (`App\Support\CodActiv`): un `<script>`, un atribut `on…`, o
adresă `javascript:`/`vbscript:` (cu entitățile decodate, ca în browser), un `srcdoc`, un `<base>`, un câmp care e în
întregime JavaScript (`js_content`). Citirea e mai largă decât a browserului (etichete în comentarii, în atribute, în
`<textarea>`), ca să nu scape nimic.

Baza de comparație e **exact entitatea pe care o scrie cererea**, aleasă după rută, secțiune (`$_POST['section']`) și
id-ul propriu al acelei entități — niciodată o uniune de rânduri nelegate. Pe `/admin/emails` se ia doar rândul scris de
secțiunea cerută (șablonul de newsletter după `newsletter_id`, campania după `campaign_id`, formularul opt-in după
`optin_form_id`, setările șablonului de comandă după tipul lui), nu abonații (al căror nume îl scrie formularul public de
opt-in) sau listele; setările se iau doar din grupul scris de formular (prefixul lor, de exemplu `floating_cart_`),
nu după numele câmpurilor trimise. O rută sau secțiune pe care garda n-o poate lega de un rând scris are baza goală
(fail-closed), deci orice cod e nou și se refuză. Corpul cererii se citește și când e JSON trimis cu alt `Content-Type`
(unele controllere citesc `php://input` direct). Altfel un rol parțial ar fi putut muta cod scris de administratorul
general dintr-un loc în altul (de exemplu numele unui abonat, pus prin opt-in public, folosit ca „cod deja salvat”).

Regula exactă (decizia: **se refuză doar când se schimbă codul**): o salvare trece dacă părțile ei care pot rula sunt
**deja** în ce e salvat acum pentru ce se editează (rândul șablonului, al produsului…). Așa un rol parțial poate
corecta un text într-un șablon care conține JavaScript scris de administratorul general, fără să-l rupă, dar nu poate
adăuga, schimba sau înmulți cod. Altfel salvarea e **refuzată întreagă** (nimic nu se scrie, nimic nu se scoate pe
tăcute), cu un mesaj clar în română și primul cod găsit, și un rând în Jurnal activitate (`cod_activ_refuzat`).
Importurile din fișiere (articole, recenzii) nu trec prin formular: le verifică `AdminController` la citirea fișierului,
cu aceeași regulă.

### N2 — garda originii pe cererile din admin

Din ~145 de rute POST din admin, doar ~39 cereau jetonul `AdminCsrf`; restul se bazau doar pe cookie-ul de sesiune
`SameSite=Lax` (care pleacă totuși la un POST de pe un subdomeniu și în browserele vechi).

`App\Support\GardaOrigine`, în `public/index.php` înaintea routerului, verifică orice cerere sub `/admin` (și `/admin`
însuși) cu altă metodă decât GET/HEAD/OPTIONS, **oricare ar fi ruta**:

1. dacă browserul trimite `Sec-Fetch-Site`, cererea trece doar cu `same-origin` sau `none` (adresă scrisă de om);
   `same-site`, `cross-site` și orice altceva se refuză;
2. altfel, dacă trimite `Origin`, el trebuie să fie originea site-ului (gazda cererii pe http sau https, ori cea din
   `APP_URL`); `Origin: null` se refuză;
3. fără niciunul dintre antete (un browser foarte vechi, un script din linia de comandă), ca înainte: cookie-ul
   `SameSite=Lax` și jetonul `AdminCsrf` unde există.

Refuzul e 403, cu un rând în `storage/logs/securitate.log` (metoda, calea fără parametri, antetele, adminul din
sesiune), fără date din formular. `AdminCsrf` rămâne neatins: garda e un strat în plus, nu un înlocuitor.

**Cererile din alte site-uri lăsate dinadins să intre** (niciuna nu e sub `/admin`, deci garda nu le atinge):

| Cale | Ce e |
|---|---|
| `/webhook/bt-ipay`, `/webhook/stripe`, `/webhook/euplatesc` | notificările (IPN/webhook) băncilor și procesatorilor de plată |
| `/checkout/bt/retur`, `/checkout/succes/{nr}` | întoarcerea clientului de la bancă |
| `/plata/{token}` | plata unei diferențe dintr-un link trimis pe email |
| `/api/erp/notificare`, `/api/erp/adrese` | ERP-ul ANDAXI (autentificat cu cheia de integrare) |
| `/api/desktop/*` | aplicația Windows a fișierelor din cloud (autentificare cu cheie de aplicație, nu cu sesiune) |
| `/api/statistici` | evenimentul scriptului paginilor (fără sesiune) |
| `/api/donations/intent`, `/api/cerere-oferta`, `/api/cart/*`, `/api/checkout/*` | scripturile paginilor publice |
| formularele publice: `/contact/send`, `/review-form/submit`, `/produs/{slug}/review`, `/gdpr-agreements/submit`, `/newsletter/optin/{slug}`, `/newsletter/unsubscribe/{token}`, `/newsletter/confirmare/{token}`, `/cont/confirmare/{token}` | formularele vizitatorilor și dezabonarea „cu un clic” (RFC 8058) |
| contul clientului: `/login`, `/register`, `/contul-meu/*`, `/cont/*` | formularele contului (au apărarea lor: `SameSite=Lax`, `AdminCsrf` pe cele sensibile, confirmarea pe email) |

(Preluarea licenței de la CRM și cronurile rulează din linia de comandă, fără HTTP, deci nici ele nu sunt atinse.)

### N3 — scripturile terților nu rulează lângă o sesiune de admin

GTM/GA/Clarity (din `views/layout.php`), chatul tawk.to și orice alt script de terț injectat prin setări rulează pe
aceeași origine ca adminul. Cine poate publica în containerul GTM (sau în contul tawk.to…) putea rula cod cu sesiunea
oricărui admin care deschidea site-ul.

`App\Support\EticheteTerte::permise()` oprește toate aceste scripturi:

- cât timp sesiunea are un admin (`admin_id`, chiar cu ceasul lui de inactivitate expirat — aceeași condiție ca pentru
  cache-ul de pagini, deci o pagină fără ele nu ajunge în cache și una din cache nu ajunge la un admin);
- pe orice adresă a adminului;
- pe site-ul de verificare, ca până acum.

Pentru un vizitator fără admin în sesiune nu se schimbă nimic: pagina e octet cu octet aceeași (dovedit de
diferențialul HTTP și de N3 din testul de fum).

Din 26 noiembrie 2026, peste această gardă stă acordul vizitatorului **pe categorii** (bannerul de cookie-uri,
[PLUGINURI.md](PLUGINURI.md), „Bannerul de cookie-uri”): în `views/layout.php`, `$tertePermise` (garda de aici) și apoi
`CookieConsent::permite('statistici')` pentru GA, GTM, Clarity, `permite('marketing')` pentru Google Ads; `ChatLive::activ` cere
garda și `permite('extern')`. Bannerul nu adaugă scripturi de la terți: doar `/assets/js/consimtamant.js` (al site-ului), datele
lui într-un `<script type="application/json">` (JSON cu `<`, `>`, `&`, `'`, `"` scăpate) și paza din `<head>`, în care numele și
valoarea cookie-ului ajung prin `View::sirJs` (valoarea e deja validată de `CookieConsent::citeste`).

### N4 — gazda cererii în cheia cache-ului de pagini

O cerere cu un antet `Host` străin (sau adresa tehnică a găzduirii) putea otrăvi cache-ul de pagini cu adrese sau
anteturi construite din gazda aceea. Acum (`App\Support\ResponseCache`): o cerere pe o gazdă necunoscută nu citește și
nu scrie cache-ul, iar gazda intră în cheia cache-ului, deci o pagină făcută pentru o gazdă nu se dă niciodată pe alta.
„Cunoscută” = gazda din `APP_URL`, varianta ei cu sau fără `www`, sau una din `SEO_GAZDE_ALIAS` (aceleași gazde pe care
`SeoRedirects` le mută pe cea canonică). Fără `APP_URL` (o instalare neconfigurată) orice gazdă e primită — cheia o ține
oricum separat. Aliasurile de găzduire, `www`/non-`www` și serverul de dezvoltare (`127.0.0.1:<port>`) merg mai departe;
s-a ales varianta cea mai puțin perturbatoare (cheie + ocolirea cache-ului), nu refuzul gazdelor necunoscute, ca un
alias de găzduire să nu pice.

### N5 — anteturile fișierelor încărcate

Răspunsurile `/uploads` nu trimiteau `X-Content-Type-Options: nosniff`, iar un SVG putea fi servit în linie. Acum, și pe
calea PHP (`public/index.php`) și în șabloanele `.htaccess` (`public/.htaccess`, `deploy/stub/.htaccess`):

- orice fișier din `/uploads/` primește `X-Content-Type-Options: nosniff` (browserul nu ghicește alt tip din conținut);
- un fișier de tip SVG sau HTML/XML (`svg`, `svgz`, `html`, `htm`, `xhtml`, `xml`, `xsl`, `xslt`, `shtml`) primește în
  plus `Content-Security-Policy: sandbox`, deci, deschis direct, e un document izolat (fără scripturi, cu altă origine
  decât site-ul).

Pentru paginile obișnuite diferențialul HTTP arată doar aceste antete noi pe `/uploads`.

## Adresele din care parse_url nu scoate o cale (N6, 24 noiembrie 2026)

O revizuire de securitate a găsit o otrăvire a cache-ului de pagini prin forma adresei, pe ambele linii de cod
(`claude/etapa1-cod-comun` și `claude/pensiune`). `parse_url()` din PHP refuză de tot o adresă ca `/x:1/?s=…`, `/x:1/y?s=…` sau
`/x:1` (un „:cifre” urmat de „/” sau de sfârșitul căii pare portul unei gazde) și nu găsește nicio cale în `//x?s=…` („x” pare o
gazdă). Routerul (`App\Http\Router::gaseste`) și cheia cache-ului de pagini (`App\Support\ResponseCache`) luau atunci calea „/” și
query-ul gol, deci cererea se randa ca prima pagină și se scria în cache sub cheia primei pagini, în timp ce `$_GET` rămânea al
cererii (serverul web îl ia din adresa întreagă). Cu o singură cerere, oricine putea înlocui prima pagină din cache cu o pagină
randată pentru adresa lui, la toți vizitatorii, până la expirarea intrării (10 minute la cache-ul extins), și o putea reface oricând.
Stratul SEO nu oprea asta: repară „/” final și „//” doar când calea curată e alta, iar cache-ul se scrie înaintea redirecționării lui.

Ce ajungea la vizitatori depinde de site. Pe un site capturat (adoptarea WordPress) prima pagină din cache era randată cu calea
goală, deci formularul de contact din subsolul ei avea `action=""` și nonce-ul căii goale: orice trimitere era refuzată („Te rugăm să
reîncarci pagina”), iar mesajul nu se scria. Pe celelalte site-uri prima pagină de azi nu citește nimic din `$_GET`, deci intrarea
avea același conținut; dar `//cos/` (pentru `parse_url` gazda „cos” cu calea „/”, pentru restul codului calea coșului, unde pozele
nu se optimizează) punea în cache o primă pagină cu pozele fără `width`/`height`. Orice afișare care ar depinde de `$_GET` pe prima
pagină ar fi fost pusă în cache la fel.

Ce s-a schimbat (același cod pe ambele linii):

- `Router::caleCerere()` (nou): calea citită de `parse_url`, sau null; `Router::gaseste()` nu găsește nicio rută pentru o adresă
  fără cale (nu mai cade pe „/”).
- `public/index.php`, după interceptările de dinainte (mentenanța, campaniile…) și înaintea cache-ului de pagini: o adresă fără cale
  primește răspunsul unei adrese necunoscute — la GET pagina 404 a site-ului (`SiteController::paginaNegasita`, ca ruta generală
  pentru o pagină care nu există), altfel 404-ul routerului —, cu `Cache-Control: no-store`, fără cache-ul de pagini.
- `ResponseCache::adresaCuCheie()` (nou), la începutul lui `beginRequest`: o adresă fără cale citită de `parse_url`, ori cu o gazdă
  fără schemă (`//cos/`), nu citește și nu scrie cache-ul. Forma absolută (`http://gazda/cale`) își păstrează calea (din a doua
  trecere, mai jos, fără cache).
- `views/layout.php`: pagina 404 a unei astfel de adrese are calea ei scrisă (până la „?”), nu „/”, deci titlul, descrierea și
  canonicalul paginii 404, ca orice adresă necunoscută (altfel ar fi luat titlul și descrierea primei pagini).

Adresele obișnuite (cu o cale citită de `parse_url`) merg exact ca înainte: aceleași rute, aceeași cheie, aceeași pagină. Singura
excepție voită (în afara celor din a doua trecere, mai jos): o adresă care începe cu „//” și pe care stratul SEO n-o repară (căile
excluse, de exemplu `//cos/`) își păstrează pagina, dar nu mai intră în cache. O cerere în forma absolută fără cale (`GET http://gazda HTTP/1.1`, pe care browserele nu o
trimit) primește acum 404-ul, ca orice adresă fără cale.

Testele: `tools/dev/test-seo.php`, A (routerul și cheia, fără server) și `tools/dev/lib/test-seo-viteza.php`, E4.2 (pe server, cu
cache-ul extins pornit: fiecare astfel de adresă dă 404-ul site-ului cu `no-store` și nicio intrare; prima pagină rămâne octet cu
octet a ei, fără marca din adrese; `?page=` are intrarea lui, `utm_*` și `?s=` ocolesc cache-ul, ca înainte), în poarta G-seo.

### A doua trecere: „#”, query-ul serverului și query-ul stricat (SEC-1–SEC-3, 25 noiembrie 2026)

A doua revizuire de securitate a reparației a găsit aceeași clasă de problemă (cheia din cache diferă de ce a primit pagina) pe
adrese pe care `parse_url` le citește, pe ambele linii, tot de dinainte (codul atins era același și înaintea lui N6):

- **„#” înaintea lui „?” (SEC-1).** Stratul SEO (`SeoRedirects::imparteUri`) tăia fragmentul doar din cale, păstra textul de după
  „#” drept query și, când repara adresa („/” final, „//”, forma absolută, „/” lipsă pe un pachet cu `slash_final`), rescria
  `REQUEST_URI` la „calea curată?acel text”. Serverul web taie însă query-ul la „#” (php -S, Apache), deci `$_GET` era gol, iar
  cheia (citită din adresa rescrisă) avea parametrul. Cache-ul se scrie înaintea 301-ului stratului SEO: o singură cerere
  (`curl --request-target '/magazin/#?sort=price_desc'`) punea sub cheia lui `/magazin?sort=price_desc` lista sortată alfabetic,
  servită apoi (HIT) oricui deschidea adresa, 10 minute, și refăcută oricând. La fel `/magazin//#?…`, `/categorie/<slug>/#?…`,
  HEAD și `GET http://gazda/magazin/#?…`.
- **Forma absolută cu „?” înaintea primului „/”.** La `GET http://gazda?sort=price_desc&x=/magazin/` același strat lua drept cale
  tot ce urma după primul „/” din query, deci pe un server care primește o astfel de cerere (php -S o refuză, Apache o primește)
  adresa rescrisă `/magazin` punea lista sortată price_desc chiar sub cheia lui `/magazin`.
- **Un server care păstrează „#” în query** (comportamentul LiteSpeed și al CDN-ului Hostinger nu se poate verifica aici):
  `/magazin#?sort=price_desc`, fără nicio reparație, punea lista price_desc sub cheia lui `/magazin`, iar `/#?s=…` scria cheia
  primei pagini cu `$_GET['s']` plin, adică chiar condiția N6, pe o adresă pe care `parse_url` o citește.
- **Octeții de control (SEC-2).** `parse_url` îi face „_”: `/?blog\x01page=2` avea cheia lui `blog_page=2`, cu `$_GET` fără
  `blog_page`. Doar pe un server care îi lasă să treacă (php -S, Apache în modul strict și nginx de la 1.21.1 îi refuză).
- **Un query pe care `parse_str` îl strică.** O cheie repetată (rămâne ultima valoare), o pereche fără cheie („=…”), o cheie codată
  sau tăiată la „%00” dădeau cheia paginii obișnuite, dar codul care pune adresa întreagă în pagină (linkul „Înapoi” din bannerul
  unei campanii, `Campanii::linkImplicitBanner`) ducea în pagina din cache textul ales de cine a trimis cererea.

Ce s-a schimbat (același cod pe ambele linii):

- `SeoRedirects::imparteCererea()` (nou): adresa cererii se taie cum o taie serverul web. „#” începe fragmentul oriunde ar fi, deci
  nici calea, nici query-ul nu trec de el, iar gazda formei absolute se termină la primul „/”, „?” sau „#” (`imparteUri`).
  `/magazin/#?sort=x` e calea `/magazin/` fără query: 301 spre `/magazin`, iar pagina randată (și intrarea ei) e `/magazin` fără
  parametri, deci corectă. Locațiile date de aplicație se taie ca înainte.
- `ResponseCache::adresaCuCheie()`: cheia doar pentru o adresă care e exact calea și query-ul citite de `parse_url`, scrisă ca
  linkurile site-ului (calea de la „/”, apoi „?query”); nu pentru una cu fragment, cu octeți de control sau în forma absolută.
- `ResponseCache::queryCaServerul()` (nou), în `beginRequest`: query-ul cheii trebuie să fie cel din care serverul a făcut `$_GET`
  (`QUERY_STRING`), în orice ordine și scriere; altfel nimic din cache. Pentru o cerere obișnuită cele două sunt același text.
- `ResponseCache::parametriPermisi()`: fiecare pereche din query e un parametru permis, cu cheia scrisă cum o citește PHP (fără chei
  repetate, perechi fără cheie, chei codate, tăiate sau schimbate de `parse_str`). Linkurile site-ului (`http_build_query` din
  `$_GET`) au mereu forma asta; adresa mai poate diferi de cheie doar prin ordinea perechilor și prin felul în care sunt codate
  aceleași valori, adică tot aceeași adresă.

Cererile obișnuite merg exact ca înainte: aceleași pagini, aceleași chei, aceleași intrări (și cu altă ordine a parametrilor, cu un
„&” în plus sau cu valori codate). Schimbări voite, doar pe adrese pe care browserele și linkurile site-ului nu le trimit: o adresă
cu „#” (browserul nu trimite fragmentul) primește 301-ul stratului SEO spre calea curată fără textul de după „#”, iar fără
reparație își primește pagina, fără cache; la fel forma absolută (`http://gazda/cale`, pe care browserele o trimit doar unui
proxy), o adresă cu octeți de control și una cu un query stricat. Sonda (cereri scrise întocmai, pe php -S și printr-un server
simulat care păstrează „#” în query; fiecare intrare comparată cu pagina curată a cheii ei și cu `$_GET`): pe codul de dinainte, pe
pachetul demo, 9 intrări otrăvite și 23 de chei diferite de `$_GET`, iar cu o campanie cu banner pornită 19 intrări otrăvite; pe
codul nou 0, 0 și 0, iar fiecare adresă obișnuită se scrie și se servește din cache ca înainte.

La deploy: o intrare stricată scrisă înaintea lui rămâne până la expirarea ei (10 minute la cache-ul extins, TTL-ul
regulii la cel clasic); golirea cache-ului de pagini imediat după deploy (orice salvare reușită în admin o face,
`public/index.php`, `ResponseCache::schimbaContinut`) le scoate pe toate.

Testele: `tools/dev/test-seo.php`, A (fără server: `adresaCuCheie`, `queryCaServerul`, `parametriPermisi` și tăierea adresei de
către stratul SEO, pe adrese cu „#”, forma absolută, octeți de control, chei repetate) și `tools/dev/lib/test-seo-viteza.php`, E4.2
(pe server, cu adresa trimisă întocmai, `CURLOPT_REQUEST_TARGET`: fiecare „#” cu o reparație a stratului SEO, și cu HEAD, dă 301
spre calea curată fără query și scrie doar intrarea paginii fără parametri, octet cu octet cea curată, iar
`/magazin?sort=price_desc` rămâne MISS, sortată price_desc; „#” fără reparație, forma absolută, o cheie repetată, „=…”: pagina, fără
cache; HEAD pe adresele fără cale: 404, nicio intrare), în poarta G-seo. Pe codul de dinainte verificările noi pică (6 din 334):
`/magazin?sort=price_desc` venea HIT, cu lista sortată alfabetic.

## Builderul newsletterului: textul scris pe pânză și încărcarea pozelor (26 noiembrie 2026)

Builderul „C – clasic, curățat” ([PLUGINURI.md](PLUGINURI.md), „Newsletter”) scrie textul direct pe pânză și încarcă poze în
Galerie. Ce l-a făcut sigur:

- **Textul se curăță la randare, ca înainte, mai strict.** Conținutul blocurilor rămâne text cu etichetele editorului; serverul
  (`NewsletterService::allowRichTags`) scapă totul și redă doar `<b>`, `<i>`, `<u>`, `<s>`, `<strike>`, `<del>`, `<strong>`, `<em>`,
  `<a href="…">` și, nou, `<span style="color:#rgb…#rrggbbaa">` (culoarea din bara de text), exact în această formă. Nou: adresa unui
  link trebuie să fie fără schemă (o cale, „#”, un `{{token}}`) sau http(s), mailto, tel; `javascript:`, `data:` și orice altă
  schemă (și cu spații, octeți de control sau majuscule) devine „#” (înainte, `<a href="javascript:…">` dintr-un text ajungea ca
  atare în email și în previzualizare). Un link cu „&” în adresă e recunoscut (înainte rămânea text). Într-un bloc Text, un link
  pus de editor nu mai e luat drept adresă de transformat în link (ieșea HTML stricat).
- **Pânza desenează textul cu aceleași reguli** (aceeași funcție, în JavaScript), deci un text scris în panou cu `<img onerror>`,
  `<b onmouseover>` sau `javascript:` nu rulează pe pagina adminului (înainte, `safeRich` lăsa atributele etichetelor permise).
  Lipirea pe pânză aduce doar text simplu; ce se scrie pe pânză trece înapoi în bloc printr-o funcție care păstrează doar
  etichetele de mai sus. Previzualizarea rămâne în cadrul izolat (N1a).
- **Încărcarea pozelor din builder** (`POST /admin/gallery/bulk-upload` cu `raspuns=json`) folosește aceeași verificare a fișierului
  ca Galeria (`handleMediaUpload`: extensia, conținutul, numele), doar pentru poze (JPG, PNG, WebP, GIF), și cere jetonul `AdminCsrf`
  (antetul `X-CSRF-Token`; altfel 403), pe lângă garda originii (N2) și dreptul rolului la Galerie. Răspunde JSON, fără
  redirecționare. Încărcarea din pagina Galerie rămâne neschimbată.
- **Ruta nouă** `GET /admin/emails/builder/produse` (JSON) cere un admin logat cu acces la newslettere sau la emailurile magazinului
  (garda rutelor și `guard()`); arată doar produse active.

Testele: `tools/dev/test-newsletter.php`, H8 (curățarea pe server, și prin previzualizare), H9–H10 (ruta produselor, încărcarea:
fără jeton, cu alt jeton, un fișier care nu e poză, un PDF, fără admin) și G7–G9 în Chromium (scrisul și lipirea pe pânză, codul
din panou care nu rulează, pozele trase și lipite).

## Automatizările newsletterului (26 noiembrie 2026)

Pluginul `automatizari` ([PLUGINURI.md](PLUGINURI.md), „Automatizări newsletter”). Ce îl ține sigur:

- **Adminul**: fiecare pagină și acțiune trece de garda adminului (`Auth::canAccessPath`: administratorul general și
  „Administrator email-uri”) și de garda pluginului; fiecare scriere e POST cu jetonul formularului (`AdminCsrf`: câmpul `_csrf`
  sau antetul `X-CSRF-Token`; fără el, 419 la JSON sau mesajul „Sesiunea formularului a expirat”). Editorul primește datele într-un
  `<script type="application/json">` codat cu `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, iar scriptul pune
  totul în pagină cu `textContent` / `createElement` (niciun text din date ca HTML). Arborele fluxului se verifică din nou pe server
  (tipuri, chei, adâncime, emailurile doar ale aceleiași automatizări). Previzualizarea emailului din panou vine izolată, ca „Vezi”
  din Emailuri trimise: `Content-Security-Policy: sandbox`, într-un `<iframe sandbox="">`. Pasul „Cod de reducere” face cupoane ale
  magazinului: o salvare care adaugă unul sau îi schimbă setările cere și dreptul la Cupoane (`Auth::canAccessPath('/admin/coupons')`),
  deci „Administrator email-uri” nu poate face coduri (de exemplu 90% pentru o adresă a lui); altfel 403, nimic salvat.
- **Emailurile**: codurile (`{{prenume}}` …) se scapă la înlocuirea în HTML; conținutul trece prin același randat ca newsletterul
  (`NewsletterService::renderHtmlFromBlocks`, cu `allowRichTags` și linkurile filtrate). Subsolul de dezabonare e obligatoriu, cu
  linkul semnat al abonatului.
- **Linkurile de urmărire**: semnate HMAC-SHA256 cu cheia secretă a newsletterului (`NewsletterService::semneaza`; doar cheile noi,
  nu cea veche, ghicibilă), pe un mesaj cu prefixul lor (`automatizare|d|…`, `automatizare|c|<trimitere>|<adresa>`), deci nu pot
  semna altceva și o semnătură nu merge pentru altă trimitere. Clicul duce doar la adresa semnată (altfel pagina 404: nicio
  redirecționare deschisă), un link relativ în site, altă schemă decât http(s) la prima pagină; nu pune niciun cookie de urmărire.
- **Datele personale**: jurnalul automatizărilor nu are adrese de email (id-uri, pași, motive); erorile din jurnalul PHP doar clasa
  și locul, fără date. Doar abonații activi intră, iar refuzul urmăririi (`fara_urmarire`) e respectat. Pe copia de verificare
  tabelele vin doar cu structura.
