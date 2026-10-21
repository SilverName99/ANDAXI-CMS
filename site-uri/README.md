# Pachetele de date ale site-urilor

Aici stau datele unui singur site care nu țin de codul comun (nucleul nu conține
nimic scris pentru un anumit client): fișiere JSON, fără PHP executabil. Pe server
ele se copiază în dosarul site-ului (`SITE_DATE_DOSAR` din `.env`, implicit
`site/` în rădăcina codului); codul comun nu citește niciodată direct din `site-uri/`.
Doar uneltele de dezvoltare îl folosesc direct: `tools/dev/compara-cu-baza.sh` pornește
codul curent cu `site-uri/bioscem/` când îl compară cu codul Bioscem (care are aceste
date scrise în cod).

| Pachet | Ce conține |
|---|---|
| `bioscem/redirecturi.json` | adresele vechi ale Bioscem: 128 de reguli exacte (118 × 301, 10 × 410) și 6 prefixe 410, adică cele 113 ale livrării 1 SEO, cele 14 × 301 aprobate de proprietar pe 19 octombrie 2026 (Bioscem `9f59e79`) și `/retur-garantie` din 20 octombrie (Bioscem `8c074f1`) |
| `bioscem/seo.json` | etichetele din sitemap (D2/D3), numele și logo-urile pentru Google, `/cos-cumparaturi`; din livrarea 2 SEO: sufixul vechi din titluri, logo-ul din antet și variantele lui WebP, logo-ul de distribuire, livrarea și returul pentru Google, prefixul fluxului Merchant ([docs/SEO.md](../docs/SEO.md), „Datele unui site”) |
| `bioscem/seo-texte.json` | textele Google scrise de mână (prima pagină, 3 categorii, 20 de produse; Bioscem `7c561fa`, `0250480`), pentru `php scripts/seo-texte.php` |
| `bioscem/avertismente-produse.json` | avertismentul celor trei produse refrigerate (Bioscem `0f5f568`), pentru `php scripts/avertisment-produse.php` |
| `bioscem/public/` | fișierele publice ale site-ului (variantele WebP ale logo-ului din antet, Bioscem `a14e803`): **la adoptare se copiază peste `public/` al codului** (nu în dosarul site-ului); `tools/dev/compara-cu-baza.sh` le pune acolo doar pe durata comparației |
| `bioscem/ce-se-schimba.md` | ce vede altfel clientul când trece pe codul comun (se predă la adoptare); fiecare rând e declarat și în `tools/dev/compara-cu-baza.php` |
| `<site>/setari-inghetate.json` | înghețarea setărilor (WP-06): valorile codului vechi al site-ului acolo unde codul comun ar folosi altceva, scrise o singură dată, la adoptare, doar unde lipsesc (pasul `site/<site>/setari-inghetate` al migrărilor). Bioscem din `4254ca4`; Biovitality, vertij și grafoanaytis provizorii până la raportul „live față de repo” din Etapa 0. Generat de `tools/inghetare/genereaza-setari.php`, verificat de `tools/inghetare/verifica-inghetare.php` |
| `<site>/migrari.json` | parametrii migrărilor pe care îi știe doar site-ul (`Schema::parametruSite`), de exemplu procesatorul linkurilor de plată vechi |
| `<site>/privat/` | valorile private ale înghețării (adrese, telefoane: decizia OD-49, repo-ul e public); **ignorat de git**, scris de generator local și copiat de mână pe server lângă pachet |
| `vertij/ce-se-schimba.md` | ca la Bioscem; începută odată cu înghețarea (bannerul de cookie-uri) |
| `biovitality/ce-se-schimba.md`, `grafoanaytis/ce-se-schimba.md` | ca la Bioscem; începute în WP-07 (robots.txt, `/_andaxi/ping`, jurnalul erorilor PHP) |

Cele trei liste de mai sus au, din WP-R din 21 octombrie, și rândurile nucleului din lista Bioscem care li se aplică (trecerea pe migrări: `/health`, `install.php`, nicio instrucțiune de schemă pe cerere, pașii de activare și rândurile lor din jurnal, ștergerile care rămân; schema adăugată de migrări; sesiunea, cronurile, pagina de start a adminului), verificate pe codul fiecărui site la „ultimul commit revizuit”. Ce primesc din alte site-uri odată cu codul comun (livrările SEO și avertismentul pe produs ale Bioscem, reparațiile Bioscem; `docs/de-portat.md`) le trec porturile lor, până la adoptare.

Formatul și regulile: [docs/SEO.md](../docs/SEO.md) (harta adreselor, SEO) și [docs/MIGRARI.md](../docs/MIGRARI.md), „Înghețarea setărilor” și „Parametrii site-ului”. Pachetul inventat al site-ului
de dezvoltare stă în `tools/dev/site-demo/`.
