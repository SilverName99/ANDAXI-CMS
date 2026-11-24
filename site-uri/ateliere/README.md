# Pachetul `ateliere`

Pachetul de date al unui site WooCommerce (Divi 4, cu magazin, newsletter și calendar de evenimente) mutat de pe WordPress pe
drumul capturat (docs/DEPLOY.md, „Adoptarea unui site WordPress”; docs/MAGAZIN-WC.md). Acesta e **scheletul** pistei A (fundația):
doar configurația generică, fără nicio dată a site-ului real.

| Fișier | Ce ține |
|---|---|
| `tema/tema.json` | cadrul capturat, runtime-ul `divi4@1` și seturile lui (`runtime_seturi`: comportament, magazin_wc, calendar) |
| `wp-compat.json` | adresele ca WordPress, plus `magazin_wc` (rutele, cheile și CSP-ul magazinului în forma WooCommerce) și `mailpoet` (adresele vechi ale MailPoet de pe `/`) |
| `import-wordpress.json` | drumul capturat, dialectul și regulile publice ale regiunilor dinamice (selectori neutri, doar pe coș, checkout, cont, produse și șabloane) |
| `seo.json` | regulile SEO (goale deocamdată) |
| `stripe-aspect.json` | opțiunile Stripe.js ale checkout-ului (`appearance`, `fonts`): locul l-a pus pista A, gol; valorile le scrie pista H (docs/MAGAZIN-WC.md) |
| `ce-se-schimba.md` | ce se schimbă pentru client (se completează de fiecare pistă, în commit-ul care schimbă) |

Licența de dezvoltare a site-ului e `ateliere` (config/plugins.php → `licente_dev`): `tools/dev/php.sh tools/dev/licenta.php ateliere`.

Regulile regiunilor din pachet sunt un schelet: fiecare pistă își verifică selectorii pe captura reală (dovada regiunilor, la import)
odată cu randatorul ei. Până la randatorul ei, un document cu o regiune se refuză la import (NU, cu pista care lipsește).

Tot ce ține de site-ul real stă **doar** în `privat/`, ignorat de git și copiat de mână pe server:

| Fișier din `privat/` | Ce ține | Cine îl citește |
|---|---|---|
| `import-wordpress.json` | `scripturi_aruncate` (sha256 cu motivul), `captura.nonce`, `regiuni` pe paginile al căror nume e al site-ului (magazinul, calendarul), `sabloane` (adresele capturate ale unui articol, ale unei categorii, un termen de căutare, o comandă primită) | `ImportWordPress::reguli()` |
| `captura-structura.json` | `{"<cale>": {"et-last-child": [...]}}` | `ImportWordPress::reguli()` → `captura.structura` |
| `urmarire.json` | identificatorii trackerelor (pista F) | `Urmarire` |
| `paritate.json` | referința și pragurile harness-ului de paritate | `tools/dev/paritate-wp/` |
| `lista-neagra.txt` | termenii care nu au voie în git (domeniul, numele, telefoanele, adresele) | verificarea 11b din `tools/dev/verifica-rute.php` |

Forma lui `privat/import-wordpress.json`, cu valori inventate:

```json
{
    "regiuni": [
        {"document": "/magazin-exemplu/", "selector": "div.et_pb_shop > div.et_pb_module_inner", "regiune": "wc:magazin_lista"},
        {"document": "/calendar-exemplu/", "selector": "div.wcpc-calendar", "regiune": "wcpc:evenimente", "mod": "atribut", "atribut": "data-events"}
    ],
    "sabloane": {"articol": "/articol-exemplu/", "categorie": "/category/exemplu/", "cautare": "/?s=exemplu",
        "comanda": "/checkout/order-received/1234/?key=wc_order_exemplu"},
    "scripturi_aruncate": []
}
```
