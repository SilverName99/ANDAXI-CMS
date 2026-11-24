/**
 * Runtime-ul compat, wc/v1: plata cu Stripe Checkout (Payment Element, alegerea monedei); încarcă Stripe.js doar pe checkout și pe paginile metodelor de plată, unde CSP-ul site-ului primește sursele Stripe (CspSite::extensie). Pista H (docs/MAGAZIN-WC.md).
 *
 * Deocamdată un loc rezervat: numele modulului l-a scris pista A în manifest (divi4/v1/sloturi.json → seturi → magazin_wc), iar
 * fișierul nu face nimic, oricare ar fi pagina; pista H îl scrie. Un site îl încarcă doar dacă tema lui cere setul
 * (tema.json → runtime_seturi), deci paginile celorlalte site-uri nu se schimbă.
 */
(function () {
    'use strict';
}());
