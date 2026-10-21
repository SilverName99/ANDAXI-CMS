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
| Bioscem | `Nou-site-bioscem`, `claude/migrate-bioscem-site-bz38y4` | `4254ca4` (20.10.2026) | CMS pornește din `11b1040` |
| Biovitality | `Mutare-Site-Biovitality`, `claude/migrate-bioscem-site-bz38y4` | `cc231e4` (20.10.2026) | Bioscem s-a desprins din BV `f7d15ba` (10.08.2026) |
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
| — | 05.11 | SEO livrarea 4 (dimensiuni poze, linkuri, H1, linkuri interne; termenul de livrare 1–3 zile lucrătoare pentru Google, decizia proprietarului din 20 oct) | de portat (WP-R, A12) |

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

## Biovitality (de la `f7d15ba`)

**Deja în CMS** (le are Bioscem, deci și CMS-ul): `bbfc9bc` integrarea ERP; `adeea74` capcana anti-bot;
`81bda7a` Lot A; `2622237`, `f76eda8`, `1d48301`, `83b14e1`, `3021542`, `ff0cb6d` Lot B (pentru `3021542`
varianta Stripe a linkului de plată rămâne „de portat”, X5); `5d071e2`, `18d44ab` Lot C; `9f5b196`, `98f81b6`
(portate din Bioscem); `1b0d61f` tab „Sertar Oferte”; `fca58bd`, `c32bc8d` chat live (Bioscem l-a primit din BV);
`0fadb19` data nașterii pe liste; `f78ecb7` coloana „Cui a fost dat” (echivalentul Bioscem
`promoProductRecipientsApi`); `4acb250` linia de produs dublată (Bioscem `11b1040`); `88b7f4d` unire.

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `9cca65b` | 19.08 | Promoționale: fereastra „Cui a fost dat” derulează | de portat (WP-21, C13) |
| `2ebc0cb` | 19.08 | Produse promoționale: export Excel | de portat (WP-21, C13; `XlsxWriter` în nucleu) |
| `657c0cf` | 19.08 | Promoționale: momentul acordării și operatorul | de portat (WP-21, C13; migrarea 0031) |
| `6cc6fc5` | 20.08 | Eliberarea lacătului de sesiune pe cererile de fundal | portat (WP-12, 21 octombrie): C4 `elibereazaSesiunea()` în heartbeat (formularul din sesiune citit înainte), sumarul coșului, cele mai vândute și catalog; C3 `ErpClient::fromDbRapid`, cu 3 secunde (planul SEO §8.12, B6) în locul celor 4 din BV. Dovada: `tools/dev/test-reparatii.php` (E, F) |
| `9f076f8` | 20.08 | FANbox: „Ramburs” devine „Plata la FANbox” | nu se portează (CMS păstrează Bioscem `705371a`, C16) |
| `95f904f` | 20.08 | Newsletter: diagnostic destinatari, filtre și paginare | de portat (WP-21, C11a) |
| `8ba8ddd` | 20.08 | Recenzii: filtru anti-spam și secțiunea Spam | de portat (WP-21, C10; migrarea 0030; limita pe adresă cu `IpClient::adresa()`/`conditieLimita()` din WP-12b, nu cu `CF-Connecting-IP`/`X-Forwarded-For` ca în `ReviewGuard::ip` din BV) |
| `649c837` | 20.08 | Analiza competiției (+ mici schimbări în emails) | pluginul `concurenta` (Etapa 4, P3); partea de newsletter în WP-21 (C11) |
| `f4dd2c0` | 20.08 | Statistici newsletter fără reîncărcarea paginii | de portat (WP-21, C11a) |
| `4f65fd9` | 20.08 | Galeria din selectoare nu se mai descarcă la fiecare pagină | portat (WP-12, C7: `loading="lazy" decoding="async"` pe cele 6 poze ale selectoarelor; declarat în `$porturiBiovitality`) |
| `0c682b3` | 21.08 | Recenzii: detectorul prinde injecțiile boolean | de portat (WP-21, C10) |
| `855dab2` | 21.08 | Popup newsletter: CSS și JS în fișiere | de portat (WP-21, C11e) |
| `c64fc2d` | 21.08 | Ofertele nu mai reînvie; salvarea nu mai așteaptă ERP-ul | C1 portat prin Bioscem `b632294` (`62349a2`; varianta Bioscem tratează în plus cheile care se ciocnesc, BV o primește la adoptare); C2, C3, C6 portate (WP-12, 21 octombrie; C3 ține minte în plus, 60 de secunde, codurile pe care ERP-ul nu le cunoaște, G-m3; C2 dovedit de cazul de salvare „reparație” din `compara-cu-baza.php`, C6 declarat în `$porturiBiovitality`); C5 nu se portează (înlocuit de migrări, WP-05) |
| `d19a8ca` | 25.08 | Panoul de căutare peste benzile de anunț | nu se portează (anulat de `6583720` în aceeași zi; BV = CMS) |
| `6583720` | 25.08 | Revine la straturile de dinainte în layout | nu se portează (anulează `d19a8ca`) |
| `f5486ee` `6ee0f79` `e5c2b11` `375c36b` `9945580` `282f24f` `74fd7e4` `e2de3cb` `b43ade2` `771bfa2` `5ef9fe7` `5f1abee` `168e78d` `a59d950` `9c1286b` `370f97d` `274bddd` `f9b4d78` | 26–31.08 | bunny.net, fișiere de lucru, rolul „Cloud user”, parole pe foldere, aplicația Windows | pluginul `fisiere_cloud` (Etapa 4, P1); aplicația C# în repo separat (P2) |
| `7ee1a86` `729d6fa` `ea927aa` | 31.08–04.09 | Analiza competiției: campanii, filtre, golire, „doar în magazin” | pluginul `concurenta` (Etapa 4, P3) |
| `153b633` | 31.08 | Partenerii într-o bandă care se derulează | pachetul BV (temă, Etapa 4, P5) |
| `c078bd5` | 31.08 | Blog: imagini redimensionabile, H5/H6, lățimea copertei, ciornă | de portat (WP-22 B1, WP-23 B4) |
| `14d19dc` | 03.09 | Blog: import din WordPress, referințe, etichete | de portat (WP-22 B1, WP-23 B7) |
| `9c549c7` | 03.09 | Blog: cuprins, bibliografie, articole similare, etichete | de portat (WP-22, B2) |
| `263b520` | 03.09 | Blog: căutare și filtrare pe categorii și etichete | de portat (WP-22, B3) |
| `49c4f5b` | 03.09 | Blog: previzualizare desktop/telefon, card Google | de portat (WP-23 B4; cardul Google în WP-37) |
| `84e29a9` | 03.09 | Parteneri care curg, bulină la recenzii, bloc video în newslettere | bulina WP-21 (C10), video WP-21 (C11d); partenerii pachetul BV (P5) |
| `f5295b5` | 03.09 | Căutare mai rapidă în blog + documentație | de portat (WP-22, B3, X12 `Slug`) |
| `3ea6a31` | 03.09 | Editorul reține categoriile, etichetele și referințele | X1 (categoriile) portat prin Bioscem `89dcc4e` (`1df3771`), plus regula categoriei principale din Bioscem `b3781a4` (`dbd218c`), pe care BV nu o are și o primește la adoptare; etichetele și referințele de portat (WP-23, B4) |
| `e2c33d3` | 03.09 | Paginarea postărilor nu mai sare în capul listei | de portat (WP-23, B4) |
| `6ea2cb1` | 03.09 | Banda cu parteneri pe desktop + derulare lină din cuprins | `blog-anchors.js` în WP-22 (doar pe `/blog/{slug}`); partenerii pachetul BV |
| `b9fcaf8` | 03.09 | Coloanele: culoarea coloanei nu mai e acoperită | de portat (WP-21, C11c) |
| `90afd46` | 08.09 | Template-uri de produs: pachete cu produse din magazin | câmpurile WP-19 (C14d); pachetele pluginul `pachete_produse` (Etapa 4, P4) |
| `83067d3` | 08.09 | Instalarea creează coloanele pentru pachete | pluginul `pachete_produse` (Etapa 4; migrare în grupul G) |
| `2b8a618` | 09.09 | Checkout, transport gratuit, produse inactive, ordine manuală | WP-19 (C8, C14b), WP-20 (C14a); textul Stripe din checkout nu se portează (C15) |
| `1d4bee2` | 09.09 | Cod de pagină pentru produsele unui template | de portat (WP-19, C14e) |
| `b9e3ddc` | 09.09 | Bifa care scoate un produs din lista magazinului | de portat (WP-19, C14c) |
| `57447e0` | 09.09 | Editorul spune ce câmpuri a ascuns și de ce | de portat (WP-19, C14d) |
| `afc6fd7` `7391226` | 09.09 | Coșul arată pachetele care conțin produsul | pluginul `pachete_produse` (Etapa 4, P4) |
| `a14df64` | 09.09 | Transport gratuit din produs, respectat peste tot | de portat (WP-20, C14a) |
| `222ed71` | 12.10 | Cupoane unice: produsele se schimbă în masă | de portat (WP-21, C12) |
| `aed0cee` | 15.10 | Codul de cupon acceptat cu spații | de portat (WP-20, C9) |
| `cc231e4` | 19.10 | AWB: numărul de colete ales în ERP la aprobare | portat (`4915478`, din Bioscem `de5c2b0`); la trecerea BV înghețare `fan_kg_max_colet=0` |
| — | — | Contul arată doar comenzile legate de cont | nu se face pe BV (decizia OD-47, 19.10): doar în codul comun, regula nucleului din WP-25; la trecere intră în „ce se schimbă” al BV |

