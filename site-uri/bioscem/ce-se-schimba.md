# Ce se schimbă pentru Bioscem când trece pe codul comun

Lista se predă clientului la adoptare. Fiecare rând e scris în commit-ul care face schimbarea
(sau, pentru schimbările de dinainte de această listă, în commit-ul care le-a găsit) și e
dovedit de `tools/dev/compara-cu-baza.sh`, unde e declarat explicit, cu motivul lui
(`$deBioscem` sau, pentru o pagină care azi dă eroare, `$coduriDeBioscem` în
`tools/dev/compara-cu-baza.php`). Tot ce nu e aici rămâne exact ca azi.

## Ce se vede

| Unde | Ce se schimbă | De ce | De când |
|---|---|---|---|
| Admin → Setări magazin → Sitemap XML | Explicația spune și că articolele de blog intră în sitemap (textul e pe alte rânduri). Sitemap-ul în sine e același. | Explicația se construiește din funcțiile site-ului; pe un site fără blog nu mai pomenește articolele | livrarea 1 SEO în codul comun (`c2b9331`), găsită la comparația cu Bioscem `f5eafa3` |
| `/checkout/succes` deschis direct (fără numărul comenzii) | Se deschide pagina cu adresa `checkout/succes` din Admin → Pagini, fără datele unei comenzi; azi adresa dă eroare 500. Pagina de mulțumire de după o comandă (`/checkout/succes/<număr>`) rămâne aceeași. | Codul trimitea starea comenzii (text) în locul totalului (număr) și pagina cădea („Erori moștenite” 7 din `docs/DEZVOLTARE.md`) | reparată în codul comun (`5d808ea`), găsită din nou la revizuirea Bioscem `9f59e79` (WP-R, 20 octombrie) |
| Admin → Pagini, pagina „Acorduri GDPR” (`/acorduri-gdpr`) | O pagină ștearsă de admin rămâne ștearsă; azi revine singură la următoarea pagină de admin. O readuce, ca azi, butonul „Generează pagini necesare” din Setări magazin. Cât timp pagina nu e ștearsă, nu se schimbă nimic. | Conținutul cerut de un plugin se pune o singură dată, când pluginul pornește (pașii de activare din `docs/MIGRARI.md`); readucerea la fiecare cerere anula ștergerea făcută de admin | trecerea pe migrări (WP-05) |
| Admin → Jurnal activitate | La prima cerere de după trecere apar două rânduri „Plugin pornit: pas de activare”, fără administrator: Acorduri GDPR („pagina /acorduri-gdpr exista (neatinsă)”) și ERP („erp_trimite_din exista (neatins)”). Pagina și setarea rămân cum erau. | Pașii de activare ai pluginurilor rulează o singură dată pe bază și fiecare se notează în jurnal | trecerea pe migrări (WP-05) |

## Ce nu se vede

| Unde | Ce se schimbă | De ce |
|---|---|---|
| `/robots.txt` | Regula `Disallow: /cos-cumparaturi` e la finalul listei, nu imediat după regulile coșului. Aceleași reguli; pentru motoarele de căutare ordinea nu contează. | Regula e a site-ului Bioscem, deci stă în pachetul lui (`site-uri/bioscem/seo.json`), nu în cod |
| Cronurile și scripturile de pe server (`storage/sessions`) | Nu mai lasă câte un fișier de sesiune gol la fiecare rulare. Sesiunile vizitatorilor (coșul, contul, adminul) rămân exact ca azi. | Un cron n-are browser, deci n-are ce sesiune să țină; fișierele goale doar ocupau loc până la curățenia de 30 de zile („Erori moștenite” 5 din `docs/DEZVOLTARE.md`). Declarat în capul lui `tools/dev/compara-cu-baza.sh` (nu se vede în HTML) |
| Paginile site-ului și ale adminului | Nu mai trimit bazei de date instrucțiuni de schemă (CREATE, ALTER) la fiecare cerere: dashboard-ul trimitea 110 din cele 144 de instrucțiuni ale lui, acum 0. Paginile arată la fel. | Schema o fac migrările, o singură dată, la trecere (`scripts/migreaza.php --aplica`, `docs/MIGRARI.md`); garda din capul celor 36 de funcții care o făceau din mers le oprește pe o bază la zi. Dovedit de `tools/dev/test-instalare.php` și `tools/dev/smoke.sh` (numără instrucțiunile fiecărei cereri) |
| `/health` | Răspunsul are a doua linie, „schema: la_zi”; cu baza în urma codului (migrări neaplicate) răspunde 503. Administratorul general vede atunci și un banner roșu în admin. | Monitorizarea vede o bază care n-a primit migrările codului |
| `scripts/install.php` | Pe o bază care are administratori refuză (iese cu 1, fără să scrie nimic). Codul de azi ar fi scris setările implicite peste cele ale site-ului. | Instalarea e doar pentru o bază nouă; una existentă se aduce la zi cu `scripts/migreaza.php --aplica` („Erori moștenite” 4 din `docs/DEZVOLTARE.md`, codurile de ieșire) |
| Cronul `scripts/fan-tracking-sync.php` | Nu mai încearcă cele trei ALTER TABLE la fiecare rulare. | Coloanele le fac migrările |
| Setarea `erp_trimite_din` | Pasul de activare al ERP-ului o găsește pusă goală de înghețarea setărilor Bioscem (fără prag: comenzile se trimit în ERP ca azi) și n-o atinge. | Pragul e doar pentru un site care se leagă de ERP abia acum (WP-12, E1); Bioscem e legat de mult |
