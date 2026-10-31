# Arhitectura adminului: ce pagini are și cum le așezăm

Faza D a Etapei 1 reface tot adminul în varianta **„B — Compact, pentru lucru intens”** (machetele din
[ui/](ui/README.md)). Înainte de pagini, decizia ta din 27 octombrie (B6) cere o trecere prin **toate** paginile:
ce e împrăștiat se adună, ce e amestecat se desparte, ce stă în locul greșit se mută. Documentul acesta e
trecerea aceea: inventarul de azi, meniul nou și propunerile care au nevoie de „da”-ul tău.

**Ce se schimbă oricum (fără să decizi nimic):** meniul din stânga se regrupează în 8 grupuri (mai jos, „Meniul
nou”). Paginile rămân aceleași, la aceleași adrese, cu aceleași butoane și câmpuri; se mută doar locul lor în
meniu. Asta intră acum (WP-30).

**Decizia ta (31 octombrie):** IA-01 … IA-10 aprobate, **făcute în WP-35** (secțiunea „Ce s-a făcut”, mai jos); IA-11
(pagina de rapoarte a vânzărilor) și IA-12 (lista coșurilor abandonate) amânate: nu se fac acum.

Pentru oricine se uită după noi: nicio propunere nu schimbă numele câmpurilor sau ce trimite un formular (contractul
adminului, `tools/dev/contract-admin.php`, poarta G-admin; partea D din `tools/dev/test-admin-faza-d.php`). O pagină
împărțită își păstrează adresa veche (cu un tab ales), o pagină unită își păstrează ambele adrese, iar o adresă mutată trimite
la noua ei adresă, ca linkurile salvate, obiceiurile oamenilor și legăturile cu ERP-ul sau aplicațiile să meargă în continuare.

## Grupurile meniului nou

| Grup | Ce găsești acolo, pe scurt |
|---|---|
| **Acasă** | Ce e de făcut azi: cifrele zilei, graficul pe 14 zile, comenzile recente, lista „De făcut”, stocul scăzut, rândul de sănătate (refăcută în WP-32) |
| **Comenzi** | Comenzile magazinului (din WP-33: tab-uri pe status, filtre, panoul comenzii din dreapta, acțiunile în masă cu rezultatul pe comandă) și cererile de ofertă (pe un site de prezentare, cererile sunt „comenzile”) |
| **Produse** | Produsele și tot ce ține de pagina unui produs: categorii, câmpuri, șabloane, recenzii, produse promoționale (editorul produsului refăcut pe secțiuni în WP-34) |
| **Clienți** | Oamenii: conturile clienților, setările conturilor, acordurile GDPR semnate, mesajele din formularul de contact |
| **Marketing** | Ce aduce vânzări: cupoane, puncte de fidelitate, newsletter (campanii, șabloane, liste, formulare), emailurile automate |
| **Conținut** | Ce se vede pe site în afară de produse: pagini, blog, galeria de poze, antetul, subsolul și meniul site-ului |
| **Rapoarte** | Ce s-a întâmplat: jurnalul adminilor, încercările de înregistrare și comandă, statisticile newsletterului, emailurile trimise |
| **Setări** | Cum funcționează site-ul: date generale, plăți, livrare, ERP, coș flotant, manechin, emailurile de comandă și trimiterea lor, Google, administratori |

Un grup fără nicio pagină pe un site nu apare (ca azi). Un grup cu o singură pagină apare ca un singur link,
cu numele paginii: de exemplu pe grafoanaytis grupul „Comenzi” are doar „Cereri de ofertă”, deci în meniu scrie
„Cereri de ofertă”. Fiecare intrare se vede doar dacă rolul adminului are voie la ea (ca azi).

## Ce s-a făcut (WP-35, 31 octombrie)

