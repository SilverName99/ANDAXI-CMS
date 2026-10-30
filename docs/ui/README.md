# Machetele adminului „B — Compact, pentru lucru intens”

Referința vizuală pentru refacerea adminului (Etapa 1, faza D: WP-30…WP-36). Sunt machete,
nu cod al produsului: nimic din acest dosar nu e încărcat de site sau de admin.

| Fișier | Ce arată |
|---|---|
| `B-Acasa.dc.html` | Acasă: meniul lateral închis la culoare (`#111827`), cifrele zilei, comenzile de lucrat, graficul săptămânii |
| `B-Comenzi.dc.html` | Comenzi: tab-uri pe status cu numărul lor, bara de filtre (perioadă, plată, livrare, ERP), bara acțiunilor în masă (Generează AWB, Confirmă, Trimite în ERP, Tipărește, Schimbă statusul), panoul lateral al comenzii |
| `B-Produs.dc.html` | Editarea unui produs: „Cum apare în Google” cu numărătoarea caracterelor, EAN, TVA 11 % / 21 %, numărul modificărilor nesalvate, nota despre adresa veche care trimite automat la cea nouă |

Ce trebuie știut când le folosești:

- **Format.** Sunt fișierele uneltei de machete (`.dc.html`): HTML cu stiluri scrise pe
  elemente și o culoare de accent ca parametru (`{{accent}}`, implicit `#2563eb`). Scriptul
  uneltei (`support.js`) nu e copiat aici, deci deschise direct în browser arată doar
  structura și textele; culorile, dimensiunile și textele se citesc din sursă.
- **Date inventate.** Clienții, produsele, sumele și firma („Magazin Demo”) sunt inventate
  (verificat la copiere: o singură adresă de email, `@exemplu.ro`, și un telefon de forma
  `0722 000 …`). O machetă nouă pusă aici trebuie să respecte aceeași regulă: repo-ul e public.
- **Fonturile.** Machetele încarcă IBM Plex Sans și IBM Plex Mono de la Google Fonts. Asta
  rămâne doar în fișierele de referință: adminul refăcut le servește din propriul server
  (fără cereri spre Google), ca restul fișierelor lui.
- **Ce decid ele și ce nu.** Arată direcția (densitate: text de 14 px, rânduri de 44 px;
  căutarea cu Ctrl+K, scurtăturile de la `?`, grupurile meniului: Acasă, Comenzi, Produse,
  Clienți, Marketing, Conținut, Rapoarte, Setări). Adresele, câmpurile formularelor și
  pluginurile rămân cele din cod: refacerea schimbă aspectul, nu ce trimite adminul
  (contractul adminului, `tools/dev/contract-admin.php`, din WP-29). Ce se vede doar cu un
  plugin (AWB, ERP, recenzii…) apare doar cu pluginul lui, ca acum.

Sursa: proiectul de machete „ANDAXI CMS – 3 variante de admin”, varianta B (19 octombrie 2026).

## Componentele deja în cod (newsletterul, din Bioscem `42984c1`)

Pașii newsletterului din builder (Conținut → Destinatari → Verificare → Trimite / Programează, WP-R din 30 octombrie) sunt
făcuți deja cu vocabularul de componente al WP-31, în aspectul de azi al adminului. Sunt ale nucleului: orice pagină de admin
le poate folosi. În Faza D se schimbă doar **aspectul** (variabilele și regulile CSS), nu paginile.

Fiecare componentă e un fișier mic în `views/admin/componente/`, chemat cu `admin_componenta('<nume>', [...])`
(`_incarca.php`); primește doar ce i se dă explicit și are clasele `adm-…`. Stilul: `public/assets/css/admin-componente.css`,
cu toate culorile, razele și inelul de focus ca variabile `--adm-…`. Când WP-30 aduce `admin.css` cu tokenurile lui, fie se
leagă variabilele `--adm-…` de tokenurile noi, fie regulile `adm-…` se mută în `admin.css`.

