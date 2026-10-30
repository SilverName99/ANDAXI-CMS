# De portat: schimbările din codurile vechi care trebuie să ajungă în ANDAXI-CMS

Din prima zi a Etapei 1, fiecare commit nou pe cele patru ramuri vechi primește aici un rând și ajunge
în codul comun în aceeași săptămână. Lista unui site trebuie să fie goală (doar „portat” sau
„nu se portează”) în ziua în care site-ul trece pe codul comun. Reparațiile din Etapa 0b intră și ele aici.
Funcții noi pe codurile vechi se fac doar cu acordul proprietarului.

Verificarea săptămânală: `tools/dev/php.sh tools/dev/de-portat.php` (listează commit-urile de după
„ultimul commit revizuit” de mai jos; nu face `git fetch`, deci întâi `git -C <clonă> fetch`).

„Ultimul commit revizuit” al Bioscem e și **baza comparației** `tools/dev/compara-cu-baza.sh`:
codul comun trebuie să dea același HTML ca acel commit al Bioscem. Un commit Bioscem portat
mută rândul de mai jos la el, apoi comparația trebuie să iasă cu 0 fără nicio declarație pentru
port (se pot declara doar diferențe `$deBioscem`, cu motivul lor; `$porturiBioscem` nu se aplică
față de Bioscem). Comparația istorică, `--baza=f5ae5ea` (poarta G-cmp-istoric, și la WP-R), primește
în schimb, în același commit cu portul, diferențele lui față de acel commit în `$porturiBioscem`,
pe commit-uri Bioscem, cu pagina (sau paginile) și numărul exact de apariții.

| Site | Repo, ramura | Ultimul commit revizuit | Punctul de plecare (fork) |
|---|---|---|---|
| Bioscem | `Nou-site-bioscem`, `claude/migrate-bioscem-site-bz38y4` | `070e1af` (21.10.2026) | CMS pornește din `11b1040` |
| Biovitality | `Mutare-Site-Biovitality`, `claude/migrate-bioscem-site-bz38y4` | `779e0d8` (21.10.2026) | Bioscem s-a desprins din BV `f7d15ba` (10.08.2026) |
| vertij | `vertij-tinitus-refacut`, `claude/site-dashboard-updates-3qcr5b` | `0fc2b2c` (20.10.2026) | import din BV `b792224` în `6d48cd8` (29.06.2026) |
| grafoanaytis | `site-nou-grafoanaytis.ro`, `claude/client-website-rebuild-e5o9z1` | `d3c4b79` (20.10.2026) | import din BV `88b7f4d` în `87aaa90` (26.08.2026) |