| # | Cum arată acum | Adresele |
|---|---|---|
| IA-01 | „Email-uri” e împărțită după cine o folosește; fiecare bucată are titlul ei și doar tab-urile ei: **Newsletter** (Newslettere, Șabloane newsletter, Liste abonați, Formulare opt-in), **Emailuri de comandă** (Emailuri de comandă, Setări trimitere, Trimite email test; fără magazin se numește „Trimiterea emailurilor”), **Mesaje de contact**, **Emailuri trimise** (Emailuri trimise, Statistici newsletter), **Automatizări**. | Aceleași: `/admin/emails/newsletters?tab=…`, `/admin/emails/{sender,test,automation}` |
| IA-02 | O pagină **Câmpuri și șabloane**, cu tab-urile „Câmpuri suplimentare” și „Șabloane produse”; o singură intrare în meniu. | Ambele rămân: `/admin/products/fields`, `/admin/products/templates` (fiecare cu tab-ul ei) |
| IA-03 | **Setări magazin / site** în șase tab-uri: General (numele în titluri, paginile, favicon, culoarea adminului, mentenanța), Magazin (TVA, numerotarea, cantitatea, sertarul „Oferte”; „Produse” pe un catalog fără magazin), SEO și Google, Cookie și popup, Viteză, Chat live. Fiecare casetă se salvează ca înainte; după salvare rămâi în tab. | `/admin/settings/store?tab=general|magazin|seo|cookies|viteza|chat`; cele vechi (`?tab=tva`, `?tab=caching` …) deschid tab-ul nou la caseta lor |
| IA-04 | Pagina **Google** e caseta „Google” din tab-ul „SEO și Google”; fără intrare în meniu. | `/admin/settings/google` → `/admin/settings/store?tab=google` (302); formularul postează tot la `/admin/settings/google` |
| IA-05 | Pagină nouă **Oferte BBD** (Produse): toate ofertele, după data de valabilitate, cu căutare și stări (valabile, oprite, expirate); doar citire, „Deschide produsul” duce la editor. Doar cu pluginul oferte BBD. | `/admin/products/oferte-bbd` (nouă) |
| IA-06 | O pagină **Cupoane**, cu tab-urile „Cupoane” și „Coduri unice”; în meniu doar „Cupoane”. | Ambele rămân: `/admin/coupons`, `/admin/coupons/unique` |
| IA-07 | **Fidelitate** ține programul, în tab-uri (Program, Reguli promo, Puncte clienți, Toate tranzacțiile, Import); istoricul punctelor unui client e în **fișa lui** (Clienți → clientul → „Puncte de fidelitate”). | `/admin/users/points?tab=…` (vechile `?panel=…` deschid tab-ul lor); `/admin/users/settings?tab=points` (unde se întorc salvările) → `/admin/users/points` |
| IA-08 | **Setări livrare** în două tab-uri: „Livrare” și „Curier FAN”; contul FAN se salvează împreună cu livrarea, din oricare tab. | `/admin/settings/shipping?tab=livrare|fan`; cele vechi (`?tab=fanbox` …) deschid tab-ul nou |
| IA-09 | **Setări plăți** cu un tab pe fiecare fel de plată din abonament: Ordin de plată, Card – EuPlătesc, Card – Stripe (sau „Stripe”, fără magazin), Banca Transilvania. | `/admin/settings/payments?tab=op|euplatesc|stripe`, `?tab=bt` ca înainte |
| IA-10 | Numele noi din meniu (lista de mai jos). | — |

## Căutarea și scurtăturile (WP-36, 31 octombrie)

Căutarea de sus merge din orice pagină: un clic pe ea sau **Ctrl K** (pe Mac ⌘ K). Găsește comenzile (după număr, nume, email),
produsele (după nume sau cod), clienții, paginile, postările, paginile și tab-urile din admin (de exemplu „tva”, „Curier FAN”,
„cookie”) și câteva acțiuni (produs nou, pagină nouă, postare nouă, coșurile de gunoi). Fiecare admin vede doar ce poate deschide:
cel de magazin comenzi și produse, cel de blog și cel de emailuri postările; nimic al unui modul pe care site-ul nu-l are. O
comandă găsită se deschide direct în panoul ei; nicio acțiune nu se face din căutare.

**„G”, apoi o literă**, duce la un grup din meniu (prima lui pagină pe care o vezi): A Acasă, C Comenzi, P Produse, L Clienți,
M Marketing, T Conținut, R Rapoarte, S Setări. Lista întreagă e la „?”, doar cu grupurile pe care le are site-ul și rolul tău.
Nicio scurtătură nu pornește cât scrii într-un câmp.

