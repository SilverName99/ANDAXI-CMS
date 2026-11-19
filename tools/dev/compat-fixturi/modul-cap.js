/**
 * Un modul inventat înregistrat din head (slotul `cap`) cu momentul `imediat`, pentru poarta G-compat: ax-date nu e încă
 * parsat (vine după slot), deci modulul nu pornește, iar diagnosticul spune de ce. Pe site-uri nu ajunge niciodată.
 */
(function () {
    'use strict';
    var ax = window.axCompat;
    var P = window.__axProba;
    if (ax && P) {
        P.capInregistrat = ax.modul('proba-cap', 'imediat', function () {
            P.ordine.push('cap');
        });
        P.capDateValide = ax.dateValide();
    }
}());
