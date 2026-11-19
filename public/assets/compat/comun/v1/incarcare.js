/**
 * Runtime-ul compat, comun/v1: încărcarea (adoptarea unui site WordPress, pasul 8a; DECIZIE §2.6.1, INSTRUCTIUNI-BUILDER-C2
 * §8). Scris clean-room, din specificația de comportament `incarcare.md` (pasul 0.C), fără niciun cod de furnizor.
 *
 * Piesa de care depind celelalte module ale runtime-ului. Stă prima în slotul `{{ax:runtime:cap}}` (manifestul
 * divi4/v1/sloturi.json), deci rulează sincron, în head, înaintea primei desenări:
 *  - starea de bază a documentului: clasa `js` pe `<html>`;
 *  - `window.axCompat`, interfața modulelor:
 *      modul(nume, moment, porneste)  înregistrează un modul; `porneste(axCompat)` rulează o singură dată, la momentul cerut:
 *                                     'imediat' (la înregistrare: dintr-un script din corp, deci înainte de DOMContentLoaded),
 *                                     'dom' (într-o sarcină după DOMContentLoaded, ca handlerele „ready” de pe live: acolo se
 *                                     văd structura, fit-vids și containerul dg, OBS-01), 'load' (load-ul ferestrei; după
 *                                     'dom' oricum). Într-un moment, modulele pornesc în ordinea înregistrării, adică în
 *                                     ordinea scripturilor din manifest. Un nume deja înregistrat e ignorat (false);
 *      porneste(moment)               pornește modulele încă nepornite ale unui moment trecut (fără efect peste cele pornite);
 *      date(nume)                     o copie a valorii `nume` din `<script type="application/json" id="ax-date">`, sau
 *                                     undefined (lipsă, ax-date invalid, sau blocul încă neparsat); cheile `__proto__`,
 *                                     `constructor` și `prototype` sunt scoase la citire, oricât de adânc;
 *      dateValide()                   true / false (cu numărarea refăcută); null cât blocul nu e încă parsat (ax-date vine
 *                                     după slotul cap);
 *      adresaSigura(adresa)           true doar pentru o adresă https sau una care rămâne pe originea paginii (rezolvată față
 *                                     de baza documentului): orice adresă luată din configurație trece întâi pe aici;
 *      noteaza(text), diagnostic()    jurnalul intern al runtime-ului (în consolă ajunge doar refuzul lui ax-date, o dată);
 *      cookie.citeste(), cookie.scrie(text), stocare('local' | 'sesiune') → { disponibila, citeste, scrie, sterge }
 *                                     accesul la cookie-uri și la Web Storage fără excepții: în previzualizarea din admin
 *                                     (iframe sandbox="allow-scripts", fără allow-same-origin: origine opacă) accesul direct
 *                                     aruncă SecurityError, deci modulele nu ating direct document.cookie sau localStorage;
 *      tarziu, claseUa                puse de tarziu.js și clase-ua.js.
 *
 * ax-date trebuie să fie exact un element cu acest id în pagină, un `<script type="application/json">` cu un obiect JSON. Fără
 * el, cu mai multe elemente cu id-ul lui (de exemplu unul care îl suprascrie, pus înaintea blocului), cu alt element sau cu JSON
 * invalid, niciun modul nu pornește, nimic nu aruncă, iar consola primește un singur rând. Numărarea se reface înaintea fiecărei
 * porniri, deci un al doilea element apărut mai târziu oprește și modulele de după el. Clasa `js` și trecerea foilor târzii
 * (tarziu.js) vin din head, înaintea blocului, și reproduc ce face live-ul fără configurații, deci nu depind de el.
 * Configurația vine doar din ax-date (citit strict ca JSON); valorile nu devin niciodată cod sau HTML.
 *
 * O a doua includere a fișierului nu face nimic.
 */
