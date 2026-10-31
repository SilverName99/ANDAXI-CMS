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

## Componentele (WP-31)

Paginile adminului se refac (WP-32 … WP-35) din piese mici, aceleași peste tot. Fiecare e un fișier în
`views/admin/componente/`, chemat cu `admin_componenta('<nume>', [...])` (`_incarca.php`, încărcat de
`views/admin/layout.php`); primește doar ce i se dă explicit și are clasele `adm-…`. Stilul: `public/assets/css/admin.css`
(variabilele `--ax-…`; numele vechi `--adm-…` trimit la ele), comportamentul: `public/assets/js/admin/` (încărcate pe
fiecare pagină de admin). Toate se văd împreună, cu date inventate, pe pagina de probă **`/admin/componente`** (doar
administratorul general, fără intrare în meniu; `App\Http\Controllers\ComponenteAdminController`).

| Componenta | Ce e | Comportamentul (JS) |
|---|---|---|
| `antet-pagina` | titlul paginii (`'nivel' => 1`) sau al unui pas, propoziția de sub el, butoanele din dreapta, ajutorul din spatele lui „?” | `componente.js`: clic, Enter, Esc |
| `tab-uri-cu-numar` | tab-urile unei liste (statusuri), fiecare cu numărul lui și, opțional, culoarea; sunt linkuri, deci filtrul stă în adresă | — |
| `bara-filtre` | formular GET: căutare (cu o tastă, de exemplu `/`), liste, date, căsuțe (`bifa`, WP-33); „Resetează” | `filtre.js`: aplicare imediată (`'automat' => true`), tasta căutării |
| `tabel` | tabelul listelor: coloane sortabile (link cu `?sort=&dir=`, restul adresei păstrat), antet fix, rânduri dense, bife (din WP-33 și o bifă dezactivată pe rând, `bifa_atribute`, și clase în plus, `clasa`) | `tabel.js`: „toate” (fără bifele dezactivate), Shift+clic, Esc golește |
| `bara-actiuni-masa` | bara care apare cu rânduri bifate: câte, butoanele acțiunilor (fiecare `formaction` spre o adresă care **există deja**), „Renunță”; din WP-33 și câmpurile formularului (`campuri`, de exemplu statusul nou), adresa formularului, atribute `data-*` și butoane care nu trimit (Tipărește) | `tabel.js`: arată/ascunde, confirmarea, Esc |
| `panou-lateral` | `'mod' => 'coloana'` (lângă conținut, ca în pașii newsletterului) sau `'sertar'` (din dreapta, peste pagină; corpul se poate încărca de la o adresă de detalii existentă: `data-adm-panou-sursa`; răspunsul ei, fără cadru: layout-ul `admin/fragment`) | `panou.js`: deschide, Esc, × sau fundalul închid, Tab rămâne în panou, focusul revine |
| `sectiune-formular` | o parte a unui formular lung: titlu, propoziție, câmpuri; opțional pliabilă (`<details>`) | — |
| `card` | o cifră a zilei sau un rezumat, cu ton (ok, atenție, eroare, info); opțional tot cardul e link | — |
| `mesaj` | informare, reușită, avertisment, eroare (și mesajul de după o salvare, în cadru) | — |
| `stare-goala` | ce vede omul când lista e goală și ce poate face | — |
| `bara-pasi` | pașii unui flux (newsletterul: Conținut → Destinatari → Verificare → Trimite) | al paginii (`newsletter-pasi.js`) |
| pastila `adm-stare`, `adm-numar`, `adm-mono` | starea (Ciornă, Programat, Se trimite, Oprit, Trimis), un număr, cifre egale | — |

**Unificarea cu newsletterul.** Primele șase componente (antet, bara de pași, tabel, panou, mesaj, stare goală) au venit cu
newsletterul (Bioscem `42984c1`, portat în `77759ef`), cu propriul `admin-componente.css`. WP-31 le-a extins, nu le-a
dublat: aceleași fișiere și clase, aceleași date primite (pașii newsletterului arată la fel), plus opțiunile noi; stilul
s-a mutat în `admin.css`, iar `admin-componente.css` s-a scos. Un commit Bioscem viitor care schimbă `admin-componente.css`
se portează în secțiunea 4 din `admin.css` (`docs/de-portat.md`).

**Tastatura (G-tastatura).** `tools/dev/php.sh tools/dev/contract-admin.php --plimbare` (`tools/dev/plimbare-tastatura.cjs`)
trece cu Tab prin fiecare pagină a contractului și prin `/admin/componente`, pe licențele `toate`, `bioscem`, `grafoanaytis`:
focusul vizibil pe fiecare element, „?” deschide lista scurtăturilor și Esc o închide, panoul lateral se închide cu Esc și
focusul revine, iar pe pagina de probă bara acțiunilor în masă, tasta `/` și filtrele păstrate la Înapoi. O scurtătură
nouă a unei pagini (WP-33, WP-36) se adaugă în lista de la „?” prin `$adminScurtaturiPagina` (pusă de vederea paginii) și se
verifică într-o plimbare a paginii, cu pluginul ei pornit și oprit (lista comenzilor: `tools/dev/plimbare-comenzi.cjs`, din
`tools/dev/test-admin-faza-d.php`).

**Pentru contractul adminului (WP-29, `$contractAdminPorturi`)** — adrese, câmpuri și atribute noi: `POST /admin/emails/builder/verificare`
(JSON, nu trimite nimic); `POST /newsletter/unsubscribe/{token}` (cu `confirmare=1`, cu `trimite_link=1` sau „cu un clic”, RFC 8058);
`GET/POST /newsletter/confirmare/{token}`; secțiunile `newsletter-campaign-unschedule` și `newsletter-campaign-pause` pe `POST /admin/emails`;
`ajax=1` la `POST /admin/emails/builder` (JSON, 409 dacă newsletterul a plecat); `pas` (1–4) și `nou=1` la `GET /admin/emails/builder?type=campaign`;
atributele citite de JS: `data-pas`, `data-pas-panou`, `data-mergi-la`, `data-nlp-salveaza`, `data-nlp-confirma`, `data-nlp-trimite`,
`data-ecran`, `.nlp-lista[data-activi]`; setările `newsletter_cron_ultima_rulare`, `newsletter_limita_globala_atinsa`; starea `paused`
(Oprit) a unei campanii, stările `pending` și `unknown` ale unui rând de trimitere, starea `pending` (neconfirmat) a unui abonat.