## Meniul de acum (după WP-35)

- **Acasă**
- **Comenzi**: Comenzi · Cereri de ofertă
- **Produse**: Produse · Categorii produse · Câmpuri și șabloane · Oferte BBD · Recenzii · Produse promoționale
- **Clienți**: Clienți · Conturi clienți · Acorduri GDPR · Mesaje de contact
- **Marketing**: Cupoane · Fidelitate · Newslettere · Șabloane newsletter · Liste abonați · Formulare opt-in · Automatizări
- **Conținut**: Pagini · Postări blog · Categorii · Șabloane blog · Autori blog · Galerie · Antet, subsol și meniu
- **Rapoarte**: Jurnal activitate · Încercări de înregistrare și comandă (fără magazin: „Încercări de înregistrare”) ·
  Statistici newsletter · Emailuri trimise
- **Setări**: Setări magazin (Setări site fără magazin) · Setări plăți · Setări livrare · ERP ANDAXI · Coș flotant · Manechin ·
  Emailuri de comandă · Setări trimitere · Trimite email test · Administratori · Abonamentul tău

## Inventarul de dinainte de WP-35 (cu meniul din WP-30)

Site-uri: **B** = Bioscem, **BV** = Biovitality, **VT** = vertij, **GR** = grafoanaytis (după licențele lor de
dezvoltare, `config/plugins.php` → `licente_dev`). „Toate” = orice site (nucleul).

### Paginile din meniu