(function (w, d) {
    'use strict';

    if (w.axCompat && w.axCompat.versiune) {
        return;
    }

    var MOMENTE = ['imediat', 'dom', 'load'];
    var NUME_MODUL = /^[a-z0-9][a-z0-9-]{0,63}$/;
    var MAX_DIAGNOSTIC = 100;

    var module = [];
    var dupaNume = {};
    var trecute = { dom: false, load: false };
    var jurnal = [];
    var stareDate = null;
    var anuntat = false;

    function noteaza(text) {
        if (jurnal.length < MAX_DIAGNOSTIC) {
            jurnal.push(String(text));
        }
    }

    function mesaj(e) {
        try {
            return e && e.message ? String(e.message) : String(e);
        } catch (x) {
            return '?';
        }
    }

    // Starea de bază: <html class="js">, înainte de prima desenare.
    try {
        d.documentElement.classList.add('js');
    } catch (e) {
        noteaza('html.js: ' + mesaj(e));
    }

    function blocuri() {
        return d.querySelectorAll('[id="ax-date"]');
    }

    /** ax-date refuzat: niciun modul; un rând în diagnostic și, o singură dată pe pagină, unul în consolă. */
    function refuza(motiv) {
        stareDate = { ok: false, obiect: null, el: null };
        noteaza('ax-date: ' + motiv + '; niciun modul nu pornește');
        if (anuntat) {
            return;
        }
        anuntat = true;
        try {
            if (w.console && typeof w.console.warn === 'function') {
                w.console.warn('axCompat: ax-date ' + motiv + '; modulele runtime-ului nu pornesc pe această pagină');
            }
        } catch (e) {
            // fără consolă rămâne rândul din diagnostic
        }
    }

    // Cheile care ar putea polua prototipul unui obiect la o combinare ulterioară (jQuery.extend, Object.assign): scoase la citire.
    function revizor(cheie, valoare) {
        if (cheie === '__proto__' || cheie === 'constructor' || cheie === 'prototype') {
            noteaza('ax-date: cheia „' + cheie + '” scoasă');
            return undefined;
        }
        return valoare;
    }

    /**
     * Citește ax-date o singură dată; null cât încă nu se poate decide. Cât documentul se parsează, un bloc negăsit poate încă
     * să vină (slotul cap e înaintea lui), deci nu se decide nimic; unul găsit e întreg, fiindcă îl citesc doar scripturile
     * sincrone și modulele, care rulează după el. După parsare, lipsa lui e definitivă.
     */
    function citesteDate() {
        if (stareDate !== null) {
            return stareDate;
        }
        var gasite = blocuri();
        if (gasite.length === 0 && d.readyState === 'loading') {
            return null;
        }
        var motiv = '';
        var obiect = null;
        if (gasite.length === 0) {
            motiv = 'lipsește';
        } else if (gasite.length > 1) {
            motiv = 'apare de ' + gasite.length + ' ori';
        } else if (gasite[0].nodeName !== 'SCRIPT' || String(gasite[0].getAttribute('type') || '').toLowerCase() !== 'application/json') {
            motiv = 'nu e un <script type="application/json">';
        } else {
            try {
                obiect = JSON.parse(gasite[0].textContent || '', revizor);
            } catch (e) {
                motiv = 'JSON invalid';
            }
            if (motiv === '' && (obiect === null || typeof obiect !== 'object' || Object.prototype.toString.call(obiect) !== '[object Object]')) {
                motiv = 'nu e un obiect JSON';
            }
        }
        if (motiv !== '') {
            refuza(motiv);
        } else {
            stareDate = { ok: true, obiect: obiect, el: gasite[0] };
        }
        return stareDate;
    }

    /** Un bloc acceptat rămâne valabil doar cât e singurul element cu id-ul lui (verificat înaintea fiecărei porniri). */
    function reverifica() {
        if (stareDate === null || !stareDate.ok) {
            return;
        }
        var gasite = blocuri();
        if (gasite.length !== 1 || gasite[0] !== stareDate.el) {
            refuza(gasite.length > 1 ? 'apare de ' + gasite.length + ' ori' : 'nu mai e în pagină');
        }
    }

    function dateValide() {
        var s = citesteDate();
        if (s === null) {
            return null;
        }
        reverifica();
        return stareDate.ok;
    }

    function date(nume) {
        var s = citesteDate();
        if (s === null || !s.ok || typeof nume !== 'string' || !Object.prototype.hasOwnProperty.call(s.obiect, nume)) {
            return undefined;
        }
        var v = s.obiect[nume];
        return v === undefined ? undefined : JSON.parse(JSON.stringify(v));
    }

    function porneste(moment) {
        if (moment !== 'imediat' && trecute[moment] !== true) {
            return;
        }
        if (dateValide() !== true) {
            return;
        }
        reverifica();
        if (stareDate.ok !== true) {
            return;
        }
        for (var i = 0; i < module.length; i++) {
            var m = module[i];
            if (m.moment !== moment || m.pornit) {
                continue;
            }
            m.pornit = true;
            try {
                m.porneste(api);
            } catch (e) {
                noteaza('modulul ' + m.nume + ': ' + mesaj(e));
            }
        }
    }

    function modul(nume, moment, functie) {
        if (typeof nume !== 'string' || !NUME_MODUL.test(nume) || typeof functie !== 'function'
            || MOMENTE.indexOf(moment) < 0) {
            noteaza('modul refuzat: ' + String(nume));
            return false;
        }
        if (Object.prototype.hasOwnProperty.call(dupaNume, nume)) {
            return false;
        }
        var m = { nume: nume, moment: moment, porneste: functie, pornit: false };
        dupaNume[nume] = m;
        module.push(m);
        if (moment === 'imediat') {
            if (dateValide() === null) {
                noteaza('modulul ' + nume + ': „imediat” înaintea blocului ax-date; nu pornește');
                m.pornit = true;
                return true;
            }
            porneste('imediat');
        } else if (trecute[moment]) {
            porneste(moment);
        }
        return true;
    }

    function cookieCiteste() {
        try {
            return String(d.cookie || '');
        } catch (e) {
            return '';
        }
    }

    function cookieScrie(text) {
        try {
            d.cookie = String(text);
            return true;
        } catch (e) {
            return false;
        }
    }

    function stocare(fel) {
        var s = null;
        try {
            s = fel === 'sesiune' ? w.sessionStorage : w.localStorage;
        } catch (e) {
            s = null;
        }
        return {
            disponibila: !!s,
            citeste: function (cheie) {
                try {
                    return s ? s.getItem(String(cheie)) : null;
                } catch (e) {
                    return null;
                }
            },
            scrie: function (cheie, valoare) {
                try {
                    if (!s) {
                        return false;
                    }
                    s.setItem(String(cheie), String(valoare));
                    return true;
                } catch (e) {
                    return false;
                }
            },
            sterge: function (cheie) {
                try {
                    if (!s) {
                        return false;
                    }
                    s.removeItem(String(cheie));
                    return true;
                } catch (e) {
                    return false;
                }
            }
        };
    }

    function portul(a) {
        return a.port || (a.protocol === 'https:' ? '443' : (a.protocol === 'http:' ? '80' : ''));
    }

    /**
     * O adresă luată din configurație se folosește doar dacă e https sau dacă, rezolvată față de baza documentului, rămâne pe
     * originea paginii (aceeași schemă http(s), gazdă și port). Restul (javascript:, data:, //altă-gazdă, http spre altă gazdă,
     * text care nu e adresă) e refuzat. CSP-ul paginii rămâne a doua gardă.
     */
    function adresaSigura(adresa) {
        if (typeof adresa !== 'string' || adresa === '') {
            return false;
        }
        try {
            var a = d.createElement('a');
            a.href = adresa;
            a.href = a.href;
            if (a.protocol === 'https:') {
                return true;
            }
            var b = d.createElement('a');
            b.href = d.baseURI || w.location.href;
            b.href = b.href;
            return a.protocol === 'http:' && a.protocol === b.protocol && a.hostname === b.hostname && portul(a) === portul(b);
        } catch (e) {
            return false;
        }
    }

    var api = {
        versiune: 'comun/v1',
        modul: modul,
        porneste: porneste,
        date: date,
        dateValide: dateValide,
        adresaSigura: adresaSigura,
        noteaza: noteaza,
        diagnostic: function () {
            return jurnal.slice();
        },
        cookie: { citeste: cookieCiteste, scrie: cookieScrie },
        stocare: stocare
    };
    w.axCompat = api;

    // Momentul 'dom': o sarcină după DOMContentLoaded (după toate handlerele lui), nu în timpul lui.
    function laDom() {
        if (trecute.dom) {
            return;
        }
        trecute.dom = true;
        porneste('dom');
    }

    function dupaDcl() {
        w.setTimeout(laDom, 0);
    }

    function laLoad() {
        laDom();
        if (trecute.load) {
            return;
        }
        trecute.load = true;
        porneste('load');
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', dupaDcl);
    } else {
        trecute.dom = true;
    }
    if (d.readyState === 'complete') {
        trecute.load = true;
    } else {
        w.addEventListener('load', laLoad);
    }
}(window, document));
