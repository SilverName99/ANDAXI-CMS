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
