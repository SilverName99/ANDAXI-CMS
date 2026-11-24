# Ce se schimbă pentru site când trece de pe WordPress pe codul comun

Lista se predă proprietarului la adoptare. Implicitul e „identic”: paginile ies din documentele capturate de pe site-ul de azi, iar
părțile care se schimbă după vizitator (coșul, checkout-ul, contul, listele de articole, calendarul) din regiunile dinamice, în
markupul de azi. Fiecare abatere e scrisă aici, cu motivul, în commit-ul pistei care o face (docs/MAGAZIN-WC.md).

## Ce se vede

| ID | Unde | Ce se schimbă | De ce |
|---|---|---|---|
| VC-PLATA-001 | Scripturile Stripe | Se încarcă doar pe checkout și pe paginile metodelor de plată din cont, nu și pe paginile de produs și pe coș. Politica de securitate a paginilor (CSP) permite sursele Stripe doar acolo. | Pe celelalte pagini nu e nevoie de ele (butoanele de plată rapidă sunt oprite și azi). |

## Ce nu se vede (dar se verifică)

| Unde | Ce se schimbă | De ce |
|---|---|---|
| Paginile coșului, ale checkout-ului și ale contului | Nu se țin niciodată în cache (`Cache-Control: no-store, private`); celelalte pagini da, fără nicio dată a vizitatorului în ele. | Datele unui vizitator nu ajung niciodată la altul. |
