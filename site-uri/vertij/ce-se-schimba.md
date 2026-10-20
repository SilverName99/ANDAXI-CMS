# Ce se schimbă pentru vertij când trece pe codul comun

Lista se predă clientului la adoptare. Fiecare rând e scris în commit-ul care face schimbarea
(sau care o decide) și spune de ce. Lista e la început: o completează porturile vertij din planul
Etapei 1 (de exemplu ce înseamnă pentru VT emailurile, conturile membrilor și sesiunile adminului,
`port-vertij.md` §8). Tot ce nu e aici rămâne exact ca azi.

## Ce se vede

| Unde | Ce se schimbă | De ce | De când |
|---|---|---|---|
| Toate paginile site-ului, la prima vizită | Apare bannerul de cookie-uri (textul neutru al codului comun, de schimbat din Admin → Setări magazin → Cookies). Microsoft Clarity (statisticile vizitelor), când e pornit în setări, se încarcă doar după ce vizitatorul acceptă; azi se încarcă la oricine, fără întrebare. | Codul comun nu pornește nicio urmărire fără acord. Înghețarea setărilor nu păstrează lipsa bannerului (`cookie_banner_enabled` în `fara_inghetare` din `setari-inghetate.json`), fiindcă fără banner Clarity n-ar mai porni niciodată | înghețarea setărilor (WP-06); comportarea Clarity e a codului comun (`views/layout.php`, `CookieConsent::permiteUrmarire`) |
