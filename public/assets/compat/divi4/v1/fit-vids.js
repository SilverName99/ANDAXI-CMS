/**
 * Runtime-ul compat, divi4/v1: foaia `<style id="fit-vids-style">` (adoptarea unui site WordPress, felia 1, pasul 8a; decizia
 * D1: reprodus, pentru paritatea DOM-ului și a CSSOM-ului). Scris clean-room, din specificația `fara-efect.md`, fără niciun cod
 * de furnizor.
 *
 * Pe live, scriptul de video fluid al temei pune această foaie în head pe toate paginile, deși niciun video al site-ului nu o
 * folosește (fără efect vizibil). Modulul `fit-vids` o adaugă după DOMContentLoaded (momentul 'dom', doar cu ax-date valid;
 * pe live +11…+50 ms, OBS-01), ca ultim copil al lui `<head>` în acel moment: după ultima foaie din head; `<style>`-urile puse
 * mai târziu de alte module vin după ea (ca pe live pe pagina cu hărți). Doar atributul `id`. Un element care are deja acest
 * id o face să nu mai fie adăugată, deci o a doua rulare nu schimbă nimic.
 *
 * TEXTUL foii (CSS_FIT_VIDS) e scris aici, al nostru: aceleași reguli ca foaia observată pe live (OBS-R-extra: același efect pe
 * toate paginile și UA-urile), adică învelișul video-ului fluid cu lățimea întreagă, poziționat relativ, fără padding, iar
 * iframe-ul, object-ul și embed-ul din el poziționate absolut pe toată suprafața lui. Paritatea compară această foaie pe reguli
 * (selector → declarații), nu pe text (paritate.json → normalizari.head.stiluri_pe_reguli).
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.fitVids) {
        return;
    }

    var ID = 'fit-vids-style';
    var INVELIS = '.fluid-width-video-wrapper';
    var CSS_FIT_VIDS = INVELIS + ' { width: 100%; position: relative; padding: 0 }\n'
        + [INVELIS + ' embed', INVELIS + ' object', INVELIS + ' iframe'].join(', ')
        + ' { position: absolute; width: 100%; height: 100%; left: 0; top: 0 }\n';

    function aplica() {
        var cap = d.head || d.getElementsByTagName('head')[0];
        if (!cap || d.getElementById(ID)) {
            return;
        }
        var foaie = d.createElement('style');
        foaie.setAttribute('id', ID);
        if (CSS_FIT_VIDS !== '') {
            foaie.appendChild(d.createTextNode(CSS_FIT_VIDS));
        }
        cap.appendChild(foaie);
    }

    ax.fitVids = { aplica: aplica, css: CSS_FIT_VIDS };
    ax.modul('fit-vids', 'dom', aplica);
}(window, document));
