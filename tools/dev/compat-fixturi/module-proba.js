/**
 * Module inventate pentru poarta G-compat (tools/dev/compat-browser.cjs): stau în slotul `final` al paginilor de probă și
 * verifică interfața lui `axCompat` (incarcare.js) — ordinea pornirii în fiecare moment, izolarea unui modul care aruncă,
 * înregistrarea dublă ignorată, un modul înregistrat dintr-un alt modul, momentul `imediat`, copiile din `date()`. Notează în
 * `window.__axProba.ordine` (observator.js). Pe site-uri nu ajunge niciodată.
 */
(function () {
    'use strict';
    var ax = window.axCompat;
    var P = window.__axProba;
    if (!ax || !P) {
        return;
    }
    ax.modul('proba-f', 'imediat', function () {
        P.ordine.push('f:' + document.readyState);
    });
    ax.modul('proba-a', 'dom', function (api) {
        P.ordine.push('a:' + (api === ax) + ':' + (ax.claseUa ? ax.claseUa.clase !== null : 'fara-clase-ua'));
    });
    ax.modul('proba-b', 'dom', function () {
        P.ordine.push('b');
        throw new Error('proba: modul care aruncă');
    });
    ax.modul('proba-c', 'load', function () {
        P.ordine.push('c:' + document.readyState);
    });
    ax.modul('proba-d', 'dom', function () {
        P.ordine.push('d');
        ax.modul('proba-e', 'dom', function () {
            P.ordine.push('e');
        });
    });
    P.dublu = ax.modul('proba-a', 'dom', function () {
        P.ordine.push('a-dublu');
    });
    P.refuzate = [ax.modul('Proba Rea', 'dom', function () {}), ax.modul('proba-g', 'curand', function () {}),
        ax.modul('proba-h', 'dom', 'nu e funcție')];
    var x = ax.date('proba');
    if (x && x.lista) {
        x.lista.push(99);
    }
    P.copie = ax.date('proba');
}());
