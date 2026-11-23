/**
 * Runtime-ul compat, divi4/v1: conținutul și atributele Divi pe dispozitiv și la hover (`data-et-multi-view`; adoptarea unui site
 * WordPress, felia 2; decizia D1: reprodus). Scris clean-room, din specificația de comportament `multi-view.md` și din DOM-ul de
 * după scripturi al referinței (inventarul mutațiilor, rularea de paritate din 23.11), fără niciun cod de furnizor.
 *
 * Fiecare element cu `data-et-multi-view` poartă JSON-ul `{"schema": {"content" | "attrs": {"desktop", "tablet", "phone",
 * "hover"}}, "slug", "hover_selector"?}` (în captură; politica HTML-ului capturat i-a validat deja variantele HTML și adresele).
 * Modurile, ca media queries-urile CSS-ului capturat: desktop ≥ 981 px, tablet 768–980 px, phone ≤ 767 px; o valoare lipsă pentru
 * phone se ia de la tablet, apoi de la desktop.
 *
 * La pornire (momentul 'dom', doar cu ax-date valid), pentru fiecare element, în ordinea documentului:
 *  1. ținta de hover primește `et_multi_view__hover_selector`: elementele prinse de `hover_selector` care sunt elementul însuși
 *     sau un strămoș al lui (logo-ul meniului: imaginea însăși); altfel cel mai apropiat strămoș cu clasa `slug`
 *     (`div.et_pb_toggle`, `div.et_pb_image`), altfel elementul. La toate lățimile;
 *  2. pe desktop nu se schimbă nimic (valoarea desktop e deja în pagină). Pe tablet/phone, dacă modul are o valoare proprie
 *     (`tablet`/`phone`): conținutul (`content`) se înlocuiește cu varianta și elementul primește `et_multi_view_swapped` (CSS-ul
 *     capturat ascunde până atunci elementele `data-et-multi-view-load-*-hidden`); atributele (`attrs`) se pun pe o copie a
 *     imaginii ascunse `img.et_multi_view_hidden_image`, care o înlocuiește în DOM (ca pe live: nod scos, nod adăugat), fără
 *     `et_multi_view_hidden_image` și cu `et_multi_view_swapped et_multi_view_image__loaded` la sfârșit; o altă imagine își
 *     schimbă atributele pe loc, ca la hover. O valoare de imagine fără `srcset`/`sizes` le pune goale (observat pe live).
 * Hover (valoarea `hover`, de exemplu imaginile de pe Obiective): `mouseenter` pe țintă pune atributele valorii hover pe imagine
 * (`et_multi_view_image__loading` + `et_multi_view_swapped`, deci ascunsă de CSS), iar la `load`-ul imaginii `__loading` devine
 * `__loaded` (CSS-ul capturat o estompează în 0,5 s); `mouseleave` întoarce, la fel, valoarea modului curent. Ultima stare
 * câștigă. Resize peste un prag: valoarea modului nou (OBS-16, neobservat pe live).
 *
 * Variantele HTML nu trec prin parserul browserului (fără innerHTML/DOMParser): le citește `citesteFragment`, un cititor strict
 * pe o listă închisă de etichete și atribute, care construiește nodurile cu createElement/createTextNode și refuză tot ce ar
 * face parserul HTML să schimbe arborele (închideri implicite, tbody implicit, text în tabel, etichete necunoscute, atribute
 * `on*`, adrese nesigure, referințe de caractere necunoscute): o variantă refuzată lasă elementul neschimbat, cu un rând în
 * diagnostic. Atributele de imagine: doar src, srcset, sizes, alt, title, width, height și clasele din `class` (adăugate);
 * adresele doar prin `axCompat.adresaSigura`.
 *
 * `axCompat.multiView.aplica()` nu dublează nimic (un element deja văzut doar își reevaluează modul); `citesteFragment` e expus
 * pentru teste. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.multiView) {
        return;
    }

    var SLUG = /^et_pb_[a-z0-9_]{1,60}$/;
    var SELECTOR_SIGUR = /^[A-Za-z0-9_#.\->\s]{1,200}$/;
    var MODURI = ['desktop', 'tablet', 'phone'];
    var ATR_IMAGINE = ['src', 'srcset', 'sizes', 'alt', 'title', 'width', 'height'];

    // ---------------------------------------------------------------------------------------------------------------------
    // Cititorul fragmentelor HTML (lista închisă)
    // ---------------------------------------------------------------------------------------------------------------------
    var VIDE = { br: 1, hr: 1, img: 1, col: 1, wbr: 1 };
    var ETICHETE = {};
    ('p br hr h1 h2 h3 h4 h5 h6 strong b em i u s sub sup small mark span div ul ol li dl dt dd blockquote figure figcaption '
        + 'table caption colgroup col thead tbody tfoot tr th td a img abbr cite q code wbr').split(' ').forEach(function (t) {
        ETICHETE[t] = 1;
    });
    // Etichetele care, deschise cu un <p> deschis, l-ar închide implicit în parserul HTML (refuzate aici).
    var INCHID_P = {};
    'address article aside blockquote div dl fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hr main nav ol p pre section table ul li dd dt'
        .split(' ').forEach(function (t) {
            INCHID_P[t] = 1;
        });
    // Etichetele care nu au voie într-una la fel deschisă în domeniul ei (parserul ar închide-o pe cea deschisă), cu barierele
    // domeniului: o listă sau un tabel imbricat începe un domeniu nou.
    var TABEL_BARIERA = ['table', 'td', 'th', 'caption'];
    var FARA_IMBRICARE = { li: ['ul', 'ol'].concat(TABEL_BARIERA), dt: ['dl'].concat(TABEL_BARIERA), dd: ['dl'].concat(TABEL_BARIERA),
        tr: ['table'], td: ['table'], th: ['table'], a: TABEL_BARIERA, p: TABEL_BARIERA };
    var TITLURI = { h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1 };
    var COPII_TABEL = { table: { caption: 1, colgroup: 1, thead: 1, tbody: 1, tfoot: 1 }, thead: { tr: 1 }, tbody: { tr: 1 }, tfoot: { tr: 1 },
        tr: { td: 1, th: 1 }, colgroup: { col: 1 } };
    var ATRIBUTE = {};
    ('class style title lang dir role id align valign width height border cellpadding cellspacing colspan rowspan scope span start '
        + 'type reversed value alt loading decoding sizes target rel href src srcset').split(' ').forEach(function (a) {
        ATRIBUTE[a] = 1;
    });
    var ENTITATI = { amp: '&', lt: '<', gt: '>', quot: '"', apos: '\'', nbsp: '\u00A0', shy: '\u00AD', ndash: '\u2013', mdash: '\u2014',
        lsquo: '\u2018', rsquo: '\u2019', sbquo: '\u201A', ldquo: '\u201C', rdquo: '\u201D', bdquo: '\u201E', laquo: '\u00AB', raquo: '\u00BB',
        hellip: '\u2026', euro: '\u20AC', copy: '\u00A9', reg: '\u00AE', trade: '\u2122', deg: '\u00B0', middot: '\u00B7', bull: '\u2022',
        times: '\u00D7', divide: '\u00F7', plusmn: '\u00B1' };

    function refuz(motiv) {
        var e = new Error(motiv);
        e.refuzFragment = true;
        throw e;
    }

    /** Referințele de caractere dintr-un text sau dintr-o valoare de atribut; una necunoscută (cu „;”) e refuzată. */
    function decodeaza(s) {
        return s.replace(/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});?/g, function (tot, ref) {
            var areFinal = tot.charAt(tot.length - 1) === ';';
            if (ref.charAt(0) === '#') {
                if (!areFinal) {
                    refuz('referință numerică fără „;”');
                }
                var n = ref.charAt(1) === 'x' || ref.charAt(1) === 'X' ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10);
                if (!(n > 0 && n <= 0x10FFFF) || (n >= 0xD800 && n <= 0xDFFF) || (n >= 0x80 && n <= 0x9F)) {
                    refuz('referință numerică în afara listei');
                }
                return String.fromCodePoint(n);
            }
            if (!areFinal || !Object.prototype.hasOwnProperty.call(ENTITATI, ref)) {
                refuz('referință de caracter necunoscută: &' + ref);
            }
            return ENTITATI[ref];
        });
    }

    function adresaBuna(nume, v) {
        if (nume === 'href') {
            return /^#[A-Za-z0-9_:.-]*$/.test(v) || /^(mailto|tel):[^\s<>"']+$/i.test(v) || ax.adresaSigura(v);
        }
        if (nume === 'src') {
            return ax.adresaSigura(v);
        }
        if (nume === 'srcset') {
            var parti = v.split(',');
            for (var i = 0; i < parti.length; i++) {
                var u = parti[i].trim().split(/\s+/)[0];
                if (u === '' || !ax.adresaSigura(u)) {
                    return false;
                }
            }
            return true;
        }
        return true;
    }

    function atributBun(nume, v) {
        if (/^on/.test(nume)) {
            return false;
        }
        if (!(ATRIBUTE[nume] === 1 || /^aria-[a-z]{1,30}$/.test(nume) || (/^data-[a-z0-9-]{1,60}$/.test(nume) && nume.indexOf('data-ax-') !== 0))) {
            return false;
        }
        if (nume === 'id' && !/^[A-Za-z][A-Za-z0-9_:.-]{0,80}$/.test(v)) {
            return false;
        }
        if (nume === 'style' && /expression\s*\(|javascript\s*:|@import|behaviou?r\s*:/i.test(v)) {
            return false;
        }
        if (nume === 'target' && !/^_(blank|self|parent|top)$/.test(v)) {
            return false;
        }
        return adresaBuna(nume, v);
    }

    /**
     * Citește o variantă HTML într-un DocumentFragment, cu noduri create unul câte unul; aruncă (refuzFragment) la orice ieșire
     * din lista închisă sau la o construcție pe care parserul HTML ar reface-o altfel.
     */
    function citesteFragment(sursa) {
        if (typeof sursa !== 'string' || sursa.length > 200000) {
            refuz('varianta nu e text');
        }
        var s = sursa.replace(/\r\n?/g, '\n');
        var frag = d.createDocumentFragment();
        var stiva = [{ nume: '#frag', el: frag }];
        var i = 0;
        var n = s.length;
        function curent() {
            return stiva[stiva.length - 1];
        }
        function inDomeniu(nume, barieri) {
            for (var k = stiva.length - 1; k > 0; k--) {
                if (stiva[k].nume === nume) {
                    return true;
                }
                if (barieri.indexOf(stiva[k].nume) >= 0) {
                    return false;
                }
            }
            return false;
        }
        function text(t) {
            if (t === '') {
                return;
            }
            var c = curent().nume;
            if (COPII_TABEL[c] && /[^\t\n\f\r ]/.test(t)) {
                refuz('text în ' + c);
            }
            curent().el.appendChild(d.createTextNode(decodeaza(t)));
        }
        while (i < n) {
            var lt = s.indexOf('<', i);
            if (lt < 0) {
                text(s.slice(i));
                break;
            }
            var urm = s.charAt(lt + 1);
            if (!/[A-Za-z\/!]/.test(urm)) {
                // „<” urmat de altceva decât o literă, „/” sau „!”: text, ca în parserul HTML
                text(s.slice(i, lt + 1));
                i = lt + 1;
                continue;
            }
            text(s.slice(i, lt));
            if (s.substr(lt, 4) === '<!--') {
                var sf = s.indexOf('-->', lt + 4);
                if (sf < 0 || /^>|^->/.test(s.slice(lt + 4)) || s.slice(lt + 4, sf).indexOf('--') >= 0) {
                    refuz('comentariu neobișnuit');
                }
                if (COPII_TABEL[curent().nume]) {
                    refuz('comentariu în tabel');
                }
                curent().el.appendChild(d.createComment(s.slice(lt + 4, sf)));
                i = sf + 3;
                continue;
            }
            if (urm === '!') {
                refuz('construcție <! necunoscută');
            }
            var m;
            if (urm === '/') {
                m = /^<\/([A-Za-z][A-Za-z0-9]*)\s*>/.exec(s.slice(lt, lt + 80));
                if (!m) {
                    refuz('etichetă de închidere invalidă');
                }
                var numeInchis = m[1].toLowerCase();
                if (curent().nume !== numeInchis) {
                    refuz('închiderea lui ' + numeInchis + ' nu e a elementului curent');
                }
                stiva.pop();
                i = lt + m[0].length;
                continue;
            }
            m = /^<([A-Za-z][A-Za-z0-9]*)/.exec(s.slice(lt, lt + 40));
            var nume = m[1].toLowerCase();
            if (ETICHETE[nume] !== 1) {
                refuz('etichetă în afara listei: ' + nume);
            }
            var j = lt + m[0].length;
            var atribute = [];
            var vazute = {};
            var autoInchis = false;
            for (;;) {
                var spatiu = /^[\t\n\f ]*/.exec(s.slice(j, j + 1000))[0].length;
                j += spatiu;
                if (j >= n) {
                    refuz('etichetă neterminată');
                }
                if (s.charAt(j) === '>') {
                    j++;
                    break;
                }
                if (s.substr(j, 2) === '/>') {
                    autoInchis = true;
                    j += 2;
                    break;
                }
                if (spatiu === 0 && atribute.length > 0) {
                    refuz('atribute lipite');
                }
                var ma = /^([a-zA-Z][a-zA-Z0-9-]*)(?:[\t\n\f ]*=[\t\n\f ]*(?:"([^"]*)"|'([^']*)'|([^\t\n\f "'=<>`]+)))?/.exec(s.slice(j, j + 100000));
                if (!ma) {
                    refuz('atribut invalid în ' + nume);
                }
                var na = ma[1].toLowerCase();
                var va = ma[2] !== undefined ? ma[2] : (ma[3] !== undefined ? ma[3] : (ma[4] !== undefined ? ma[4] : ''));
                j += ma[0].length;
                if (Object.prototype.hasOwnProperty.call(vazute, na)) {
                    continue; // ca în parserul HTML: primul câștigă
                }
                vazute[na] = 1;
                va = decodeaza(va);
                if (!atributBun(na, va)) {
                    refuz('atribut refuzat: ' + na + ' pe ' + nume);
                }
                atribute.push([na, va]);
            }
            if (autoInchis && VIDE[nume] !== 1) {
                refuz('„/>” pe un element care nu e vid: ' + nume);
            }
            var par = curent().nume;
            if (COPII_TABEL[par] && COPII_TABEL[par][nume] !== 1) {
                refuz(nume + ' direct în ' + par);
            }
            if ((nume === 'tr' || nume === 'td' || nume === 'th' || nume === 'caption' || nume === 'thead' || nume === 'tbody' || nume === 'tfoot'
                || nume === 'colgroup' || nume === 'col') && !COPII_TABEL[par]) {
                refuz(nume + ' în afara unui tabel');
            }
            if (INCHID_P[nume] === 1 && inDomeniu('p', TABEL_BARIERA)) {
                refuz(nume + ' într-un <p> deschis');
            }
            if (FARA_IMBRICARE[nume] && inDomeniu(nume, FARA_IMBRICARE[nume])) {
                refuz(nume + ' în ' + nume);
            }
            if (TITLURI[nume] === 1 && TITLURI[par] === 1) {
                refuz(nume + ' direct în ' + par);
            }
            var el = d.createElement(nume);
            for (var k = 0; k < atribute.length; k++) {
                el.setAttribute(atribute[k][0], atribute[k][1]);
            }
            curent().el.appendChild(el);
            if (VIDE[nume] !== 1) {
                stiva.push({ nume: nume, el: el });
            }
            i = j;
        }
        return frag;
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Modurile și valorile
    // ---------------------------------------------------------------------------------------------------------------------
    function modAcum() {
        try {
            if (w.matchMedia('(min-width: 981px)').matches) {
                return 'desktop';
            }
            return w.matchMedia('(min-width: 768px)').matches ? 'tablet' : 'phone';
        } catch (e) {
            var l = w.innerWidth || 0;
            return l >= 981 ? 'desktop' : (l >= 768 ? 'tablet' : 'phone');
        }
    }

    function revizor(cheie, valoare) {
        return cheie === '__proto__' || cheie === 'constructor' || cheie === 'prototype' ? undefined : valoare;
    }

    function esteObiect(o) {
        return o !== null && typeof o === 'object' && Object.prototype.toString.call(o) === '[object Object]';
    }

    /** Valoarea unui mod: phone → tablet → desktop. `proprie` = modul are o valoare a lui (nu moștenită de la desktop). */
    function valoare(fel, mod) {
        var ordine = mod === 'phone' ? ['phone', 'tablet', 'desktop'] : (mod === 'tablet' ? ['tablet', 'desktop'] : ['desktop']);
        for (var k = 0; k < ordine.length; k++) {
            if (Object.prototype.hasOwnProperty.call(fel, ordine[k])) {
                return { v: fel[ordine[k]], proprie: ordine[k] !== 'desktop' };
            }
        }
        return null;
    }

    var intrari = [];

    function intrareDin(el) {
        for (var k = 0; k < intrari.length; k++) {
            if (intrari[k].el === el) {
                return intrari[k];
            }
        }
        return null;
    }

    function stramos(el, clasa) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList && n.classList.contains(clasa)) {
                return n;
            }
        }
        return null;
    }

    function tinteHover(el, cfg) {
        var rez = [];
        if (typeof cfg.hover_selector === 'string' && SELECTOR_SIGUR.test(cfg.hover_selector)) {
            var gasite = [];
            try {
                gasite = d.querySelectorAll(cfg.hover_selector);
            } catch (e) {
                gasite = [];
            }
            for (var k = 0; k < gasite.length; k++) {
                if (gasite[k] === el || gasite[k].contains(el)) {
                    rez.push(gasite[k]);
                }
            }
            if (rez.length) {
                return rez;
            }
        }
        var s = typeof cfg.slug === 'string' && SLUG.test(cfg.slug) ? stramos(el, cfg.slug) : null;
        return [s || el];
    }

    function golesteSiPune(el, frag) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        el.appendChild(frag);
    }

    function puneContinut(intr, html) {
        var frag;
        try {
            frag = citesteFragment(html);
        } catch (e) {
            ax.noteaza('multi-view: variantă refuzată (' + (e && e.refuzFragment ? e.message : 'eroare') + ')');
            return false;
        }
        golesteSiPune(intr.el, frag);
        return true;
    }

    /** Atributele de imagine ale unei valori, verificate; null dacă nu e o listă bună. */
    function atributeDin(v) {
        if (!esteObiect(v)) {
            return null;
        }
        var rez = [];
        for (var k in v) {
            if (!Object.prototype.hasOwnProperty.call(v, k)) {
                continue;
            }
            var x = v[k];
            if (typeof x !== 'string') {
                continue;
            }
            if (k === 'class') {
                rez.push(['class', x]);
            } else if (ATR_IMAGINE.indexOf(k) >= 0) {
                if ((k === 'src' && x !== '' && !adresaBuna('src', x)) || (k === 'srcset' && x !== '' && !adresaBuna('srcset', x))) {
                    ax.noteaza('multi-view: adresă refuzată în ' + k);
                    return null;
                }
                rez.push([k, x]);
            } else {
                ax.noteaza('multi-view: atribut ignorat: ' + k);
            }
        }
        return rez;
    }

    function aplicaAtribute(el, lista) {
        for (var k = 0; k < lista.length; k++) {
            if (lista[k][0] === 'class') {
                var clase = lista[k][1].split(/\s+/);
                for (var c = 0; c < clase.length; c++) {
                    if (/^[A-Za-z0-9_-]{1,80}$/.test(clase[c])) {
                        el.classList.add(clase[c]);
                    }
                }
            } else if (el.getAttribute(lista[k][0]) !== lista[k][1]) {
                el.setAttribute(lista[k][0], lista[k][1]);
            }
        }
    }

    /** Schimbarea pe loc a unei imagini (hover, imaginile vizibile): ascunsă până la load, apoi estompată de CSS. */
    function schimbaPeLoc(intr, lista) {
        var el = intr.el;
        intr.generatie++;
        var g = intr.generatie;
        el.classList.remove('et_multi_view_image__loaded');
        el.classList.add('et_multi_view_image__loading');
        el.classList.add('et_multi_view_swapped');
        aplicaAtribute(el, lista);
        function gata() {
            if (intr.generatie !== g) {
                return;
            }
            el.classList.remove('et_multi_view_image__loading');
            el.classList.add('et_multi_view_image__loaded');
        }
        if (el.nodeName === 'IMG' && !el.complete) {
            el.addEventListener('load', gata, { once: true });
            el.addEventListener('error', gata, { once: true });
        } else {
            w.setTimeout(gata, 0);
        }
    }

    /** Imaginea ascunsă a logo-ului pe tablet/phone: o copie cu atributele modului îi ia locul (ca pe live). */
    function inlocuiesteImaginea(intr, lista) {
        var vechi = intr.el;
        var nou = vechi.cloneNode(false);
        aplicaAtribute(nou, lista);
        nou.classList.remove('et_multi_view_hidden_image');
        nou.classList.add('et_multi_view_swapped');
        nou.classList.add('et_multi_view_image__loaded');
        if (vechi.parentNode) {
            vechi.parentNode.replaceChild(nou, vechi);
        }
        intr.el = nou;
    }

    function aplicaValoare(intr, v, pePrag) {
        if (intr.fel === 'content') {
            if (typeof v !== 'string') {
                return;
            }
            if (puneContinut(intr, v)) {
                intr.el.classList.add('et_multi_view_swapped');
            }
            return;
        }
        var lista = atributeDin(v);
        if (lista === null) {
            return;
        }
        if (intr.el.nodeName === 'IMG') {
            // ca pe live: o valoare fără srcset/sizes le golește (altfel ar rămâne cele vechi ale imaginii)
            var are = {};
            for (var k = 0; k < lista.length; k++) {
                are[lista[k][0]] = 1;
            }
            if (!are.srcset) {
                lista.push(['srcset', '']);
            }
            if (!are.sizes) {
                lista.push(['sizes', '']);
            }
        }
        if (pePrag && intr.el.nodeName === 'IMG' && intr.el.classList.contains('et_multi_view_hidden_image')) {
            inlocuiesteImaginea(intr, lista);
        } else if (intr.el.nodeName === 'IMG') {
            schimbaPeLoc(intr, lista);
        } else {
            aplicaAtribute(intr.el, lista);
        }
    }

    /** Valoarea modului curent, doar dacă diferă de cea pusă deja (la pornire: desktop e deja în pagină). */
    function reevalueaza(intr) {
        var mod = modAcum();
        if (intr.mod === mod) {
            return;
        }
        var anterior = intr.mod;
        intr.mod = mod;
        var x = valoare(intr.schema, mod);
        if (x === null) {
            return;
        }
        if (anterior === null && !x.proprie) {
            return; // la pornire, valoarea desktop e cea din captură
        }
        if (anterior !== null && !x.proprie && !intr.schimbat) {
            return;
        }
        intr.schimbat = intr.schimbat || x.proprie;
        aplicaValoare(intr, x.v, true);
    }

    function laIntrare(intr) {
        return function () {
            if (!Object.prototype.hasOwnProperty.call(intr.schema, 'hover')) {
                return;
            }
            intr.inHover = true;
            aplicaValoare(intr, intr.schema.hover, false);
        };
    }

    function laIesire(intr) {
        return function () {
            if (!intr.inHover) {
                return;
            }
            intr.inHover = false;
            var x = valoare(intr.schema, modAcum());
            if (x !== null) {
                aplicaValoare(intr, x.v, false);
            }
        };
    }

    function citesteConfig(el) {
        var cfg;
        try {
            cfg = JSON.parse(el.getAttribute('data-et-multi-view') || '', revizor);
        } catch (e) {
            return null;
        }
        if (!esteObiect(cfg) || !esteObiect(cfg.schema)) {
            return null;
        }
        return cfg;
    }

    function aplica() {
        var lista = d.querySelectorAll('[data-et-multi-view]');
        for (var k = 0; k < lista.length; k++) {
            var el = lista[k];
            var intr = intrareDin(el);
            if (intr) {
                reevalueaza(intr);
                continue;
            }
            var cfg = citesteConfig(el);
            if (cfg === null) {
                ax.noteaza('multi-view: data-et-multi-view invalid (poziția ' + k + ')');
                continue;
            }
            var fel = esteObiect(cfg.schema.content) ? 'content' : (esteObiect(cfg.schema.attrs) ? 'attrs' : null);
            if (fel === null) {
                continue;
            }
            intr = { el: el, fel: fel, schema: cfg.schema[fel], mod: null, schimbat: false, inHover: false, generatie: 0 };
            intrari.push(intr);
            var tinte = tinteHover(el, cfg);
            for (var t = 0; t < tinte.length; t++) {
                tinte[t].classList.add('et_multi_view__hover_selector');
                if (Object.prototype.hasOwnProperty.call(intr.schema, 'hover')) {
                    tinte[t].addEventListener('mouseenter', laIntrare(intr));
                    tinte[t].addEventListener('mouseleave', laIesire(intr));
                }
            }
            reevalueaza(intr);
        }
    }

    var asculta = false;

    function porneste() {
        aplica();
        if (!asculta) {
            asculta = true;
            w.addEventListener('resize', function () {
                for (var k = 0; k < intrari.length; k++) {
                    reevalueaza(intrari[k]);
                }
            });
        }
    }

    ax.multiView = { aplica: aplica, citesteFragment: citesteFragment, mod: modAcum, moduri: MODURI.slice() };
    ax.modul('multi-view', 'dom', porneste);
}(window, document));
