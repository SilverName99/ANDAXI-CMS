/**
 * Runtime-ul compat, comun/v1: foile încărcate târziu (adoptarea unui site WordPress, pasul 8a; DECIZIE §2.6.1, INSTRUCTIUNI-
 * BUILDER-C2 §6 și §8). Scris clean-room, din specificația de comportament `tarziu.md` (pasul 0.C), fără niciun cod de furnizor.
 *
 * Divi 4 încarcă o parte din CSS-ul paginii ca `<link rel="preload" as="style">` și îl trece pe `stylesheet` când foaia a
 * venit. Importul (dialectul divi4) a înlocuit handlerul inline al fiecărui astfel de link cu atributul `data-ax-tarziu`, deci
 * CSP-ul site-ului nu cere nici `'unsafe-inline'`, nici `'unsafe-hashes'`. Fișierul acesta e rezerva lui, al doilea în slotul
 * `{{ax:runtime:cap}}` (după incarcare.js), sincron, în head:
 *  1. fiecare `link[data-ax-tarziu]` cu `rel="preload"` devine `rel="stylesheet"` la evenimentul `load` al foii, pe loc, fără
 *     să se mute (cascada păstrează ordinea din document); o foaie care dă eroare (404) rămâne `preload`. Ascultătorul e pe
 *     document, în faza de captură, deci prinde fiecare link parsat după acest script. Un link parsat înaintea lui, a cărui foaie
 *     a venit deja, trece pe loc, după Resource Timing (un status ≥ 400 cunoscut = nu); dacă foaia lui dă totuși eroare ca
 *     stylesheet (statusul nu se vede în previzualizarea cu origine opacă), linkul se întoarce pe `preload`. Un browser fără
 *     `preload` nu le-ar încărca niciodată: acolo trecerea se face imediat (și la DOM gata, pentru restul);
 *  2. o singură dată, înainte de DOMContentLoaded, ca pe live (OBS-01, OBS-02: odată cu prima desenare, după foile din head):
 *     la primul cadru de animație (head-ul e parsat atunci) sau, fără niciun cadru până atunci (o filă ascunsă), când documentul
 *     devine „interactive” — fiecare adresă din lista `file` (configurația foilor „late” a Divi, mutată de import în ax-date;
 *     deci doar cu ax-date valid) primește un `<link rel="stylesheet" id="et-dynamic-late-css">`, imediat după
 *     `<style id="divi-style-inline-inline-css">` (altfel imediat înaintea lui `<style id="divi-dynamic-critical-inline-css">`;
 *     fără niciunul, nimic, cu un rând în diagnostic). Doar adrese care trec de `axCompat.adresaSigura` (https sau pe originea
 *     paginii); o adresă deja inserată nu se mai inserează.
 *
 * Pe paginile servite de cadrul capturat, linkurile acestea vin deja ca `<link rel="stylesheet">` (CadruCapturat::foiTarziiCaFoi, la
 * răspunsul web: aspectul paginii nu depinde de acest script); trecerea de mai sus rămâne rezerva pentru un document care le-ar avea
 * încă (P1, previzualizări, porțile).
 * Preload-urile nu țin pe loc evenimentul `load` al ferestrei: pe o rețea lentă, foile târzii se pot aplica după el, ca pe live.
 * Atributul `data-ax-tarziu` rămâne pe link (pe live rămânea atributul `onload`). Starea: `axCompat.tarziu.stare()`;
 * `axCompat.tarziu.aplica()` reia ambele treceri, fără efect peste ce s-a făcut. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.tarziu) {
        return;
    }

    var SELECTOR = 'link[data-ax-tarziu]';
    var ID_LATE = 'et-dynamic-late-css';
    var DUPA = 'divi-style-inline-inline-css';
    var INAINTE = 'divi-dynamic-critical-inline-css';
    var esuate = [];
    var dinTiming = [];
    var inserate = [];

    function mesaj(e) {
        return e && e.message ? e.message : String(e);
    }

    function relEste(link, valoare) {
        return String(link.getAttribute('rel') || '').replace(/^\s+|\s+$/g, '').toLowerCase() === valoare;
    }

    function alNostru(link) {
        return !!link && link.nodeName === 'LINK' && link.hasAttribute('data-ax-tarziu');
    }

    function asteapta(link) {
        return alNostru(link) && relEste(link, 'preload');
    }

    function inLista(lista, link) {
        for (var i = 0; i < lista.length; i++) {
            if (lista[i] === link) {
                return i;
            }
        }
        return -1;
    }

    function treceLink(link) {
        if (!asteapta(link) || inLista(esuate, link) >= 0) {
            return false;
        }
        link.setAttribute('rel', 'stylesheet');
        return true;
    }

    /** O foaie cerută înaintea scriptului și deja venită (Resource Timing); un status ≥ 400 cunoscut = nu. */
    function venitaDeja(link) {
        try {
            var p = w.performance;
            if (!p || typeof p.getEntriesByName !== 'function' || !link.href) {
                return false;
            }
            var intrari = p.getEntriesByName(link.href);
            for (var i = 0; i < intrari.length; i++) {
                var x = intrari[i];
                if (x.entryType !== 'resource' || !(x.responseEnd > 0)) {
                    continue;
                }
                return !(typeof x.responseStatus === 'number' && x.responseStatus >= 400);
            }
        } catch (e) {
            ax.noteaza('tarziu (Resource Timing): ' + mesaj(e));
        }
        return false;
    }

    var suportaPreload = false;
    try {
        var proba = d.createElement('link');
        suportaPreload = !!(proba.relList && typeof proba.relList.supports === 'function' && proba.relList.supports('preload'));
    } catch (e) {
        suportaPreload = false;
    }

    /** Linkurile deja în document: fără preload în browser, toate; altfel cele a căror foaie a venit (Resource Timing). */
    function treceCeleDinDocument() {
        var linkuri = d.querySelectorAll(SELECTOR);
        for (var i = 0; i < linkuri.length; i++) {
            var l = linkuri[i];
            if (!asteapta(l) || inLista(esuate, l) >= 0) {
                continue;
            }
            if (!suportaPreload) {
                treceLink(l);
            } else if (venitaDeja(l) && treceLink(l)) {
                dinTiming.push(l);
            }
        }
    }

    d.addEventListener('load', function (e) {
        try {
            if (asteapta(e.target)) {
                treceLink(e.target);
            }
        } catch (x) {
            ax.noteaza('tarziu (load): ' + mesaj(x));
        }
    }, true);
    d.addEventListener('error', function (e) {
        try {
            var l = e.target;
            if (!alNostru(l) || inLista(esuate, l) >= 0) {
                return;
            }
            var k = inLista(dinTiming, l);
            if (asteapta(l)) {
                esuate.push(l);
            } else if (k >= 0 && relEste(l, 'stylesheet')) {
                // Trecută după Resource Timing, dar foaia nu se aplică: înapoi pe preload, ca linkul a cărui foaie n-a venit.
                dinTiming.splice(k, 1);
                l.setAttribute('rel', 'preload');
                esuate.push(l);
            }
        } catch (x) {
            ax.noteaza('tarziu (error): ' + mesaj(x));
        }
    }, true);

    function deJaInserat(adresa) {
        var existente = d.querySelectorAll('link[id="' + ID_LATE + '"]');
        for (var i = 0; i < existente.length; i++) {
            if (existente[i].getAttribute('href') === adresa) {
                return true;
            }
        }
        return false;
    }

    function stil(id) {
        var el = d.getElementById(id);
        return el && el.nodeName === 'STYLE' && el.parentNode ? el : null;
    }

    function insereazaLate() {
        var file = ax.date('file');
        if (file === undefined) {
            return;
        }
        if (Object.prototype.toString.call(file) !== '[object Array]') {
            ax.noteaza('tarziu: „file” nu e o listă');
            return;
        }
        var dupa = stil(DUPA);
        var inainte = stil(INAINTE);
        var parinte = dupa ? dupa.parentNode : (inainte ? inainte.parentNode : null);
        if (!parinte) {
            if (file.length > 0) {
                ax.noteaza('tarziu: lipsesc ' + DUPA + ' și ' + INAINTE + '; foile „file” nu se inserează');
            }
            return;
        }
        var reper = dupa ? dupa.nextSibling : inainte;
        for (var i = 0; i < file.length; i++) {
            var adresa = file[i];
            if (!ax.adresaSigura(adresa)) {
                ax.noteaza('tarziu: adresă refuzată în „file” (poziția ' + i + ')');
                continue;
            }
            if (deJaInserat(adresa)) {
                continue;
            }
            var link = d.createElement('link');
            link.setAttribute('rel', 'stylesheet');
            link.setAttribute('id', ID_LATE);
            link.setAttribute('href', adresa);
            parinte.insertBefore(link, reper);
            inserate.push(adresa);
        }
    }

    var lateGata = false;
    var cadruCerut = false;

    /**
     * Încercarea inserării foilor din `file`. Cât documentul se parsează și blocul ax-date sau reperele nu sunt încă acolo (un
     * head închis devreme de parser), se reîncearcă la cadrul următor; la „interactive” se decide definitiv.
     */
    function incearcaLate(definitiv) {
        if (lateGata) {
            return;
        }
        var valide = ax.dateValide();
        if (!definitiv && (valide === null || (d.readyState === 'loading' && !stil(DUPA) && !stil(INAINTE)))) {
            cereCadru();
            return;
        }
        lateGata = true;
        if (valide === true) {
            insereazaLate();
        }
    }

    function cereCadru() {
        if (cadruCerut || lateGata || typeof w.requestAnimationFrame !== 'function') {
            return;
        }
        cadruCerut = true;
        w.requestAnimationFrame(function () {
            cadruCerut = false;
            incearcaLate(false);
        });
    }

    ax.tarziu = {
        stare: function () {
            var linkuri = d.querySelectorAll(SELECTOR);
            var s = { total: linkuri.length, aplicate: 0, asteptate: 0, esuate: 0, inserate: inserate.slice(), preload: suportaPreload };
            for (var i = 0; i < linkuri.length; i++) {
                if (relEste(linkuri[i], 'stylesheet')) {
                    s.aplicate++;
                } else if (inLista(esuate, linkuri[i]) >= 0) {
                    s.esuate++;
                } else {
                    s.asteptate++;
                }
            }
            return s;
        },
        aplica: function () {
            treceCeleDinDocument();
            if (ax.dateValide() === true) {
                insereazaLate();
            }
        }
    };

    // Trecerea preload-urilor nu depinde de configurație: acum, pentru linkurile de dinaintea scriptului; apoi la `load`-ul
    // fiecărei foi. Foile din `file` vin din configurație: la primul cadru sau la „interactive”, ce vine întâi.
    treceCeleDinDocument();
    if (!suportaPreload && d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', treceCeleDinDocument);
    }
    if (d.readyState === 'loading') {
        cereCadru();
        d.addEventListener('readystatechange', function () {
            if (d.readyState !== 'loading') {
                incearcaLate(true);
            }
        });
    } else {
        incearcaLate(true);
    }
}(window, document));
