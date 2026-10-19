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
| `bioscem/redirecturi.json` | adresele vechi ale Bioscem (livrarea 1 SEO, 19 octombrie 2026): 113 reguli (103 × 301, 10 × 410) și 6 prefixe 410 |
| `bioscem/seo.json` | etichetele din sitemap (D2/D3), numele și logo-urile pentru Google, `/cos-cumparaturi` |
| `bioscem/ce-se-schimba.md` | ce vede altfel clientul când trece pe codul comun (se predă la adoptare); fiecare rând e declarat și în `tools/dev/compara-cu-baza.php` |

Formatul și regulile: [docs/SEO.md](../docs/SEO.md). Pachetul inventat al site-ului
de dezvoltare stă în `tools/dev/site-demo/`.