**Ramuri BV neunite în ramura de lucru** (de verificat cu raportul „live vs repo” din Etapa 0; implicit nu se portează):
`claude/migrate-two-sites-backend-gl3heg` → `a5cceb7` (setarea sertarului de oferte; echivalentul a intrat prin
`1b0d61f`), `d9ae088` și `de200b3` (`wk-compat.css/js` pentru clasele `wk-*` din conținutul importat din WordPress;
dacă BV live le are, devin CSS de temă BV în Etapa 4), `5ef9706` + anularea `308b369`;
`claude/bunny-admin-access-zone-8eqier` → `63dc58b` (același conținut ca `f5486ee`).

Biovitality nu are livrarea 2 SEO și avertismentul pe produs ale Bioscem (`6c8c789` … `4254ca4`, 20.10): le primește
odată cu codul comun, la adoptare (Etapa 4; lista din `site-uri/biovitality/ce-se-schimba.md` le trece atunci).

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

vertij nu are livrarea 2 SEO și avertismentul pe produs ale Bioscem (`6c8c789` … `4254ca4`, 20.10): le primește odată
cu codul comun, la adoptare (Etapa 4; `site-uri/vertij/ce-se-schimba.md` le trece atunci, după pluginurile VT).

## grafoanaytis (de la `87aaa90`)