| Azi în meniu | Pagina (adresa) | Ce face | Plugin | Site-uri | Grupul nou |
|---|---|---|---|---|---|
| Dashboard | Dashboard (`/admin`) | cifre, comenzi și utilizatori recenți (doar adminul general) | nucleu | toate | **Acasă** (eticheta devine „Acasă”) |
| Utilizatori | Listă utilizatori (`/admin/users`; importul: `?panel=import`; un client: `/admin/users/{id}`) | conturile clienților, importul din CSV/XLSX și din backup WordPress | conturi_clienti | B, BV, VT | **Clienți** |
| Utilizatori | Setări utilizatori (`/admin/users/settings`) | paginile contului, login cu Google, câmpurile formularului de înregistrare | conturi_clienti | B, BV, VT | **Clienți** |
| Utilizatori | Puncte fidelitate (`/admin/users/points`, panourile `claimed`/`unclaimed`) | setările programului, regulile promo, importul, istoricul punctelor fiecărui client | fidelitate | B, BV | **Marketing** |
| Utilizatori | Securitate formulare (`/admin/users/security`) | jurnalul încercărilor de înregistrare și de comandă (protecția anti-robot) | magazin sau conturi_clienti | B, BV, VT | **Rapoarte** |
| Utilizatori | Acorduri GDPR (`/admin/users/gdpr-agreements`; aceeași pagină și la `/admin/pages/gdpr-agreements`) | acordurile semnate, exportul, datele operatorului | acorduri_gdpr | B, BV | **Clienți** |
| Magazin | Produse (`/admin/products`, `/new`, `/trash`) | lista și editorul produselor (editorul se deschide peste listă) | catalog | B, BV, GR | **Produse** |
| Magazin | Categorii produse (`/admin/categories`) | categoriile și SEO-ul mărcilor | catalog | B, BV, GR | **Produse** |
| Magazin | Câmpuri suplimentare (`/admin/products/fields`) | câmpurile în plus ale produselor | catalog | B, BV, GR | **Produse** |
| Magazin | Template-uri produse (`/admin/products/templates`, editorul `/{id}/builder`) | șabloanele paginii de produs | catalog | B, BV, GR | **Produse** |
| Magazin | Cereri de ofertă (`/admin/cereri-oferta`) | cererile primite din butonul „Cere ofertă” | cereri_oferta | GR | **Comenzi** |
| Magazin | Comenzi (`/admin/orders`, coșul de gunoi `/trash`) | lista comenzilor, fereastra unei comenzi (FAN, BT, ERP, link de plată, promo, precomandă), acțiunile în masă | magazin | B, BV | **Comenzi** |
| Magazin | Cupoane (`/admin/coupons`) | cupoanele cu cod | cupoane | B, BV | **Marketing** |
| Magazin | Cupoane unice (`/admin/coupons/unique`, `?view=active/used`) | codurile unice (importate, generate) | cupoane | B, BV | **Marketing** |
| Magazin | Recenzii (`/admin/products/reviews`) | aprobarea recenziilor, filtrul de spam, importul | recenzii | B, BV | **Produse** |
| Magazin | Produse promoționale (`/admin/promo-products`) | produsele date cadou la comenzi și raportul lor | produse_promo | B, BV | **Produse** |
| Magazin | Coș flotant (`/admin/settings/floating-cart`) | aspectul și comportamentul coșului care plutește pe site | magazin | B, BV | **Setări** |
| Configurare | Setări magazin / Setări site (`/admin/settings/store`) | 14 secțiuni: sitemap, favicon, numele în titluri, SEO global, Clarity, chat live, mentenanță, TVA, numerotarea comenzilor, controlul cantității, sertarul „Oferte”, cache, bannerul de cookie, popupul de anunț | nucleu | toate | **Setări** |
| Configurare | Setări livrare (`/admin/settings/shipping`) | contul FAN, prețurile și regulile de livrare, importurile de localități, străzi, km în plus, FANbox, adresele de ridicare | livrare_fan | B, BV | **Setări** |
| Configurare | Setări plăți (`/admin/settings/payments`, BT: `?tab=bt`) | ordinul de plată, EuPlătesc, Stripe (și webhook-ul), Banca Transilvania | magazin sau o plată | B, BV, VT | **Setări** |
| Configurare | ERP ANDAXI (`/admin/settings/erp`) | legătura cu ERP-ul, reîncercările, ce se întâmplă la aprobare | erp | B, BV | **Setări** |
| Configurare | Configurare manechin (`/admin/settings/mannequin`) | manechinul și punctele lui | manechin | B, BV | **Setări** |
| Configurare | Google (`/admin/settings/google`) | verificarea proprietății site-ului | nucleu | toate | **Setări** |
| Configurare | Administratori (`/admin/settings/admins`) | conturile de admin și rolurile lor | nucleu | toate | **Setări** |
| (singur) | Jurnal activitate (`/admin/activity-log`) | ce a făcut fiecare admin | nucleu | toate | **Rapoarte** |
| (singur) | Pagini (`/admin/pages`, `/new`, `/{id}/edit`, `/trash`) | paginile site-ului, cu editorul de cod | nucleu | toate | **Conținut** |
| (singur) | Galerie (`/admin/gallery`, `?view=all/folders`) | pozele și fișierele încărcate, pe dosare | nucleu | toate | **Conținut** |
| (singur) | Header/Footer/Meniu (`/admin/design`, `?section=`) | antetul, subsolul și meniul site-ului | nucleu | toate | **Conținut** |
| Blog | Postări blog (`/admin/blog/posts`, `/new` (pasul 1), `/editor?post=`, `/trash`) | articolele, editorul, importul | blog | B, BV, VT | **Conținut** |
| Blog | Categorii (`/admin/blog/categories`) | categoriile articolelor | blog | B, BV, VT | **Conținut** |
| Blog | Template blog (`/admin/blog/templates`, editorul `/{id}/builder`) | șabloanele articolelor | blog | B, BV, VT | **Conținut** |
| Blog | Autori blog (`/admin/blog/authors`) | autorii articolelor | blog | B, BV, VT | **Conținut** |
| Setări email | Setări trimitere (`/admin/emails/sender`) | de la ce adresă și prin ce se trimit emailurile | nucleu | toate | **Setări** |
| Setări email | Trimite email test (`/admin/emails/test`) | un email de probă | nucleu | toate | **Setări** |
| Automatizări | Automatizări (`/admin/emails/automation`) | emailurile automate (coșul abandonat) | magazin | B, BV | **Marketing** |
| Email-uri | Template-uri (`/admin/emails/newsletters?tab=templates`) | șabloanele newsletterului | newsletter | B, BV, VT | **Marketing** |
| Email-uri | — (doar tab) | Newslettere (`?tab=campaigns`): campaniile, cu pașii Conținut → Destinatari → Verificare → Trimite | newsletter | B, BV, VT | **Marketing** (intrare nouă în meniu: azi se ajunge doar din tab-uri) |
| Email-uri | Email-uri ecommerce (`?tab=ecommerce`) | textele emailurilor de comandă | magazin | B, BV | **Setări** |
| Email-uri | Liste abonați (`?tab=subscribers`) | abonații newsletterului, pe liste | newsletter | B, BV, VT | **Marketing** |
| Email-uri | Formulare opt-in (`?tab=optin`) | formularele de abonare | newsletter | B, BV, VT | **Marketing** |
| Email-uri | Formulare contact (`?tab=contact_forms`) | mesajele primite și cine le primește | nucleu | toate | **Clienți** |
| Email-uri | Statistici (`?tab=stats`) | deschideri și clicuri pe campanii | newsletter | B, BV, VT | **Rapoarte** |
| Email-uri | Istoric trimitere (`?tab=history`) | toate emailurile trimise, cu retrimiterea | nucleu | toate | **Rapoarte** |

