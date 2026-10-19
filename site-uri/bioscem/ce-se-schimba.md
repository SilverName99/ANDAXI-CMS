# Ce se schimbă pentru Bioscem când trece pe codul comun

Lista se predă clientului la adoptare. Fiecare rând e scris în commit-ul care face schimbarea
(sau, pentru schimbările de dinainte de această listă, în commit-ul care le-a găsit) și e
dovedit de `tools/dev/compara-cu-baza.sh`, unde e declarat explicit, cu motivul lui
(`$deBioscem` în `tools/dev/compara-cu-baza.php`). Tot ce nu e aici rămâne exact ca azi.

## Ce se vede

| Unde | Ce se schimbă | De ce | De când |
|---|---|---|---|
| Admin → Setări magazin → Sitemap XML | Explicația spune și că articolele de blog intră în sitemap (textul e pe alte rânduri). Sitemap-ul în sine e același. | Explicația se construiește din funcțiile site-ului; pe un site fără blog nu mai pomenește articolele | livrarea 1 SEO în codul comun (`c2b9331`), găsită la comparația cu Bioscem `f5eafa3` |

## Ce nu se vede

| Unde | Ce se schimbă | De ce |
|---|---|---|
| `/robots.txt` | Regula `Disallow: /cos-cumparaturi` e la finalul listei, nu imediat după regulile coșului. Aceleași reguli; pentru motoarele de căutare ordinea nu contează. | Regula e a site-ului Bioscem, deci stă în pachetul lui (`site-uri/bioscem/seo.json`), nu în cod |