| Piesa | Fișierul | Componenta WP-31 | Ce rămâne în Faza D |
|---|---|---|---|
| Antet de pagină cu „?” (ajutorul) | `antet-pagina.php` | `antet-pagina` + ajutorul din spatele lui „?” | stilul; ajutorul se deschide cu `public/assets/js/admin/componente.js` (clic, Esc) |
| Bara de pași | `bara-pasi.php` | **nouă** (WP-31 n-o are): se adaugă ca `bara-pasi` | stilul |
| Mesajul (info / reușită / avertisment / eroare) | `mesaj.php` | `mesaj` | stilul |
| Panoul lateral (rezumatul, „Trimite un test”, lista de verificare) | `panou-lateral.php` | `panou-lateral` | azi în coloana din dreapta; poate deveni panoul care se deschide din dreapta, fără schimbări în pași |
| Tabelul listelor (cu bife) | `tabel.php` | `tabel` | sortarea și antetul fix nu trebuie aici |
| Starea goală („Nu ai încă nicio listă…”) | `stare-goala.php` | **nouă**: se adaugă ca `stare-goala` | stilul |
| Pastila de stare (Ciornă, Programat, Se trimite, Oprit, Trimis) | clasa `adm-stare` | parte din `tabel` / `tab-uri-cu-numar` | stilul |
| Butoanele | `btn`, `btn-secondary`, `btn-danger` | butoanele shell-ului (WP-30) | se restilizează odată cu `app.css` → `admin.css` |

Pașii stau în `views/admin/emails/newsletter/` (`pas-destinatari.php`: antet-pagina, tabel, stare-goala; `pas-verificare.php`:
antet-pagina, panou-lateral; `pas-trimitere.php`: antet-pagina, mesaj, panou-lateral), așezarea lor în
`public/assets/css/admin-newsletter.css` (clasele `nlp-…`, cu aceleași variabile `--adm-…`), mutarea între pași în
`public/assets/js/admin/newsletter-pasi.js` (WP-35: pasul în adresă, `?pas=N`; salvarea înaintea oricărei trimiteri;
previzualizarea; lista de verificare). Pasul 1 („Conținut”) e builderul (`views/admin/emails/builder.php`, clasele `eb-…`), refăcut
în WP-35; builderul expune `window.ebBuilder`, iar `newsletter-pasi.js` nu umblă în interiorul lui. Plimbarea cu tastatura: pașii
sunt butoane, fiecare element are inel de focus (`--adm-focus`), Esc închide ajutorul. Textele newsletterului (și ajutorul) sunt
doar pe paginile pluginului `newsletter`, deci dispar cu el oprit (test-pluginuri C).

**Pentru contractul adminului (WP-29, `$contractAdminPorturi`)** — adrese, câmpuri și atribute noi: `POST /admin/emails/builder/verificare`
(JSON, nu trimite nimic); `POST /newsletter/unsubscribe/{token}` (cu `confirmare=1`, cu `trimite_link=1` sau „cu un clic”, RFC 8058);
`GET/POST /newsletter/confirmare/{token}`; secțiunile `newsletter-campaign-unschedule` și `newsletter-campaign-pause` pe `POST /admin/emails`;
`ajax=1` la `POST /admin/emails/builder` (JSON, 409 dacă newsletterul a plecat); `pas` (1–4) și `nou=1` la `GET /admin/emails/builder?type=campaign`;
atributele citite de JS: `data-pas`, `data-pas-panou`, `data-mergi-la`, `data-nlp-salveaza`, `data-nlp-confirma`, `data-nlp-trimite`,
`data-ecran`, `.nlp-lista[data-activi]`; setările `newsletter_cron_ultima_rulare`, `newsletter_limita_globala_atinsa`; starea `paused`
(Oprit) a unei campanii, stările `pending` și `unknown` ale unui rând de trimitere, starea `pending` (neconfirmat) a unui abonat.