### Paginile la care se ajunge doar din butoane

Rămân unde sunt, sub pagina lor: coșurile de gunoi (produse, comenzi, pagini, postări), produs nou, pagină nouă,
postare nouă (pasul 1, alegerea șablonului), editorul unei postări, editorul unui șablon de produs sau de blog,
constructorul de emailuri (`/admin/emails/builder`: șablon, newsletter, campanie, email de comandă, formular),
fișa unui client, editarea unui cupon (`?coupon=`), exporturile (comenzi, produse, acorduri, produse promoționale,
localități). Fără meniu: pagina de login și „Modulul nu este inclus în abonament” (o adresă a unui modul pe care
site-ul nu-l are).

### Pluginurile fără pagină proprie

| Plugin | Unde se vede |
|---|---|
| precomenzi, oferte BBD | în editorul produsului și în fereastra comenzii |
| link de plată, plata BT, Stripe, EuPlătesc | în Setări plăți și în fereastra comenzii |
| livrare FAN | în Setări livrare și în fereastra comenzii (AWB, urmărire, FANbox) |
| ERP | în ERP ANDAXI și în fereastra comenzii |
| chat live | o secțiune în Setări magazin |
| migrare din WordPress | importul din backup în Listă utilizatori (unealtă internă ANDAXI) |
| donații, membri, fișiere în cloud, analiza concurenței, pachete de produse | nu sunt încă aduse în codul comun; paginile lor se fac direct în stilul nou (Etapa 4, OD-40) |

## Meniul din WP-30 (înainte de etichetele IA-10)

Doar locul paginilor în meniu; paginile, adresele și butoanele rămân. Ordinea din fiecare grup:

- **Acasă**
- **Comenzi**: Comenzi · Cereri de ofertă
- **Produse**: Produse · Categorii produse · Câmpuri suplimentare · Template-uri produse · Recenzii · Produse promoționale
- **Clienți**: Listă utilizatori · Setări utilizatori · Acorduri GDPR · Formulare contact
- **Marketing**: Cupoane · Cupoane unice · Puncte fidelitate · Newslettere · Template-uri · Liste abonați · Formulare opt-in · Automatizări
- **Conținut**: Pagini · Postări blog · Categorii · Template blog · Autori blog · Galerie · Header/Footer/Meniu
- **Rapoarte**: Jurnal activitate · Securitate formulare · Statistici · Istoric trimitere
- **Setări**: Setări magazin (Setări site fără magazin) · Setări plăți · Setări livrare · ERP ANDAXI · Coș flotant ·
  Configurare manechin · Email-uri ecommerce · Setări trimitere · Trimite email test · Google · Administratori ·
  Abonamentul tău (WP-32: modulele site-ului, din licență; doar administratorul general)

Ce vede fiecare site (cu pluginurile lui de azi):

