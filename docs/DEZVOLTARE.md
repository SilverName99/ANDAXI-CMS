# Dezvoltare ANDAXI-CMS

## De unde pornește codul

Codul a fost copiat din site-ul Bioscem (`Nou-site-bioscem`, branch
`claude/migrate-bioscem-site-bz38y4`, commit
`11b104043ae87ab2e05ab6cfb10cd460939ac303`, 8 octombrie 2026), cu `git archive`,
deci doar fișierele urmărite în git: fără sesiuni, cache, încărcări sau `.env`.

Primul commit din acest repo („Punct de plecare”) este codul Bioscem așa cum
este, cu excepțiile de mai jos. Orice schimbare de comportament vine în
commit-uri separate, ca diferențele față de Bioscem să se poată citi.

### Singurele diferențe față de Bioscem în commit-ul de plecare

- **Parola de admin de rezervă a fost scoasă.** `config/app.php` avea o parolă
  implicită scrisă în cod, aceeași pe toate site-urile, folosită de
  `scripts/install.php` când `.env` nu avea `ADMIN_DEFAULT_PASSWORD`. Acum nu mai
  există nicio valoare de rezervă: `install.php` refuză să pornească (cod de
  ieșire 1), înainte să atingă baza de date, dacă `ADMIN_DEFAULT_PASSWORD`
  lipsește sau are sub 10 caractere. `.env.example` nu mai conține parola.
- `README.md` e nou (scurt); README-ul Bioscem nu a fost copiat (descria
  instalarea și cronurile site-ului Bioscem, cu căile lui de pe server).
- `.gitignore` acoperă acum tot ce scrie aplicația la rulare: conținutul din
  `storage/` (sesiuni, cache, jurnale, copii, importuri), `public/uploads/`,
  sitemap-ul generat în `public/` și `public/assets/.htaccess`. Folderele din
  `storage/` există goale, cu `.gitkeep`.

### Ce NU a fost copiat și de ce

| Ce | De ce |
|---|---|
| `.git` (istoricul Bioscem) | repo nou; originea e notată mai sus |
| `storage/sessions/*` (≈1.700 fișiere în clona locală) | sesiunile pot conține date de clienți (coșuri, conturi) |
| `storage/cache/*`, `storage/import/*` | cache generat; dump-uri WordPress cu date reale |
| `public/uploads/*`, sitemap-ul generat | fișierele încărcate ale clientului; nu erau în git oricum |
| `README.md` Bioscem | specific site-ului Bioscem (căi pe server, cronuri, „de actualizat înainte de lansare”) |
| `docs/templates/*` | tema Bioscem (header, footer, pagini, cu telefoanele firmei); nu e folosită de cod; va deveni pachetul de temă Bioscem, în afara nucleului |
| `scripts/import-bioscem.php` | import unic din magazinul WooCommerce Bioscem (adresa bioscem.ro scrisă în cod) |
| `scripts/import-wordpress-users.php` | import unic al clienților din WordPress-ul Bioscem; locul lui e unealta internă „Migrare din WordPress” |
| `scripts/import-brand-taguri-wp.php` | import unic de mărci/etichete/SEO dintr-un dump WordPress Bioscem |
| `scripts/import-greutati.php` | import unic de greutăți de produse Bioscem |
| `scripts/clean-product-names.php` | curățare unică a numelor de produse importate |
| `scripts/analyze-product-sections.php`, `scripts/apply-product-sections.php` | împărțirea unică a descrierilor WooCommerce în secțiuni (folosesc doar ele între ele) |
| `scripts/set-product-template.php`, `scripts/set-similar-products.php` | operații în masă făcute o dată pe catalogul Bioscem |
| `scripts/genereaza-seo-produse.php` | generare unică de texte SEO pentru produsele Bioscem |
| `scripts/bbd-stock-debug.php`, `scripts/cos-debug.php`, `scripts/erp-stock-debug.php` | scripturi de depanare făcute pentru incidente Bioscem |
| `scripts/test-precomanda.php` | test făcut pe baza Bioscem (scrie și șterge comenzi); va fi înlocuit de testele automate |
| `scripts/verifica-precomanda-produs.php`, `scripts/verifica-telefon-comanda.php` | verificări punctuale pe date Bioscem |

Niciunul dintre fișierele de mai sus nu este folosit de cod (verificat cu
`git grep` pe numele lor înainte de copiere). `src/` a fost copiat întreg:
`WordPressDumpReader` și `WordPressPassword` le folosește adminul (importul de
utilizatori din backup WordPress, login cu parole vechi WordPress);
`ProductSections` nu mai e folosit de nimic după scoaterea celor două scripturi
de secțiuni și se mută la unealta internă „Migrare din WordPress” când o facem.

Scripturile păstrate în `scripts/`: `install.php`, `seed.php`, cronurile
(`erp-sync.php`, `bt-ipay-sync.php`, `fan-tracking-sync.php`,
`fan-lockers-sync.php`, `fan-nomenclator-sync.php`, `newsletter-campaigns.php`,
`abandoned-cart-emails.php`) și `diagnostic.php` (arată ce `.env` și ce bază vede
cronul, fără secrete).

### Secrete

Am căutat în tot codul copiat chei, parole și tokenuri (Stripe, SendGrid,
chei private, IBAN, JWT, atribuiri de tip `password = '...'`). Singurul secret
găsit a fost parola de rezervă de mai sus. Celelalte secrete ale unui site stau
în `.env` (BT iPay) și în tabelul `settings` (FAN, SMTP, SendGrid, Stripe,
EuPlătesc, ERP, Google, tawk.to), deci nu sunt în cod.
