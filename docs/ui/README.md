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
