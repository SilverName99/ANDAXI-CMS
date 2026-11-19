/**
 * Runtime-ul compat, divi4/v1: clasele și stilurile de structură puse la încărcare (adoptarea unui site WordPress, felia 1,
 * pasul 8a; INSTRUCTIUNI-BUILDER-C2 §8, decizia D1: reprodus). Scris clean-room, din specificația de comportament
 * `clase-structura.md` (diferența dintre DOM-ul capturat și DOM-ul de după scripturi), fără niciun cod de furnizor.
 *
 * CSS-ul capturat are reguli pe aceste clase (umplutura coloanelor „single”, lățimile coloanelor pe tabletă și telefon), deci
 * fără ele aspectul static diferă. Modulul `structura` pornește o singură dată, după DOMContentLoaded (momentul 'dom', doar cu
 * ax-date valid; OBS-01), înaintea modulelor care măsoară (caruselele); clasele nu depind de lățime, deci nu se refac la resize.
 * Reguli potrivite cu tot ce s-a observat pe live (clase-structura.md, corectat de OBS-04). Adaugă, la sfârșitul lui `class`:
 *  1. `et_pb_column_single` pe fiecare coloană (`.et_pb_column`, copil direct al rândului) care nu e `et_pb_specialty_column`,
 *     dintr-un rând `.et_pb_row` care are o coloană `et_pb_specialty_column` (secțiunile „specialty”, rândul desktop al antetului);
 *  2. `et_pb_row_<mărimi>` pe fiecare `.et_pb_row_inner` din conținut (nu din antet sau subsol: clasa numerotată cu `_tb_header`
 *     sau `_tb_footer`, ori un strămoș `.et-l--header` / `.et-l--footer`) cu cel puțin două coloane: mărimea fiecărei coloane
 *     (`et_pb_column_1_6` → `1-6`), în ordine, unite cu `_` (`et_pb_row_1-6_1-6_1-6`); o coloană fără mărime lasă rândul neatins;
 *  3. pe rândul principal al secțiunii „specialty” din antet (părintele coloanei `et_pb_specialty_column`, în `.et-l--header`):
 *     `et_pb_row_<mărimi>` din coloanele lui (`et_pb_row_1-4_3-4`) și `z-index: 3` în `style`. Rândurile principale „specialty”
 *     din conținut nu primesc nimic (OBS-04, și cu `et_pb_gutters1`);
 *  4. `z-index: 3` în `style` pe rândul `div.et_pb_row_0_tb_footer` (rândul cu meniul subsolului), pe toate paginile;
 *  5. clasele observate fără o regulă care să reiasă din DOM, ca listă pe pagină: ax-date → `ax_structura`, de forma
 *     `{"et-last-child": ["<selector>", …]}` (pusă de import din pachetul site-ului). Fiecare element găsit de un selector primește
 *     clasa. Clasele: doar cele din lista închisă de mai jos; selectorii: doar id-uri, clase, nume de taguri, spații și `>`.
 *
 * `axCompat.structura.aplica()` reia totul fără efect (classList nu dublează, z-index-ul e deja pus). O a doua includere nu face
 * nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.structura) {
        return;
    }

    var MARIME = /^et_pb_column_([0-9]+)_([0-9]+)$/;
    var ANTET_SUBSOL = /_tb_(header|footer)$/;
    var SELECTOR_SIGUR = /^[A-Za-z0-9_#.\->\s]{1,200}$/;
    var CLASE_DIN_LISTA = ['et-last-child'];

    function areClasa(el, clasa) {
        return !!el && el.nodeType === 1 && !!el.classList && el.classList.contains(clasa);
    }

    function coloane(rand) {
        var rez = [];
        for (var c = rand.firstElementChild; c; c = c.nextElementSibling) {
            if (areClasa(c, 'et_pb_column')) {
                rez.push(c);
            }
        }
        return rez;
    }

    function coloaneSingle() {
        var speciale = d.querySelectorAll('.et_pb_column.et_pb_specialty_column');
        for (var i = 0; i < speciale.length; i++) {
            var rand = speciale[i].parentNode;
            if (!areClasa(rand, 'et_pb_row')) {
                continue;
            }
            var lista = coloane(rand);
            for (var j = 0; j < lista.length; j++) {
                if (!areClasa(lista[j], 'et_pb_specialty_column')) {
                    lista[j].classList.add('et_pb_column_single');
                }
            }
        }
    }

    function dinAntetSauSubsol(rand) {
        for (var i = 0; i < rand.classList.length; i++) {
            if (ANTET_SUBSOL.test(rand.classList[i])) {
                return true;
            }
        }
        for (var n = rand.parentNode; n && n.nodeType === 1; n = n.parentNode) {
            if (areClasa(n, 'et-l--header') || areClasa(n, 'et-l--footer')) {
                return true;
            }
        }
        return false;
    }

    function marime(coloana) {
        for (var i = 0; i < coloana.classList.length; i++) {
            var m = MARIME.exec(coloana.classList[i]);
            if (m) {
                return m[1] + '-' + m[2];
            }
        }
        return null;
    }

    function randuriInterioare() {
        var randuri = d.querySelectorAll('.et_pb_row_inner');
        for (var i = 0; i < randuri.length; i++) {
            var rand = randuri[i];
            if (dinAntetSauSubsol(rand)) {
                continue;
            }
            var lista = coloane(rand);
            if (lista.length < 2) {
                continue;
            }
            var marimi = [];
            for (var j = 0; j < lista.length; j++) {
                var x = marime(lista[j]);
                if (x === null) {
                    marimi = null;
                    break;
                }
                marimi.push(x);
            }
            if (marimi !== null) {
                rand.classList.add('et_pb_row_' + marimi.join('_'));
            }
        }
    }

    function inAntet(el) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (areClasa(n, 'et-l--header')) {
                return true;
            }
            for (var i = 0; i < n.classList.length; i++) {
                if (/_tb_header$/.test(n.classList[i])) {
                    return true;
                }
            }
        }
        return false;
    }

    function randulSpecialAlAntetului() {
        var speciale = d.querySelectorAll('.et_pb_column.et_pb_specialty_column');
        for (var i = 0; i < speciale.length; i++) {
            var rand = speciale[i].parentNode;
            if (!areClasa(rand, 'et_pb_row') || areClasa(rand, 'et_pb_row_inner') || !inAntet(rand)) {
                continue;
            }
            var lista = coloane(rand);
            var marimi = [];
            for (var j = 0; j < lista.length && marimi !== null; j++) {
                var x = marime(lista[j]);
                marimi = x === null ? null : marimi.concat([x]);
            }
            if (marimi !== null && marimi.length >= 2) {
                rand.classList.add('et_pb_row_' + marimi.join('_'));
            }
            if (rand.style.zIndex !== '3') {
                rand.style.zIndex = '3';
            }
        }
    }

    function randulSubsolului() {
        var randuri = d.querySelectorAll('div.et_pb_row_0_tb_footer');
        for (var i = 0; i < randuri.length; i++) {
            if (randuri[i].style.zIndex !== '3') {
                randuri[i].style.zIndex = '3';
            }
        }
    }

    function clasePePagina() {
        var lista = ax.date('ax_structura');
        if (lista === undefined) {
            return;
        }
        if (lista === null || typeof lista !== 'object' || Object.prototype.toString.call(lista) !== '[object Object]') {
            ax.noteaza('structura: „ax_structura” nu e un obiect');
            return;
        }
        for (var clasa in lista) {
            if (!Object.prototype.hasOwnProperty.call(lista, clasa)) {
                continue;
            }
            var selectori = lista[clasa];
            if (CLASE_DIN_LISTA.indexOf(clasa) < 0 || Object.prototype.toString.call(selectori) !== '[object Array]') {
                ax.noteaza('structura: intrare refuzată în „ax_structura” (' + clasa + ')');
                continue;
            }
            for (var i = 0; i < selectori.length; i++) {
                var s = selectori[i];
                if (typeof s !== 'string' || !SELECTOR_SIGUR.test(s)) {
                    ax.noteaza('structura: selector refuzat pentru ' + clasa + ' (poziția ' + i + ')');
                    continue;
                }
                var gasite;
                try {
                    gasite = d.querySelectorAll(s);
                } catch (e) {
                    ax.noteaza('structura: selector invalid pentru ' + clasa + ' (poziția ' + i + ')');
                    continue;
                }
                for (var j = 0; j < gasite.length; j++) {
                    gasite[j].classList.add(clasa);
                }
            }
        }
    }

    function aplica() {
        coloaneSingle();
        randuriInterioare();
        randulSpecialAlAntetului();
        randulSubsolului();
        clasePePagina();
    }

    ax.structura = { aplica: aplica };
    ax.modul('structura', 'dom', aplica);
}(window, document));