| Site | Grupuri |
|---|---|
| Bioscem, Biovitality | toate cele 8 |
| vertij | Acasă · Clienți (Listă utilizatori, Setări utilizatori, Formulare contact) · Marketing (Newslettere, Template-uri, Liste abonați, Formulare opt-in) · Conținut · Rapoarte · Setări (Setări site, Setări plăți, Setări trimitere, Trimite email test, Google, Administratori) |
| grafoanaytis | Acasă · Cereri de ofertă (un singur link) · Produse (Produse, Categorii, Câmpuri, Template-uri) · Formulare contact (un singur link) · Conținut (Pagini, Galerie, Header/Footer/Meniu) · Rapoarte (Jurnal activitate, Istoric trimitere) · Setări |

De ce așa, pe scurt:

- **Comenzi separat de produse**: comenzile se lucrează zilnic, produsele rar; machetele le pun separat.
- **Recenziile și produsele promoționale la Produse**: sunt despre produse (macheta „Produs” le pune acolo).
- **Cupoanele, punctele și newsletterul la Marketing**: toate aduc clienți înapoi; azi stăteau la Magazin și la Utilizatori.
- **„Newslettere” apare în meniu**: azi trimiterea unei campanii se găsea doar din tab-urile paginii de emailuri.
- **Jurnalele și statisticile la Rapoarte**: sunt de citit, nu de configurat; azi erau împrăștiate în 3 grupuri.
- **Coșul flotant, emailurile de comandă și trimiterea emailurilor la Setări**: se configurează o dată și rămân.
- **Formularul de contact la Clienți**: e cutia cu mesajele oamenilor, nu o setare.

## Propunerile (aprobate 31 octombrie: IA-01 … IA-10; IA-11, IA-12 amânate)

Fiecare: ce facem, de ce (un rând), când. Recomandarea noastră e „da” la toate; cele marcate „opțional” se pot
lăsa pe mai târziu fără să strice nimic.

| # | Propunerea | De ce | Când |
|---|---|---|---|
| **IA-01** | **Împărțim pagina „Email-uri”** (azi 8 tab-uri amestecate) în: Marketing › Newsletter (Newslettere, Template-uri, Liste abonați, Formulare opt-in), Setări › Emailuri de comandă (Email-uri ecommerce, Setări trimitere, Trimite email test), Clienți › Mesaje de contact, Rapoarte › Emailuri trimise și Statistici. Fiecare bucată își vede doar tab-urile ei. | o singură pagină face azi patru treburi diferite, pentru oameni diferiți | WP-35 (emailurile) |
| **IA-02** | **Unim „Câmpuri suplimentare” și „Template-uri produse”** într-o pagină „Câmpuri și șabloane”, cu două tab-uri (cum arată macheta) | amândouă hotărăsc cum arată pagina unui produs și se folosesc împreună | WP-35 |
| **IA-03** | **Împărțim „Setări magazin”** (14 secțiuni) în tab-uri: General (numele, favicon, mentenanță), Magazin (TVA, numerotarea comenzilor, controlul cantității, sertarul „Oferte”), SEO și Google (SEO global, sitemap, Clarity, plus pagina „Google”, IA-04), Cookie și popup (bannerul, popupul de anunț), Viteză (cache), Chat live | e cea mai lungă pagină și amestecă lucruri fără legătură; azi cauți mult | WP-35 (setările) |
| **IA-04** | **Unim pagina „Google”** (verificarea proprietății) cu SEO-ul global, în tab-ul „SEO și Google” de la IA-03 | sunt aceeași treabă (Google), azi în două pagini | WP-35 |
| **IA-05** | **Pagină nouă „Oferte BBD”** în Produse: toate ofertele BBD (termen scurt de valabilitate) din toate produsele, într-o listă | azi o ofertă se vede doar deschizând fiecare produs; macheta „Produs” o are | WP-34/35 (doar cu pluginul oferte BBD) |
| **IA-06** | **Unim „Cupoane” și „Cupoane unice”** într-o pagină „Cupoane”, cu tab-urile „Cupoane” și „Coduri unice” | sunt același lucru pentru client (un cod la casă), azi în două pagini | WP-35 |
| **IA-07** | **Împărțim „Puncte fidelitate”**: setările programului și regulile rămân în Marketing › Fidelitate; istoricul punctelor unui client trece în fișa clientului (Clienți) | pagina (750 de rânduri) amestecă setările programului cu istoricul fiecărui om | WP-35 |
| **IA-08** | **Împărțim „Setări livrare”** în: Livrare (prețuri, reguli, transport gratuit, adrese de ridicare) și Curier FAN (contul FAN, importurile de localități, străzi, km, FANbox) | prețurile se schimbă des, nomenclatoarele FAN aproape niciodată; azi sunt pe aceeași pagină lungă | WP-35 (doar cu livrare FAN) |
| **IA-09** | **Setări plăți cu un tab pe fiecare fel de plată** (Ordin de plată, Card – EuPlătesc, Card – Stripe, Banca Transilvania), doar cele din abonament | azi BT e un tab separat, iar restul o pagină lungă | WP-35 |
| **IA-10** | **Nume mai clare în meniu**: „Listă utilizatori” → „Clienți”, „Setări utilizatori” → „Conturi clienți”, „Header/Footer/Meniu” → „Antet, subsol și meniu”, „Template-uri produse” → „Șabloane produse”, „Template blog” → „Șabloane blog”, „Template-uri” (newsletter) → „Șabloane newsletter”, „Email-uri ecommerce” → „Emailuri de comandă”, „Istoric trimitere” → „Emailuri trimise”, „Securitate formulare” → „Încercări de înregistrare și comandă”, „Configurare manechin” → „Manechin” | numele de azi amestecă engleza și româna și nu spun ce e în pagină | WP-35 (sau imediat, dacă vrei: e doar text) |
| **IA-11** *(opțional)* | **Pagină nouă „Rapoarte”** cu vânzările pe perioade (azi, săptămâna, luna; pe produse și pe plată) | macheta are „Rapoarte” ca pagină; azi nu există nicio pagină de vânzări | nu e în planul Etapei 1: dacă spui „da”, intră ca un pachet nou, după faza D |
| **IA-12** *(opțional)* | **„Coșuri abandonate” ca listă** în Comenzi (azi se văd doar ca număr și ca emailuri automate) | ca să vezi cine a lăsat ce în coș și să suni la nevoie | după WP-33; doar cu magazinul |