grafoanaytis nu are livrarea 2 SEO și avertismentul pe produs ale Bioscem (`6c8c789` … `4254ca4`, 20.10): le
primește odată cu codul comun, la adoptare (Etapa 4; pe un site fără pluginul magazin, avertismentul și fluxul Merchant nu apar).

| Commit | Data | Subiect | Stare |
|---|---|---|---|
| `509354b` | — | Temelia vizuală: brand și Bootstrap 5 | cheia `bootstrap` WP-13 (GR-18); restul pachetul GR (GR-19) |
| `21bbb02` | — | Paginile site-ului, front-end desprins de tema veche | `incarca_app_css` WP-13 (GR-18); conținutul pachetul GR (GR-20) |
| `9d7d0fa` | — | Instalarea nouă fără resturi | nu se portează (valorile implicite → înghețarea GR, GR-22) |
| `74e4b25` | — | Pagini pe tot ecranul, încărcare multiplă în galerie | WP-15 (GR-05); `main: lat` WP-13 (GR-18) |
| `50f5d28` | — | Pagina Produse în meniu | pachetul GR (GR-20); implicitul → GR-22 |
| `2c11526` | — | Formularul GDPR adaptat | de portat (WP-18, GR-03) |
| `86ca09b` | — | Fontul Rubik, meniu cu majuscule | WP-13 (GR-07); restul pachetul GR |
| `94a44db` | — | Banda de clienți, imaginile clientului | pachetul GR; `optimizeaza-imagini` → WP-40 (B5); `mapeaza-imagini` nu se portează |
| `873383e` | — | Imaginile lipsă arată a machetă (`imagini.js`) | pachetul GR (`js_final`, GR-19) |
| `9e21659` | — | Banner de proiect, pagina Companie, `install`/`seed` | codurile de ieșire portate în WP-05 (GR-02: `install.php` și `seed.php` ies cu 1, mesajul pe stderr); restul pachetul GR |
| `2ec6c26` `6b41718` `69b1852` | — | Inventarul golurilor, documentul pentru client, marcajele „[DE COMPLETAT]” | nu se portează (unelte GR, GR-21) |
| `b568a9c` | — | Catalog și cereri de ofertă în locul coșului | WP-26 (GR-15); `ModPrezentare` nu se portează (GR-14a, garda o face deja) |
| `8f51fe4` | — | Serviciu → produsele lui → produsul | WP-19 (GR-16 componenta; GR-17 doar dacă tokenul e folosit) |
| `84c9f6b` | — | Josul paginii de produs: imagine mare, banda de similare | WP-19 (GR-16 `{{product_produse_similare}}`); CSS-ul pachetul GR |
| `5f27a59` | — | Catalogul la `/produse` | WP-19 (GR-09); CSS-ul pachetul GR |
| `810229c` | — | Săgeata din catalog buton real, poza principală în carusel | caruselul deja în CMS (GR-11); `ModPrezentare` nu; butonul de verificat în WP-19 |
| `b480109` `8662320` | — | Brandul albastru, semnul de focalizare | pachetul GR (GR-19) |
| `d253903` | — | Pagina de servicii legată de sertarul de ofertă | WP-26 (vederea admin); conținutul pachetul GR |
| `19a8784` | — | Pagina Produse fără sortare și fără butoane | WP-19 (GR-16 `catalog_sortare_vizibila`); restul pachetul GR |
| `f2f1596` | — | Sigla nouă, subsolul, filtre pe patru coloane | pachetul GR (GR-19, GR-20) |
| `b4159bd` | — | Pagini legale, buton „înapoi sus”, Companie | `buton_sus` WP-13 (GR-18); conținutul pachetul GR |
| `1ff9f88` | — | Catalogul în ordinea cerută de client | de portat (WP-19, GR-08; pasul de date GR în Etapa 4) |
| `e927172` `49d8b9b` `9e1b62d` `e34fd31` | — | Animații, cifre, banda de sigle, antet care se strânge | pachetul GR (GR-19) |
| `d1bffca` | — | Toate mesajele spre cutia tipografiei | înghețarea GR (GR-22); `verifica-email` generic WP-26 (GR-21) |
| `fe0c041` | — | Reply-To = cine scrie; verificarea SMTP | de portat (WP-15 GR-01; WP-26 GR-21) |
| `d3c4b79` | 18.09 | Banda de recrutare, PDF-uri în galerie, CSS-ul antetului | PDF-urile WP-15 (GR-04); CSS antet deja în CMS (GR-12); conținutul pachetul GR (GR-13, GR-20) |
| `91a332c` `7bbc69e` `fbd2a08` `c2a96bc` `8f4a520` `f1c0161` `496402a` `257a831` `2ac291b` `34be948` `0eb923d` `c55fed0` `b1d9474` `bda8c32` `2305ad7` `8212267` `349bddb` `ae088a9` `79a8df0` `ae9ddc7` `1980696` `ef56a3e` `ba0b3ed` | — | Conținut, imagini și texte ale clientului | pachetul GR (GR-19, GR-20); regula din `496402a` (toate rândurile paginii de start) intră în importator |
| `6ea348f` `d2b7f4d` `466ab8c` `ed45094` `a145317` `d0b0114` `a19bae8` | — | Încărcări de fișiere brute („asset-uri noi”) | nu se portează (rămân în afara oricărui repo) |