## Bioscem (de la `11b1040`)

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `de5c2b0` | 19.10 | AWB: numărul de colete poate fi ales în ERP la aprobare | portat (`4915478`) |
| `80bb4a4` `678b3ac` `12a999d` `665f1da` `0df9b00` `bdec166` `6d29b37` | 19.10 | SEO livrarea 1 (robots, sitemap, adresa unică, redirecționări, date structurate, viteză, remedieri) | portat (`c2b9331`) |
| `f5eafa3` | 19.10 | Unire ramura de pe server cu remedierile SEO | portat (unire, fără cod nou) |
| `b632294` | 19.10 | Oferte BBD: cheia ofertei rămâne la salvarea produsului (Etapa 0b, C1, decizia OD-14) | portat (`62349a2`) |
| `89dcc4e` | 19.10 | Blog: editorul nu mai pierde categoriile postării; duplicarea le copiază (Etapa 0b, X1, decizia OD-14) | portat (`1df3771`) |
| `b3781a4` | 19.10 | Blog: categoria principală rămâne aceeași la re-salvare | portat (`dbd218c`) |
| `9f59e79` | 19.10 | Redirecționări: deciziile proprietarului („da la toate”), 14 reguli 301 | portat (`site-uri/bioscem/redirecturi.json`, în commit-ul care mută aici „ultimul commit revizuit”) |
| — | — | Contul arată doar comenzile legate de cont, nu și pe cele fără cont cu același email | nu se face pe Bioscem (decizia OD-47, 19.10): doar în codul comun, regula nucleului din WP-25; la trecere intră în `site-uri/bioscem/ce-se-schimba.md` |
| `6c8c789` `919357a` | 20.10 | SEO livrarea 2, A3: categoriile pe `/categorie/{slug}` (linkurile din pagini, antet, meniu și subsol; 301 de la `/magazin?category=` într-un singur salt, și pe www, cu „/” final sau majuscule; sortarea și filtrul pe pagina categoriei) | portat (`43bdccc`; `LinkuriCategorii` nou, al pluginului catalog: rescrierea antetului, meniului și subsolului doar cu catalogul pe site) |
| `93d803f` `0250480` | 20.10 | SEO livrarea 2, A6: o singură regulă pentru titluri și descrieri, câmpuri SEO pe categorii și mărci, numele magazinului din titluri | portat (`43bdccc`; `SeoMeta` nou: numele implicit, sufixul vechi „BIOSCEM” și cuvântul „harrison” mutate din constante în `site-uri/bioscem/seo.json`; câmpurile SEO ale categoriilor și mărcilor ale pluginului catalog, numele magazinului al nucleului; fără magazin, descrierile listelor nu spun prețuri) |
| `a14e803` | 20.10 | SEO livrarea 2, A8: logo-ul din antet ca WebP mic, cu PNG-ul ca rezervă | portat (`43bdccc`; `LogoAntet` citește PNG-ul, mărimea și variantele din `seo.json` → `logo_antet`; cele două WebP în `site-uri/bioscem/public/`, copiate peste `public/` la adoptare) |
| `dfcf6bd` `1e46f9f` | 20.10 | SEO livrarea 2, A5: livrarea și returul în datele structurate ale produselor și ale firmei; doar tariful livrării la adresă | portat (`43bdccc`; `PoliticaLivrare` ia serviciul, termenul și returul din `seo.json` → `livrare_retur`; pluginul magazin; un site fără ele nu declară nimic) |
| `7455b77` `6a42030` | 20.10 | SEO livrarea 2, A7: poza de distribuire 1200×630, numele site-ului și limba pentru Facebook și WhatsApp; textul alternativ al logo-ului | portat (`43bdccc`; `ImagineDistribuire`, nucleu; logo-ul implicit din `seo.json` → `distribuire.logo`) |
| `d277727` `1e46f9f` | 20.10 | SEO livrarea 2, A13: fluxul Google Merchant Center, `/feed/google-merchant.xml`; fără `identifier_exists=no`, fără precomenzi | portat (`43bdccc`; `FluxGoogleMerchant`, pluginul magazin: ruta, `Allow` în robots.txt; prefixul id-urilor din `seo.json` → `flux_merchant`; cât magazinul se oprește, canalul fără produse, imediat) |
| `8c074f1` | 20.10 | `/retur-garantie` duce la termeni și condiții (decizia proprietarului din 20 oct) | portat (`site-uri/bioscem/redirecturi.json`: 128 de reguli exacte, 118 × 301) |
| `7c561fa` | 20.10 | Textele Google pentru prima pagină, 3 categorii și 20 de produse, cu scriptul de aplicare | portat (`43bdccc`; `scripts/seo-texte.php` generic, citește `seo-texte.json` din dosarul site-ului; textele Bioscem în `site-uri/bioscem/seo-texte.json`, fără cifrele din Search Console, decizia OD-49) |
| `df21b30` | 20.10 | Pagini de text: un cuvânt îngroșat rămâne în rând (`docs/templates/pagina-text.css`) | nu se portează (`docs/templates/` nu e în CMS, decizia OD-34: tema e în baza site-ului); la fel părțile din `docs/templates/` ale lui `6c8c789` (antet, meniu, prima pagină cu linkurile `/categorie/…`) |
| `0582ce9` `333ba9e` `b874cf0` `5f2e483` `fa76c4f` `233cc7d` `865630f` `4254ca4` | 20.10 | Avertismentul pe produs (ex. „ATENȚIE – PRODUS REFRIGERAT”): cele trei câmpuri din Admin → Produse, fereastra la adăugarea în coș, caseta și bifa obligatorie la finalizarea comenzii (verificată și pe server), confirmarea pe comandă și în Admin → Comenzi, blocul din emailul de confirmare (`{{avertismente_produse}}`), oprirea `AVERTISMENT_PRODUSE=0` | portat (`43bdccc`; pluginul magazin; tabelele `product_warnings` și `order_product_warnings` le face migrarea adoptivă `magazin/0032` în locul lui `AvertismentProduse::ensureSchema`) |
| `0f5f568` | 20.10 | Avertismentul pe produs: scriptul pentru cele trei produse refrigerate | portat (`43bdccc`; `scripts/avertisment-produse.php` generic, textele și produsele Bioscem în `site-uri/bioscem/avertismente-produse.json`) |
| `ce40df1` | 20.10 | Avertismentul pe produs: README și `.env.example` | portat (`43bdccc`: `.env.example`; README-ul Bioscem nu e în CMS, documentația în `docs/PLUGINURI.md` și `docs/DEZVOLTARE.md`) |
| — | 29.10 | SEO livrarea 3 (restul redirecționărilor, noindex pe etichetele mici) | de portat (WP-R, A12) când apare |
| `5359ec6` | 21.10 | SEO livrarea 4, A5: termenul de livrare pentru Google (0–1 zile pregătire + 1–2 transport, zile lucrătoare luni–vineri) din patru setări, în datele structurate și în flux | portat (WP-R din 28.10, în commit-ul care mută aici „ultimul commit revizuit”; termenul implicit în `site-uri/bioscem/seo.json` → `livrare_retur.termen_livrare` = `[0, 1, 1, 2]`, setările `seo_livrare_*` din nucleu peste el; un site fără termen în pachet îl declară doar din setări complete) |
| `196fa46` | 21.10 | SEO livrarea 4, decizia D: transport gratuit de la prag și la produsele cu reducere, ca în checkout | portat (aceeași regulă ca `CheckoutCalculator::transportGratuit`, nucleu; apare doar pe un site cu `livrare_retur`) |
| `ef22b23` `682843c` `dd41c59` `a64afb6` | 21.10 | SEO livrarea 4, A10: noindex,follow pe căutarea internă și pe /blog gol, fără canonical pe 404 (nu pe căile de plată, coș, cont), pagina 404 a magazinului pe /blog/x și /categorie/x (nu pe căile excluse scrise cu „//”) | portat (`SeoIndexare`, nucleu; oprirea `SEO_NOINDEX=0`) |
| `9fe175d` `4f8b8ec` `dd41c59` | 21.10 | SEO livrarea 4, A11: titlul paginii ca H1 când textul n-are; pe /blog „Blog & Resurse” e H1-ul | portat (`SeoIndexare`, `views/site/custom-page.php`, `views/site/components/blog-posts.php`; oprirea `SEO_H1=0`) |
| `e2c97dc` `070e1af` | 21.10 | SEO livrarea 4, A8: width/height pe pozele din șabloane; cele 10 poze mari din fișiere WebP noi; mărimile pozelor vechi luate de pe disc, nu prin CDN | portat (`PozeOptimizate`, nucleu: lista din `config/poze-optimizate.php` e în pachet, `site-uri/bioscem/poze-optimizate.json` cu `"marimi_de_pe": "disc"`, fișierele în `site-uri/bioscem/public/assets/img/optimizate/`, copiate peste `public/` la adoptare; lecția `070e1af` în `tools/dev/verifica-pachet.php` (fără `"disc"` pachetul e refuzat) și în `scripts/diagnostic.php --pachet` (mărimea fiecărei poze vechi de pe disc față de pachet); oprirea `SEO_POZE=0`) |
| `2d43171` | 21.10 | SEO livrarea 4, A14: „Produse similare” din aceeași categorie, cu cardurile în HTML | portat (`ProduseSimilare`, nucleu; cardurile respectă pluginurile ca JavaScript-ul caruselului: stelele doar cu recenziile, prețul doar cu magazinul, butonul doar cât magazinul primește comenzi; oprirea `SEO_PRODUSE_SIMILARE=0`) |
| `bb11ae0` | 21.10 | SEO livrarea 4: HEAD pe o adresă cu litere mari primește același răspuns ca GET | portat (`SeoRedirects`) |
| `c3ad6ab` `b51067a` | 21.10 | SEO livrarea 4, D5 și A9: scriptul de conținut (căștile AIRCALL – alb / – negru cu adresele cu litere mici, 30 de linkuri din texte), fără frazele A9-12 și A9-13; ținta regulii `/produs/casti-antiradiatii-aircall-sp21` cu litere mici | portat (`scripts/seo-continut.php` generic, lista Bioscem în `site-uri/bioscem/seo-continut.json`, cu numele magazinului și numărătoarea mutate din script; regula în `site-uri/bioscem/redirecturi.json`). Pe Bioscem e deja aplicat (21.10): rulat din nou spune „Nimic de schimbat” |
| `36376f3` `34a1a2c` | 21.10 | SEO livrarea 4: README și `.env.example` | portat (`.env.example`; README-ul Bioscem nu e în CMS, documentația în `docs/SEO.md`, `docs/PACHETE.md`) |

`b632294` și `b3781a4` merg mai departe decât Biovitality: cheile de ofertă care s-ar ciocni (o ofertă
nouă care ar primi cheia păstrată a alteia, o cheie dublată) primesc altă variantă, o cheie care nu e
text nu strică salvarea, iar categoria principală a unei postări existente rămâne aceeași cât timp e
bifată. BV nu le are (`c64fc2d`, `3ea6a31`); le primește odată cu codul comun, la adoptare (rândurile
din secțiunea Biovitality). Dovada: `tools/dev/compara-cu-baza.sh` (salvările din admin, față de
Bioscem `9f59e79`).

Livrarea 2 SEO și avertismentul pe produs (`6c8c789` … `4254ca4`, WP-R din 20 octombrie) sunt doar pe Bioscem:
Biovitality, vertij și grafoanaytis le primesc abia odată cu codul comun, la adoptare (cu datele lor în pachet:
`seo.json` → `livrare_retur`, `logo_antet`, `flux_merchant`, `titluri`; fără ele nu declară livrare, retur sau
logo WebP). Dovada pentru Bioscem: `tools/dev/compara-cu-baza.sh` față de Bioscem `4254ca4`, fără nicio
intrare în `$porturiBioscem`: paginile (categoriile vechi, fluxul, poza de distribuire, avertismentele coșului)
și 11 cazuri de salvare (antetul cu logo-ul și linkurile vechi, câmpurile SEO ale categoriilor și mărcilor,
numele magazinului, livrarea cu prețuri fixe, avertismentul salvat, golit și neatins, fereastra din coș,
comanda refuzată fără bifă și comanda cu bifă, cu emailul și Admin → Comenzi); față de Bioscem `9f59e79` pică
fiecare dintre ele.

Livrarea 4 SEO (`5359ec6` … `070e1af`, WP-R din 28 octombrie) la fel, doar pe Bioscem. Dovada: comparația față de
Bioscem `070e1af`, fără nicio intrare în `$porturiBioscem`, cu licența `toate` și `bioscem`: paginile (inclusiv 404 fără
canonical, „Produse similare”, H1-ul paginilor fără) și cazurile noi de salvare: livrarea cu prețuri fixe (regula D și
termenul implicit), termenul din setări (cu valori greșite), căutarea cu noindex și paginile 404 noi (căile excluse cu „//”,
linkul de plată greșit, HEAD cu majuscule), /blog fără articole (noindex, „Blog & Resurse” ca H1), pozele refăcute (cu
pozele vechi de test puse de `compara-cu-baza.sh` la exact mărimea din pachet, una schimbată: rămâne PNG-ul) și produsele
similare alese în admin; față de Bioscem `4254ca4` pică 55 de pagini și cazuri. Istoricul (`--baza=f5ae5ea`) are
diferențele livrării 4 în `$porturiBioscem`, pe commit-uri (secțiunea „Produse similare” cu produsele verificate
independent de cod).

## Biovitality (de la `f7d15ba`)

**Deja în CMS** (le are Bioscem, deci și CMS-ul): `bbfc9bc` integrarea ERP; `adeea74` capcana anti-bot;
`81bda7a` Lot A; `2622237`, `f76eda8`, `1d48301`, `83b14e1`, `3021542`, `ff0cb6d` Lot B (pentru `3021542`
varianta Stripe a linkului de plată portată în WP-20, X5: `PaymentLink::PROCESATOR_STRIPE`, după BT și EuPlătesc;
dovada pe datele BV: WP-11b); `5d071e2`, `18d44ab` Lot C; `9f5b196`, `98f81b6`
(portate din Bioscem); `1b0d61f` tab „Sertar Oferte”; `fca58bd`, `c32bc8d` chat live (Bioscem l-a primit din BV);
`0fadb19` data nașterii pe liste; `f78ecb7` coloana „Cui a fost dat” (echivalentul Bioscem
`promoProductRecipientsApi`); `4acb250` linia de produs dublată (Bioscem `11b1040`); `88b7f4d` unire.

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `9cca65b` | 19.08 | Promoționale: fereastra „Cui a fost dat” derulează | portat (WP-21, C13: stilul în marcajul `bv:promo-export`, totalul lipit sus declarat în `$porturiBiovitality`) |
| `2ebc0cb` | 19.08 | Produse promoționale: export Excel | portat (WP-21, C13: `App\Support\XlsxWriter` în nucleu, fără rezerva în `public/uploads`; `GET /admin/promo-products/export`) |
| `657c0cf` | 19.08 | Promoționale: momentul acordării și operatorul | portat (WP-21, C13: `produse_promo/0031`; `given_by` = emailul adminului) |
| `6cc6fc5` | 20.08 | Eliberarea lacătului de sesiune pe cererile de fundal | portat (WP-12, 21 octombrie): C4 `elibereazaSesiunea()` în heartbeat (formularul din sesiune citit înainte), sumarul coșului, cele mai vândute și catalog; C3 `ErpClient::fromDbRapid`, cu 3 secunde (planul SEO §8.12, B6) în locul celor 4 din BV. Dovada: `tools/dev/test-reparatii.php` (E, F) |
| `9f076f8` | 20.08 | FANbox: „Ramburs” devine „Plata la FANbox” | nu se portează (CMS păstrează Bioscem `705371a`, C16) |
| `95f904f` | 20.08 | Newsletter: diagnostic destinatari, filtre și paginare | portat (WP-21, C11a, C11b: filtrele și paginarea pe server; scriptul ca `tools/newsletter/diagnostic-campanie.php`, fără `--emailuri`) |
| `8ba8ddd` | 20.08 | Recenzii: filtru anti-spam și secțiunea Spam | portat (WP-21, C10: `App\Support\ReviewGuard`, `recenzii/0030`; limita pe adresă cu `IpClient::adresa()`/`conditieLimita()` din WP-12b, nu cu `CF-Connecting-IP`/`X-Forwarded-For` ca în `ReviewGuard::ip` din BV; fără DDL la cerere) |
| `649c837` | 20.08 | Analiza competiției (+ mici schimbări în emails) | pluginul `concurenta` (Etapa 4, P3); partea de newsletter în WP-21 (C11) |
| `f4dd2c0` | 20.08 | Statistici newsletter fără reîncărcarea paginii | portat (WP-21, C11a) |
| `4f65fd9` | 20.08 | Galeria din selectoare nu se mai descarcă la fiecare pagină | portat (WP-12, C7: `loading="lazy" decoding="async"` pe cele 6 poze ale selectoarelor; declarat în `$porturiBiovitality`) |
| `0c682b3` | 21.08 | Recenzii: detectorul prinde injecțiile boolean | portat (WP-21, C10) |
| `855dab2` | 21.08 | Popup newsletter: CSS și JS în fișiere | portat (WP-21, C11e: `public/assets/{css,js}/optin-popup.*`, la fel; antetul BV le cere, G-vizual cu popup-ul deschis la adoptarea BV) |
| `c64fc2d` | 21.08 | Ofertele nu mai reînvie; salvarea nu mai așteaptă ERP-ul | C1 portat prin Bioscem `b632294` (`62349a2`; varianta Bioscem tratează în plus cheile care se ciocnesc, BV o primește la adoptare); C2, C3, C6 portate (WP-12, 21 octombrie; C3 ține minte în plus, 60 de secunde, codurile pe care ERP-ul nu le cunoaște, G-m3; C2 dovedit de cazul de salvare „reparație” din `compara-cu-baza.php`, C6 declarat în `$porturiBiovitality`); C5 nu se portează (înlocuit de migrări, WP-05) |
| `d19a8ca` | 25.08 | Panoul de căutare peste benzile de anunț | nu se portează (anulat de `6583720` în aceeași zi; BV = CMS) |
| `6583720` | 25.08 | Revine la straturile de dinainte în layout | nu se portează (anulează `d19a8ca`) |
| `f5486ee` `6ee0f79` `e5c2b11` `375c36b` `9945580` `282f24f` `74fd7e4` `e2de3cb` `b43ade2` `771bfa2` `5ef9fe7` `5f1abee` `168e78d` `a59d950` `9c1286b` `370f97d` `274bddd` `f9b4d78` | 26–31.08 | bunny.net, fișiere de lucru, rolul „Cloud user”, parole pe foldere, aplicația Windows | pluginul `fisiere_cloud` (Etapa 4, P1); aplicația C# în repo separat (P2) |
| `7ee1a86` `729d6fa` `ea927aa` | 31.08–04.09 | Analiza competiției: campanii, filtre, golire, „doar în magazin” | pluginul `concurenta` (Etapa 4, P3) |
| `153b633` | 31.08 | Partenerii într-o bandă care se derulează | pachetul BV (temă, Etapa 4, P5) |
| `c078bd5` | 31.08 | Blog: imagini redimensionabile, H5/H6, lățimea copertei, ciornă | partea publică portată (WP-22: `blog_posts.featured_image_width` din `blog/0033`, coperta îngustată în orice șablon, `{{blog_image_width}}`, stilurile H4–H6 și ale pozelor în șablonul implicit doar cu `blog_avansat`); editorul (pozele redimensionabile, H5/H6, glisorul copertei) și „Salvează ca draft” portate (WP-23: `views/admin/blog-post-editor.php`, câmpurile noi scrise separat, ca pe o bază fără `blog/0033` postarea să se salveze ca înainte) |
| `14d19dc` | 03.09 | Blog: import din WordPress, referințe, etichete | schema și `BlogReferences`, `Slug` portate (WP-22: `blog/0033`, `App\Support\Slug::make/unique` lângă `simplu()`/`categorie()`); importul portat (WP-23: unealta internă `migrare_wordpress`, `tools/migrare-wordpress/import-wordpress-posts.php` cu `WordPressExport` și `WordPressContent` lângă el, fără DDL — cere migrarea `blog/0033` —, pozele în `uploads/blog` prin `Paths`, `--post-type` implicit `post` (BV: `project`), exportul șters după o rulare reușită, `--pastreaza-sursa`) |
| `9c549c7` | 03.09 | Blog: cuprins, bibliografie, articole similare, etichete | randarea portată (WP-22: `BlogToc`, `BlogReferences`, `BlogRelatedPosts`, tokenurile noi, stilurile în `public/assets/css/blog-avansat.css` încărcat doar pe un articol care le folosește, nu în `app.css`; cuprinsul, șablonul implicit nou și secțiunea „Articole interesante” umplută de pe server doar cu `blog_avansat`, Bioscem înghețat 0; bibliografia și etichetele mereu); editorul (referințe, etichete, dialogul de link, textul alternativ) portat (WP-23) |
| `263b520` | 03.09 | Blog: căutare și filtrare pe categorii și etichete | portat (WP-22: `BlogSearch`, căutarea și chips-urile doar cu `blog_avansat`; `/blog/categorie/{slug}`, `/blog/eticheta/{slug}` mereu, cu 404 pentru una necunoscută sau o categorie inactivă, unde BV dă o listă goală cu 200; noindex pe căutare și canonical-ul filtrelor rămân la WP-39, BV-B5) |
| `49c4f5b` | 03.09 | Blog: previzualizare desktop/telefon, card Google | portat (WP-23: `POST /admin/blog/posts/preview`, cu fonturile și CSS-ul temei din pachet în loc de cele scrise fix; cardul Google în editor, cu gazda și numele site-ului, nu „biovitality.ro”; componenta comună a cardului rămâne la WP-37) |
| `84e29a9` | 03.09 | Parteneri care curg, bulină la recenzii, bloc video în newslettere | bulina portată (WP-21, C10: `insigna` în catalogul meniului, fără spam, setarea `recenzii_insigna_meniu`, Bioscem înghețat 0), blocul video portat (WP-21, C11d: `VideoEmbed::copertaSiTitlu` în clasa venită din vertij, coperta doar din https și doar imagine, cel mult 5 MB); partenerii pachetul BV (P5) |
| `f5295b5` | 03.09 | Căutare mai rapidă în blog + documentație | portat (WP-22: `Slug::transliterariUnuLaUnu`, `BlogSearch`, `BlogRelatedPosts`; documentația în `docs/PLUGINURI.md`, `blog`) |
| `3ea6a31` | 03.09 | Editorul reține categoriile, etichetele și referințele | X1 (categoriile) portat prin Bioscem `89dcc4e` (`1df3771`), plus regula categoriei principale din Bioscem `b3781a4` (`dbd218c`), pe care BV nu o are și o primește la adoptare; etichetele și referințele portate (WP-23: `loadBlogPostById`, `syncBlogPostTags`, duplicarea) |
| `e2c33d3` | 03.09 | Paginarea postărilor nu mai sare în capul listei | portat (WP-23: `views/admin/blog-posts.php`; declarat în `compara-cu-baza.php` prin marcaje) |
| `6ea2cb1` | 03.09 | Banda cu parteneri pe desktop + derulare lină din cuprins | `blog-anchors.js` și `.bv-anchor-flash` portate (WP-22: doar pe un articol care are cuprins, citări, bibliografie, etichete sau articole similare, nu pe fiecare pagină); partenerii pachetul BV |
| `b9fcaf8` | 03.09 | Coloanele: culoarea coloanei nu mai e acoperită | portat (WP-21, C11c; `transparent`/`none` scrise rămân ca înainte, doar fundalul gol nu mai scrie nimic) |
| `90afd46` | 08.09 | Template-uri de produs: pachete cu produse din magazin | câmpurile portate (WP-19, C14d: `template_only` cu migrarea `catalog/0027`, tipul „Imagine” cu selectorul galeriei, filtrul câmpurilor după șablon în editor, câmpul „html” ca markup după `catalog_camp_html_brut`, Bioscem înghețat `0`); pachetele pluginul `pachete_produse` (Etapa 4, P4) |
| `83067d3` | 08.09 | Instalarea creează coloanele pentru pachete | pluginul `pachete_produse` (Etapa 4; migrare în grupul G) |
| `2b8a618` | 09.09 | Checkout, transport gratuit, produse inactive, ordine manuală | produsele inactive și ordinea manuală portate (WP-19: C8 cu `catalog_ascunde_inactive`, Bioscem înghețat `0`, linkul de ciornă cu cheia din `catalog/0026`; C14b `App\Support\ProductOrder` + sortarea „Ordinea din catalog” a GR); transportul gratuit portat (WP-20, C14a); textul Stripe din checkout nu se portează (C15) |
| `1d4bee2` | 09.09 | Cod de pagină pentru produsele unui template | portat (WP-19, C14e: `{{products_by_template:…}}`, cardul în `product-card.php` cu markup-ul catalogului de azi, octet cu octet) |
| `b9e3ddc` | 09.09 | Bifa care scoate un produs din lista magazinului | portat (WP-19, C14c: `hidden_in_catalog` din `catalog/0026`) |
| `57447e0` | 09.09 | Editorul spune ce câmpuri a ascuns și de ce | portat (WP-19, C14d) |
| `afc6fd7` `7391226` | 09.09 | Coșul arată pachetele care conțin produsul | pluginul `pachete_produse` (Etapa 4, P4) |
| `a14df64` | 09.09 | Transport gratuit din produs, respectat peste tot | portat (WP-20, C14a: `products.free_shipping` din `magazin/0028`, coșul, cota FAN, cele trei recalculări din admin cu transportul pus de mână înainte, datele Google; dovada pe datele BV: WP-11b) |
| `222ed71` | 12.10 | Cupoane unice: produsele se schimbă în masă | portat (WP-21, C12; produsele cupoanelor din pagină în datele marcajului, nu în atribute pe rând) |
| `aed0cee` | 15.10 | Codul de cupon acceptat cu spații | portat (WP-20, C9; dovada pe datele BV: WP-11b) |
| `cc231e4` | 19.10 | AWB: numărul de colete ales în ERP la aprobare | portat (`4915478`, din Bioscem `de5c2b0`); la trecerea BV înghețare `fan_kg_max_colet=0` |
| — | — | Contul arată doar comenzile legate de cont | nu se face pe BV (decizia OD-47, 19.10): doar în codul comun, regula nucleului din WP-25; la trecere intră în „ce se schimbă” al BV |

**Ramuri BV neunite în ramura de lucru** (de verificat cu raportul „live vs repo” din Etapa 0; implicit nu se portează):
`claude/migrate-two-sites-backend-gl3heg` → `a5cceb7` (setarea sertarului de oferte; echivalentul a intrat prin
`1b0d61f`), `d9ae088` și `de200b3` (`wk-compat.css/js` pentru clasele `wk-*` din conținutul importat din WordPress;
dacă BV live le are, devin CSS de temă BV în Etapa 4), `5ef9706` + anularea `308b369`;
`claude/bunny-admin-access-zone-8eqier` → `63dc58b` (același conținut ca `f5486ee`).

| `779e0d8` | 21.10 | Aplicația de desktop: executabilul construit se păstrează 14 zile | nu se portează (doar `.github/workflows/`, construirea aplicației Windows a BV; CMS-ul n-are `.github/`) |

Biovitality nu are livrarea 2 SEO, avertismentul pe produs și livrarea 4 SEO ale Bioscem (`6c8c789` … `070e1af`,
20–21.10): le primește odată cu codul comun, la adoptare (Etapa 4; lista din `site-uri/biovitality/ce-se-schimba.md` le
trece atunci: noindex pe căutare și pe /blog gol, 404 fără canonical și pagina 404 a magazinului pe /blog/x și
/categorie/x, H1-ul din titlu pe paginile fără, „Produse similare” din aceeași categorie, HEAD cu majuscule; termenul de
livrare și pozele WebP doar cu datele lor în pachet).

## vertij (de la `6d48cd8`)

**Deja în CMS** (oglinda unor commit-uri BV de dinaintea desprinderii Bioscem): `123f873` (= BV `2ff4dd1`),
`e044174` (= `64d245c`), `132a9c1` (= `619da25`), `2d8d407` (= `7773149`). `66a9af2` e o unire.

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `7c1ae16` | 29.06 | Cache: browserul revalidează mereu | de portat (WP-15, VT-C2, setare pe site) |
| `5a50703` | 29.06 | Formularul de contact spre emailul site-ului | nu se portează (Bioscem `cad75e0`, `contact_form_recipients`; înghețare VT) |
| `7519384` | 29.06 | Slidere pe mobil + sigla SVTH | de verificat în WP-24 (sliderul pare deja în CMS prin BV `7773149`/`c2959f3`); sigla = conținut VT |
| `b0aa083` `e577b94` `01e2773` `76dd45e` | 12.07 | Slider `{{category_posts}}`: carduri de eveniment și navigare | de portat (WP-24, VT-C4) |
| `32db865` `b032fae` `1f28376` `8412906` | 22.07 | Donații Stripe: fundație, popup, mod „Exemplu” | pluginul `donatii` (Etapa 4, VT-D1, D8) |
| `1fbd151` `6d50d39` `fd766f6` | 22.07 | Cache de pagini: integritate la scriere, citire, 304 | de portat (WP-15, VT-C1) |
| `4ac772b` | 22.07 | Logger fatal + nu memora pagini incomplete | de portat (WP-15, VT-C1, VT-C3) |
| `265db83` `67319f7` `ff3c46e` `c1abee4` | 07.08 | Tokenurile `category_event_rows`, `category_grid_paged` | de portat (WP-24, VT-C4) |
| `5e1d8bd` `6c4d97c` | 07.08 | Paginile de cont și resetare în culorile brandului | pachetul VT (temă, Etapa 4) |
| `833baa9` | 07.08 | Tokenul `{{auth_form}}` | de portat (WP-25, VT-C5) |
| `d3439a5` `66b080d` `da85d3d` `ffa3d9c` | 30.08–05.10 | Conturi profesionale, categorii de membri, cereri noi | pluginul `membri` (Etapa 4, VT-M1) |
| `93cac7a` | 06.09 | Pagina de cont refăcută, butonul de cont din antet | WP-25 (VT-X3, decizia OD-20); `header_account_label` WP-16 (VT-C6) |
| `50ef812` | 06.09 | Confirmarea emailului, emailuri de cont cu brand | WP-25 (VT-C9), WP-17 (VT-C7); restul în `membri` |
| `af8ab87` `46ba1d3` | 06.09 | Poza de profil pe avatar, lățimea paginii de cont | `membri` (Etapa 4) / WP-25 (VT-X3) |
| `7a70d26` `3d37fb7` | 06.09 | Emailul în afara adresei paginii; înregistrare reluată | de portat (WP-25, VT-C9) / `membri` |
| `3441d4a` `1ad53e1` | 06.09–05.10 | Fără numele clientului anterior în site și admin | de portat (WP-16, VT-C6) |
| `26c74f9` | 06.09 | Bugetul de retrimitere separat | de portat (WP-25, VT-C8) |
| `e707086` | 06.09 | Trimitere SMTP reală | nu se portează (CMS are varianta aliniată DMARC, `OrderMailer.php:716-729`); diagnosticul în WP-26 |
| `a7177e8` `b52208e` | 06.09–05.10 | Sigla și culorile linkurilor în emailuri | de portat (WP-17, VT-C7) |
| `7c2a1a2` | 05.10 | Pregătire pentru domeniul oficial (sesiune, link coș) | nu se portează (Bioscem `af56687`, VT-C12/C14) |
| `dc52498` `b3967d3` `24bca44` `5359797` `ca1120c` | 05.10 | Donații lunare, chitanțe, versiunea API Stripe | `donatii` (Etapa 4, VT-D1…D4); `StripeGateway` WP-15 (VT-C10); dispecerul WP-25 (VT-X1) |
| `ad1d792` | 20.10 | Secțiunile pentru medici și pacienți: zece categorii de blog și zece pagini (scriptul `seed-content-sections.php`), blocurile HTML din `docs/pagini/` | nu se portează (conținutul VT, deja în baza lui; ca `docs/templates` la Bioscem, decizia OD-34); repararea panoului „Evenimente pentru pacienți” e tot conținut (HTML-ul paginii) |
| `0fc2b2c` | 20.10 | Câmpul de video al postării: linkuri YouTube și Vimeo (`VideoEmbed`), un singur loc pentru postare și evenimentul următor; șablonul „Articol cu imagini și video” | portat (`78e1d36`: `VideoEmbed`, `SiteController`, `NextEvent`, `BlogTemplateFields`, editorul postării; pluginul blog). Șablonul îl face scriptul de conținut VT (nu se portează). Pentru Bioscem: rând în `site-uri/bioscem/ce-se-schimba.md`, textul editorului declarat în `$porturiVertij`, iar un fișier .mp4 rămâne `<video>` exact ca azi (caz „regresie” în `compara-cu-baza.php`). Diferență față de vertij (revizuirea portului, 20.10): playerul YouTube/Vimeo se încarcă singur doar cu acordul pentru cookie-uri, altfel la apăsarea casetei „Pornește videoclipul”; Vimeo cu `dnt=1` (rând în `site-uri/vertij/ce-se-schimba.md`; ambele ramuri declarate în `$porturiVertij`, cazul „blog: un link YouTube/Vimeo”). Biovitality are altă clasă cu același nume (`App\Support\VideoEmbed`, coperta videoului în newslettere, `84e29a9`): WP-21 (C11d) le pune împreună în aceasta |

| `7d7d8cc` | 26.10 | Plata activării în listele de cereri și utilizatori; `&nbsp;` din rezumatele cardurilor (`PlainText`) | de portat: listele în pluginul `membri` (Etapa 4, VT-M1) / WP-25; `PlainText` (entitățile decodate, sfârșitul de bloc ca spațiu, în `CategoryPosts`) cu WP-24 (VT-C4) |
| `b0a81e1` | 26.10 | Derularea tabelelor late doar pe cele două liste (`.admin-table-scroll`) | de portat odată cu `7d7d8cc` (aceleași liste; adminul refăcut în faza D). Comite și două capturi într-un dosar `undefined/` (nu se portează) |
| `9ab7999` | 28.10 | Șablonul postării de eveniment (`docs/sabloane/eveniment.*`) | nu se portează (conținutul VT, ca `ad1d792`, decizia OD-34) |

Cele trei commit-uri de mai sus (`7d7d8cc` … `9ab7999`, „doar origin” la `tools/dev/de-portat.php`) sunt pe ramura
vertij de pe GitHub, dar clona locală (`../vertij-tinitus-refacut`) e încă la `0fc2b2c`. „Ultimul commit revizuit” al
vertij rămâne `0fc2b2c` până se portează (porțile rulează codul vertij de la acel commit, `smoke.sh --pachet=vertij`):
`7d7d8cc` și `b0a81e1` ating codul (`PlainText`, listele adminului), deci se portează în WP-ul lor, nu în acest WP-R
(doar Bioscem, decizia A2 din 27.10).

vertij nu are livrarea 2 SEO, avertismentul pe produs și livrarea 4 SEO ale Bioscem (`6c8c789` … `070e1af`, 20–21.10): le
primește odată cu codul comun, la adoptare (Etapa 4; `site-uri/vertij/ce-se-schimba.md` le trece atunci, după pluginurile VT).

## grafoanaytis (de la `87aaa90`)

grafoanaytis nu are livrarea 2 SEO, avertismentul pe produs și livrarea 4 SEO ale Bioscem (`6c8c789` … `070e1af`,
20–21.10): le primește odată cu codul comun, la adoptare (Etapa 4; pe un site fără pluginul magazin, avertismentul și fluxul Merchant nu apar).

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `509354b` | — | Temelia vizuală: brand și Bootstrap 5 | cheia `bootstrap` portată (WP-13, 22 octombrie: `tema.json` → `bootstrap`, Bootstrap 5.3.8 în nucleu, `public/assets/vendor/bootstrap/`, aceiași octeți ca la GR; docs/PACHETE.md); restul pachetul GR (GR-19) |
| `21bbb02` | — | Paginile site-ului, front-end desprins de tema veche | `incarca_app_css` portat (WP-13, 22 octombrie: fără `app.css` pe paginile publice, adminul îl păstrează); conținutul pachetul GR (GR-20) |
| `9d7d0fa` | — | Instalarea nouă fără resturi | nu se portează (valorile implicite → înghețarea GR, GR-22) |
| `74e4b25` | — | Pagini pe tot ecranul, încărcare multiplă în galerie | WP-15 (GR-05); `main: lat` și mesajele în containerul lor (`flash: bootstrap`) portate (WP-13, 22 octombrie) |
| `50f5d28` | — | Pagina Produse în meniu | pachetul GR (GR-20); implicitul → GR-22 |
| `2c11526` | — | Formularul GDPR adaptat | de portat (WP-18, GR-03) |
| `86ca09b` | — | Fontul Rubik, meniu cu majuscule | Rubik portat (WP-13, 22 octombrie, GR-07: `public/assets/vendor/fonturi/rubik/`, aceleași greutăți și fișierul variabil al lui GR, o singură copie pe subset; `tema.json` → `fonturi`, `fonturi_google: false`); restul pachetul GR |
| `94a44db` | — | Banda de clienți, imaginile clientului | pachetul GR; `optimizeaza-imagini` → WP-40 (B5); `mapeaza-imagini` nu se portează |
| `873383e` | — | Imaginile lipsă arată a machetă (`imagini.js`) | pachetul GR (`js_final`, GR-19) |
| `9e21659` | — | Banner de proiect, pagina Companie, `install`/`seed` | codurile de ieșire portate în WP-05 (GR-02: `install.php` și `seed.php` ies cu 1, mesajul pe stderr); restul pachetul GR |
| `2ec6c26` `6b41718` `69b1852` | — | Inventarul golurilor, documentul pentru client, marcajele „[DE COMPLETAT]” | nu se portează (unelte GR, GR-21) |
| `b568a9c` | — | Catalog și cereri de ofertă în locul coșului | WP-26 (GR-15); `ModPrezentare` nu se portează (GR-14a, garda o face deja) |
| `8f51fe4` | — | Serviciu → produsele lui → produsul | portat (WP-19: componenta `produse-categorie.php` cu textele neutre, GR-16; `{{produse_serviciu}}`, GR-17, calculat doar pe paginile care îl au, fără numărătoarea G2 din WP-10: inert unde nu e folosit) |
| `84c9f6b` | — | Josul paginii de produs: imagine mare, banda de similare | `{{product_produse_similare}}` portat (WP-19, GR-16: titlul și subtitlul în `catalog_similare_titlu`/`_subtitlu`, subtitlul GR înghețat; „Cere ofertă” doar cu `cereri_oferta` portat și pe site, WP-26); CSS-ul pachetul GR |
| `5f27a59` | — | Catalogul la `/produse` | portat (WP-19, GR-09: `catalog_pagina`, GR înghețat `produse`; /magazin 301 acolo; catalogul doar pe pagina din setare, nu pe orice pagină cu token — verificarea BS-V1 din WP-10 n-a rulat); CSS-ul pachetul GR |
| `810229c` | — | Săgeata din catalog buton real, poza principală în carusel | caruselul deja în CMS (GR-11); `ModPrezentare` nu; butonul verificat în WP-19: codul GR de la `d3c4b79` nu mai are rândul „Cere ofertă” pe card (punctul 13 al reviziei), doar CSS-ul lui, care e al pachetului GR; nimic de portat |
| `b480109` `8662320` | — | Brandul albastru, semnul de focalizare | pachetul GR (GR-19) |
| `d253903` | — | Pagina de servicii legată de sertarul de ofertă | WP-26 (vederea admin); conținutul pachetul GR |
| `19a8784` | — | Pagina Produse fără sortare și fără butoane | `catalog_sortare_vizibila` portat (WP-19, GR-16; GR înghețat `0`); cardul fără rândul de jos gol fără magazin (WP-19, GR-16); restul pachetul GR |
| `f2f1596` | — | Sigla nouă, subsolul, filtre pe patru coloane | pachetul GR (GR-19, GR-20) |
| `b4159bd` | — | Pagini legale, buton „înapoi sus”, Companie | `buton_sus` portat (WP-13, 22 octombrie: ținta, ancora și scriptul ei din layoutul GR; înfățișarea `.sus` vine cu CSS-ul temei GR, GR-19); conținutul pachetul GR |
| `1ff9f88` | — | Catalogul în ordinea cerută de client | portat (WP-19, GR-08: o singură coloană `sort_position` cu BV C14b, sortarea `catalog`, `catalog_sortare_implicita` înghețat `catalog`; pasul `site/grafoanaytis/0001_ordine_catalog` mută `sort_order` ca rang dens, aplicat la adoptarea GR în Etapa 4) |
| `e927172` `49d8b9b` `9e1b62d` `e34fd31` | — | Animații, cifre, banda de sigle, antet care se strânge | pachetul GR (GR-19) |
| `d1bffca` | — | Toate mesajele spre cutia tipografiei | înghețarea GR (GR-22); `verifica-email` generic WP-26 (GR-21) |
| `fe0c041` | — | Reply-To = cine scrie; verificarea SMTP | de portat (WP-15 GR-01; WP-26 GR-21) |
| `d3c4b79` | 18.09 | Banda de recrutare, PDF-uri în galerie, CSS-ul antetului | PDF-urile WP-15 (GR-04); CSS antet deja în CMS (GR-12); conținutul pachetul GR (GR-13, GR-20) |
| `91a332c` `7bbc69e` `fbd2a08` `c2a96bc` `8f4a520` `f1c0161` `496402a` `257a831` `2ac291b` `34be948` `0eb923d` `c55fed0` `b1d9474` `bda8c32` `2305ad7` `8212267` `349bddb` `ae088a9` `79a8df0` `ae9ddc7` `1980696` `ef56a3e` `ba0b3ed` | — | Conținut, imagini și texte ale clientului | pachetul GR (GR-19, GR-20); regula din `496402a` (toate rândurile paginii de start) intră în importator |
| `6ea348f` `d2b7f4d` `466ab8c` `ed45094` `a145317` `d0b0114` `a19bae8` | — | Încărcări de fișiere brute („asset-uri noi”) | nu se portează (rămân în afara oricărui repo) |