Ce **nu** propunem: să mutăm paginile la alte adrese, să scoatem pagini, să schimbăm ce face o pagină sau cine
are voie la ea. Rolurile (magazin, blog, emailuri) rămân exact cum sunt.

## Pentru dezvoltatori

- Meniul: `config/plugins.php` → `meniu_admin` (cele 8 grupuri, cheile `acasa`, `comenzi`, `produse`, `clienti`,
  `marketing`, `continut`, `rapoarte`, `setari`) și intrările din `meniu` ale fiecărui plugin (câmpul `grup`).
  Un grup cu o singură intrare vizibilă se scrie ca link (`views/admin/layout.php`). Instantaneele rutelor
  (`tools/dev/instantanee/rute-*.txt`, G-rute) arată meniul fiecărei licențe.
- Căutarea (WP-36): `App\Support\CautareAdmin` (sursele, permisiunile, interogările, literele „G”), `GET /admin/cauta`
  (`CautareAdminController`), `public/assets/js/admin/cautare.js` (fereastra) și `cadru.js` („G, apoi litera”). O pagină nouă
  din meniu intră singură în căutare și, dacă e prima a grupului ei, în „G <literă>”; un tab nou de setări se adaugă în
  `CautareAdmin::paginiAdmin()`, cu pluginul lui.
- O propunere aprobată se face în WP-ul paginii, cu componentele din WP-31 (`views/admin/componente/`), iar diferențele
  ei de contract se declară în `tools/dev/lib/contract-admin-declarat.php` (`$contractAdminPorturi`, sub-tabloul WP-35). O
  pagină împărțită în tab-uri păstrează adresa veche ca tab ales (alias-urile din `adm_tab_ales()`); o pagină unită păstrează
  ambele adrese, fiecare cu tab-ul ei; o pagină mutată trimite la noua adresă (302), iar G-admin o compară cu noua adresă
  (`contract_mutata`). Tab-urile unei pagini de setări: [ui/README.md](ui/README.md) și DEZVOLTARE.md („Adminul refăcut”).
- Inventarul de mai sus e și lista paginilor contractului: `tools/dev/lib/pagini-admin.php`.
