# Pachetele de date ale site-urilor

Aici stau datele unui singur site care nu țin de codul comun (nucleul nu conține
nimic scris pentru un anumit client): fișiere JSON, fără PHP executabil. Pe server
ele se copiază în dosarul site-ului (`SITE_DATE_DOSAR` din `.env`, implicit
`site/` în rădăcina codului); codul comun nu citește niciodată direct din `site-uri/`.

| Pachet | Ce conține |
|---|---|
| `bioscem/redirecturi.json` | adresele vechi ale Bioscem (livrarea 1 SEO, 19 octombrie 2026): 113 reguli (103 × 301, 10 × 410) și 6 prefixe 410 |
| `bioscem/seo.json` | etichetele din sitemap (D2/D3), numele și logo-urile pentru Google, `/cos-cumparaturi` |

Formatul și regulile: [docs/SEO.md](../docs/SEO.md). Pachetul inventat al site-ului
de dezvoltare stă în `tools/dev/site-demo/`.
