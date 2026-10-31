# Pachetul Bioscem

Datele site-ului Bioscem care nu țin de codul comun (formatul: [docs/PACHETE.md](../../docs/PACHETE.md)). Cu ele, codul
comun dă paginile, emailurile și setările codului Bioscem la „ultimul commit revizuit” (docs/de-portat.md); dovada e
comparația `tools/dev/compara-cu-baza.sh` (poarta G-cmp) pe baza de dezvoltare în forma Bioscem.

| Fișier | Ce e |
|---|---|
| `site.json` | constantele codului vechi: numele cookie-ului acordului (`bioscem_cookie_consent`), cheia ferestrei de bun venit închise și sarea semnăturii newsletterului (`bioscem`) |
| `tema/tema.json`, `tema/pagina-succes.css` | logo-ul sertarului de meniu mobil, iconițele categoriilor, blocul de stiluri al paginii de mulțumire și aspectul emailurilor newsletterului (logo, culori; WP-R), ca în codul Bioscem |
| `seo.json`, `redirecturi.json`, `seo-texte.json`, `seo-continut.json`, `avertismente-produse.json`, `poze-optimizate.json`, `public/` | livrările SEO ale Bioscem: regulile, harta adreselor vechi, textele, pozele refăcute (WebP) și logo-ul în WebP |
| `setari-inghetate.json` | înghețarea setărilor (ce făcea codul Bioscem fără rând în bază); valorile private (adrese, telefon, reprezentantul) stau în `privat/`, în afara git-ului (OD-49) |
| `migrari.json` | parametrii migrărilor |
| `pluginuri.json` | pluginurile Bioscem, copia contractului doar pentru unelte (`tools/dev/licenta.php site=bioscem`); licența vine numai de la CRM |
| `ce-se-schimba.md` | ce vede altfel clientul pe codul comun (se predă la adoptare) |
| `pachet.json` | manifestul: fiecare fișier cu sha256-ul lui (`tools/dev/verifica-pachet.php --actualizeaza-manifest`) |

Baza de dezvoltare în forma Bioscem: `tools/dev/instaleaza.sh --site=bioscem` (`cms_dev_bioscem`, doar date inventate; o
face și comparația, când lipsește). Testul pachetului: `tools/dev/php.sh tools/dev/test-pachet-site.php bioscem` (G-pachet).
Pe server, înaintea adoptării: `$PHP tools/sonda/pachet-site.php --site=bioscem` (V1–V12, doar numere; docs/VERIFICARE.md).
