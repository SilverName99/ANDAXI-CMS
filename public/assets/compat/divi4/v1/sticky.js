/**
 * Runtime-ul compat, divi4/v1: opțiunile „sticky” ale modulelor Divi 4 (adoptarea unui site WordPress, felia 3; decizia D1:
 * reprodus). Scris clean-room, din specificația de comportament `sticky.md` (modelul măsurat pe 3504 eșantioane) și din stările
 * luate pe pagina live rulată offline din cache-ul referinței (23.11: clasele, atributul `style`, locul și marcajul
 * placeholder-ului, `<style>`-urile puse la pornire, la 390, 820, 1366 și 1920), fără niciun cod de furnizor.
 *
 * Configurația vine doar din ax-date → `et_pb_sticky_elements` (`{"<id>": {position, topOffset, bottomLimit, offsetSurrounding,
 * styles, …}}`; o valoare poate fi pe dispozitiv: `{"desktop", "tablet", "phone"}`, phone → tablet → desktop). Id-ul e o clasă
 * Divi (`et_pb_row_76`); `selector` trebuie să fie exact `.<id>`, altfel intrarea e refuzată (nimic nu ajunge în querySelector).
 * Modurile, ca CSS-ul capturat: desktop ≥ 981 px, tablet 768–980 px, phone ≤ 767 px.
 *
 * La pornire (momentul 'dom'), pe desktop, fiecare element cu `styles.width.desktop` sau `styles.max-width.desktop` primește ca
 * ultim copil `<style class="et_pb_sticky_module_style">` cu regula observată, textul exact (lățimea = lățimea lui de atunci, 0
 * dacă e ascuns; max-width: valoarea în px, iar un procent din lățimea părintelui); pe tabletă și telefon nu se pune (observat).
 *
 * La fiecare `scroll` (și la resize și load), pentru elementele cu `position` = `top` în modul curent și afișate, în ordinea
 * documentului: N = vârful lui natural în fereastră (al placeholder-ului cât e fixat), H = înălțimea, S = `topOffset` plus, doar
 * dacă elementul are `offsetSurrounding: on`, Σ (topOffset + H) al elementelor sticky de deasupra lui care au și ele `on`.
 *  - fixarea, când N < S (la scrollY 0 nimic): în locul lui, imediat după el, o copie întreagă (`cloneNode(true)`, fără `style`, cu
 *    `et_pb_sticky_placeholder` și `data-sticky-placeholder-id="<id>"`; CSS-ul capturat o face invizibilă), clasele
 *    `et_pb_sticky et_pb_sticky--top` pe element, `has_et_pb_sticky` pe `.et_builder_inner_content`-ul lui, iar în `style`:
 *    `position: fixed !important; padding: <padding-ul lui> !important;` (+ `margin-right/left: 0px !important` dacă avea margini
 *    laterale) `z-index: 10000` (10010 în antetul Theme Builder) `width`, `left` (ale lui în flux), `top: S`, `bottom: auto`,
 *    `margin-top: 0px`;
 *  - limita de jos (`bottomLimit`: section / row / column = cel mai apropiat strămoș de acel fel): dacă B − H < S (B = marginea de
 *    jos a strămoșului), `margin-top: (B − H − S)px !important`, deci vârful vizual = min(S, B − H);
 *  - revenirea, când N ≥ S: placeholder-ul scos, clasele scoase (și `has_et_pb_sticky` când nu mai e niciun element fixat în acel
 *    conținut), proprietățile scoase din `style`; `margin-top: 0px` rămâne doar dacă era limitat (ca pe live).
 * Mișcarea depinde doar de scrollY. Nereprodus (neobservabil sau exclus de spec): tresărirea de un cadru a live-ului la intrarea
 * în limită (VC-ACASA-062), `transition: all` ținut 50 ms la fixare (fără durată, fără efect) și `<style>`-ul temporar care
 * blochează înălțimea secțiunii 3 ms. `position` bottom / topBottom și `topLimit` nu apar pe site: refuzate cu un rând în
 * diagnostic.
 *
 * `axCompat.sticky.decalaj()` dă înălțimea antetelor fixate sus (elementele `top` afișate, cu `offsetSurrounding: on` și fără
 * limită de jos: topOffset + H), pentru ancore. `axCompat.sticky.aplica()` nu dublează nimic. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.sticky) {
        return;
    }

    var ID = /^[a-z][a-z0-9_]{0,80}$/;
    var LIMITE = { section: 'et_pb_section', row: 'et_pb_row', column: 'et_pb_column' };
    var PROP_FIX = ['position', 'padding', 'margin-right', 'margin-left', 'z-index', 'width', 'left', 'top', 'bottom'];
    var intrari = null;
    var notate = {};

    function noteazaO(text) {
        if (!notate[text]) {
            notate[text] = 1;
            ax.noteaza(text);
        }
    }

    function esteObiect(o) {
        return o !== null && typeof o === 'object' && Object.prototype.toString.call(o) === '[object Object]';
    }

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

    function peMod(v, mod) {
        if (!esteObiect(v)) {
            return v;
        }
        var ordine = mod === 'phone' ? ['phone', 'tablet', 'desktop'] : (mod === 'tablet' ? ['tablet', 'desktop'] : ['desktop']);
        for (var k = 0; k < ordine.length; k++) {
            if (Object.prototype.hasOwnProperty.call(v, ordine[k]) && v[ordine[k]] !== '') {
                return v[ordine[k]];
            }
        }
        return undefined;
    }

    function px(v) {
        var m = /^\s*(-?[0-9]+(?:\.[0-9]+)?)px\s*$/.exec(typeof v === 'string' ? v : '');
        return m ? parseFloat(m[1]) : 0;
    }

    function numar(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    function stramos(el, clasa) {
        for (var n = el ? el.parentNode : null; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList && n.classList.contains(clasa)) {
                return n;
            }
        }
        return null;
    }

    function afisat(el) {
        return !!el && el.getClientRects().length > 0;
    }

    function citeste() {
        intrari = [];
        var cfg = ax.date('et_pb_sticky_elements');
        if (cfg === undefined) {
            return;
        }
        if (!esteObiect(cfg)) {
            ax.noteaza('sticky: „et_pb_sticky_elements” nu e un obiect');
            return;
        }
        for (var id in cfg) {
            if (!Object.prototype.hasOwnProperty.call(cfg, id)) {
                continue;
            }
            var c = cfg[id];
            if (!ID.test(id) || !esteObiect(c) || (c.selector !== undefined && c.selector !== '.' + id) || (c.id !== undefined && c.id !== id)) {
                ax.noteaza('sticky: intrare refuzată în „et_pb_sticky_elements”');
                continue;
            }
            var gasite = d.getElementsByClassName(id);
            var el = null;
            for (var k = 0; k < gasite.length && !el; k++) {
                if (!gasite[k].classList.contains('et_pb_sticky_placeholder')) {
                    el = gasite[k];
                }
            }
            if (el) {
                intrari.push({ id: id, el: el, cfg: c, ph: null, fix: false, limitat: false, stil: null });
            }
        }
        intrari.sort(function (a, b) {
            return a.el.compareDocumentPosition(b.el) & 4 ? -1 : 1;
        });
    }

    /** `<style class="et_pb_sticky_module_style">` de pe desktop (textul observat, cu spațiile lui). */
    function stilDesktop(intr) {
        var st = esteObiect(intr.cfg.styles) ? intr.cfg.styles : {};
        var lat = esteObiect(st.width) ? st.width.desktop : undefined;
        var max = esteObiect(st['max-width']) ? st['max-width'].desktop : undefined;
        var text = null;
        if (modAcum() === 'desktop' && ((typeof lat === 'string' && lat !== '') || (typeof max === 'string' && max !== ''))) {
            var parti = '';
            if (typeof lat === 'string' && lat !== '') {
                parti += 'width: ' + intr.el.getBoundingClientRect().width + 'px;';
            }
            if (typeof max === 'string' && max !== '') {
                var mp = /^\s*([0-9]+(?:\.[0-9]+)?)(px|%)\s*$/.exec(max);
                if (mp) {
                    var v = mp[2] === 'px' ? parseFloat(mp[1]) : parseFloat(mp[1]) / 100 * (intr.el.parentNode && intr.el.parentNode.nodeType === 1 ? intr.el.parentNode.getBoundingClientRect().width : 0);
                    parti += ' max-width: ' + v + 'px;';
                }
            }
            text = '\n        .' + intr.id + '.et_pb_sticky_module:not(.et_pb_sticky--editing) {' + parti + '}\n      ';
        }
        if (text === null) {
            if (intr.stil && intr.stil.parentNode) {
                intr.stil.parentNode.removeChild(intr.stil);
            }
            intr.stil = null;
            return;
        }
        if (!intr.stil) {
            intr.stil = d.createElement('style');
            intr.stil.className = 'et_pb_sticky_module_style';
        }
        if (intr.stil.textContent !== text) {
            intr.stil.textContent = text;
        }
        if (intr.stil.parentNode !== intr.el) {
            intr.el.appendChild(intr.stil);
        }
    }

    function scurt(t, r, b, l) {
        if (t === r && r === b && b === l) {
            return t;
        }
        if (t === b && r === l) {
            return t + ' ' + r;
        }
        if (r === l) {
            return t + ' ' + r + ' ' + b;
        }
        return t + ' ' + r + ' ' + b + ' ' + l;
    }

    function inAntet(el) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList && n.classList.contains('et-l--header')) {
                return true;
            }
        }
        return false;
    }

    function fixeaza(intr, S, r) {
        var el = intr.el;
        // valorile din flux, citite înaintea oricărei schimbări (stilul calculat e viu)
        var cs = w.getComputedStyle(el);
        var pad = scurt(cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft);
        var margini = numar(cs.marginRight) !== 0 || numar(cs.marginLeft) !== 0;
        var ph = el.cloneNode(true);
        ph.removeAttribute('style');
        ph.classList.add('et_pb_sticky_placeholder');
        ph.setAttribute('data-sticky-placeholder-id', intr.id);
        el.parentNode.insertBefore(ph, el.nextSibling);
        intr.ph = ph;
        intr.fix = true;
        el.classList.add('et_pb_sticky');
        el.classList.add('et_pb_sticky--top');
        var lay = stramos(el, 'et_builder_inner_content');
        if (lay) {
            lay.classList.add('has_et_pb_sticky');
        }
        var st = el.style;
        st.removeProperty('margin-top');
        st.setProperty('position', 'fixed', 'important');
        st.setProperty('padding', pad, 'important');
        if (margini) {
            st.setProperty('margin-right', '0px', 'important');
            st.setProperty('margin-left', '0px', 'important');
        }
        st.setProperty('z-index', inAntet(el) ? '10010' : '10000');
        st.setProperty('width', r.width + 'px');
        st.setProperty('left', r.left + 'px');
        st.setProperty('top', S + 'px');
        st.setProperty('bottom', 'auto');
        st.setProperty('margin-top', '0px');
        intr.S = S;
        intr.limitat = false;
    }

    function elibereaza(intr) {
        var el = intr.el;
        if (intr.ph && intr.ph.parentNode) {
            intr.ph.parentNode.removeChild(intr.ph);
        }
        intr.ph = null;
        intr.fix = false;
        el.classList.remove('et_pb_sticky');
        el.classList.remove('et_pb_sticky--top');
        for (var k = 0; k < PROP_FIX.length; k++) {
            el.style.removeProperty(PROP_FIX[k]);
        }
        if (intr.limitat) {
            el.style.setProperty('margin-top', '0px');
        } else {
            el.style.removeProperty('margin-top');
        }
        intr.limitat = false;
        var lay = stramos(el, 'et_builder_inner_content');
        if (lay) {
            for (var i = 0; i < intrari.length; i++) {
                if (intrari[i].fix && stramos(intrari[i].el, 'et_builder_inner_content') === lay) {
                    return;
                }
            }
            lay.classList.remove('has_et_pb_sticky');
        }
    }

    function pozitioneaza(intr, S, H, mod) {
        var st = intr.el.style;
        if (intr.S !== S) {
            st.setProperty('top', S + 'px');
            intr.S = S;
        }
        var rp = intr.ph.getBoundingClientRect();
        if (Math.abs(numar(st.width) - rp.width) > 0.05) {
            st.setProperty('width', rp.width + 'px');
        }
        if (Math.abs(numar(st.left) - rp.left) > 0.05) {
            st.setProperty('left', rp.left + 'px');
        }
        var lim = peMod(intr.cfg.bottomLimit, mod);
        if (lim === undefined || lim === 'none' || lim === '') {
            return;
        }
        if (!LIMITE[lim]) {
            noteazaO('sticky: bottomLimit „' + String(lim).slice(0, 20) + '” nesuportat');
            return;
        }
        var cont = stramos(intr.ph, LIMITE[lim]);
        if (!cont) {
            return;
        }
        var delta = cont.getBoundingClientRect().bottom - H - S;
        if (delta < 0) {
            st.setProperty('margin-top', delta + 'px', 'important');
            intr.limitat = true;
        } else if (intr.limitat) {
            st.setProperty('margin-top', '0px');
            intr.limitat = false;
        }
    }

    function actualizeaza() {
        if (!intrari) {
            return;
        }
        var mod = modAcum();
        var acumulat = 0;
        for (var k = 0; k < intrari.length; k++) {
            var intr = intrari[k];
            var poz = peMod(intr.cfg.position, mod);
            var ref = intr.fix ? intr.ph : intr.el;
            if (poz !== 'top' || !afisat(ref)) {
                if (poz !== undefined && poz !== 'none' && poz !== 'top') {
                    noteazaO('sticky: position „' + String(poz).slice(0, 20) + '” nesuportat');
                }
                if (intr.fix) {
                    elibereaza(intr);
                }
                continue;
            }
            if (peMod(intr.cfg.topLimit, mod) !== undefined && peMod(intr.cfg.topLimit, mod) !== 'none') {
                noteazaO('sticky: topLimit nesuportat');
            }
            var sus = px(peMod(intr.cfg.topOffset, mod));
            var inconjurat = peMod(intr.cfg.offsetSurrounding, mod) === 'on';
            var S = sus + (inconjurat ? acumulat : 0);
            var r = ref.getBoundingClientRect();
            if (inconjurat) {
                acumulat += sus + r.height;
            }
            if (!intr.fix && r.top < S) {
                fixeaza(intr, S, r);
            } else if (intr.fix && r.top >= S) {
                elibereaza(intr);
            }
            if (intr.fix) {
                pozitioneaza(intr, S, r.height, mod);
            }
        }
    }

    function decalaj() {
        if (!intrari) {
            return 0;
        }
        var mod = modAcum();
        var total = 0;
        for (var k = 0; k < intrari.length; k++) {
            var intr = intrari[k];
            var ref = intr.fix ? intr.ph : intr.el;
            var lim = peMod(intr.cfg.bottomLimit, mod);
            if (peMod(intr.cfg.position, mod) === 'top' && afisat(ref) && peMod(intr.cfg.offsetSurrounding, mod) === 'on'
                && (lim === undefined || lim === 'none' || lim === '')) {
                total += px(peMod(intr.cfg.topOffset, mod)) + ref.getBoundingClientRect().height;
            }
        }
        return total;
    }

    function stiluri() {
        for (var k = 0; k < intrari.length; k++) {
            stilDesktop(intrari[k]);
        }
    }

    var asculta = false;

    function aplica() {
        if (intrari === null) {
            citeste();
        }
        if (!intrari.length) {
            return;
        }
        stiluri();
        actualizeaza();
        if (!asculta) {
            asculta = true;
            w.addEventListener('scroll', actualizeaza, { passive: true });
            w.addEventListener('resize', function () {
                stiluri();
                actualizeaza();
            });
            w.addEventListener('load', actualizeaza);
        }
    }

    ax.sticky = { aplica: aplica, actualizeaza: actualizeaza, decalaj: decalaj };
    ax.modul('sticky', 'dom', aplica);
}(window, document));
