# Pachetul Biovitality

Datele site-ului Biovitality care nu țin de codul comun (formatul: [docs/PACHETE.md](../../docs/PACHETE.md)). Biovitality
e primul site care trece pe codul comun (decizia B5 a proprietarului, 27 octombrie 2026), deci pachetul are tot ce îi
trebuie la adoptare din ce nucleul nu mai scrie în cod.

| Fișier | Ce e |
|---|---|
| `site.json` | constantele codului vechi: numele cookie-ului acordului (`bv_cookie_consent`) și sarea semnăturii newsletterului (`biovitality`); fereastra de bun venit nu există pe site, deci nici cheia ei |
| `tema/tema.json` | logo-ul sertarului de meniu mobil (`/uploads/gallery/biovitality-logo.png`, „BioVitality”), ca în `views/layout.php` al codului vechi; restul cheilor la implicitul nucleului |
| `setari-inghetate.json` | înghețarea setărilor (numerotarea „BV”, Stripe, `fan_kg_max_colet` = 0, acordul GDPR, câmpurile înregistrării …); valorile private (adrese, telefon, reprezentantul) stau în `privat/`, în afara git-ului (OD-49) |
| `migrari.json` | parametrii migrărilor: linkurile de plată vechi sunt Stripe |
| `ce-se-schimba.md` | ce vede altfel clientul pe codul comun (se predă la adoptare) |

Ce **nu** e aici, și de ce:

- `redirecturi.json`, `seo.json`: codul Biovitality nu are nicio hartă a adreselor vechi și nicio regulă SEO scrisă în
  cod (n-are livrările SEO ale Bioscem), deci nu e nimic de păstrat. Ce primește Biovitality din SEO la adoptare se
  hotărăște în Faza E (WP-37…WP-40) și se trece atunci în pachet și în `ce-se-schimba.md`.
- `tema/pagina-succes.css`, `iconite_categorii`: codul Biovitality nu are nici blocul de stiluri al paginii de mulțumire,
  nici iconițele categoriilor (`{{product_categories}}`), deci rămân cele ale nucleului.
- banda partenerilor (`partners-marquee.js`, `.bv-partners__*`), restul temei: tema completă a site-ului, în Etapa 4 (P5).
- `pluginuri.json`: lista pluginurilor stă azi în `config/plugins.php` → `licente_dev.biovitality`; fișierul (și
  `tools/dev/licenta.php site=<slug>`) vine odată cu formatul lui, în WP-14, după numărătoarea cheilor de pe site-ul live (WP-10).

Baza de dezvoltare în forma Biovitality: `tools/dev/instaleaza.sh --site=biovitality` (`cms_dev_biovitality`, doar date
inventate); testul pachetului: `tools/dev/php.sh tools/dev/test-pachet-site.php biovitality` (poarta G-pachet).
