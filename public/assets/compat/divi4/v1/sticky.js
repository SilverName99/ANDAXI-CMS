/**
 * Runtime-ul compat, divi4/v1: opțiunile „sticky” ale modulelor Divi 4 (adoptarea unui site WordPress, felia 3; decizia D1:
 * reprodus). Scris clean-room, din specificația de comportament `sticky.md` (modelul măsurat pe 3504 eșantioane) și din stările
 * luate pe pagina live rulată offline din cache-ul referinței (23.11: clasele, atributul `style`, locul și marcajul
 * placeholder-ului, `<style>`-urile puse la pornire, la 390, 820, 1366 și 1920), fără niciun cod de furnizor.
 *
 * Configurația vine doar din ax-date → `et_pb_sticky_elements` (`{"<id>": {position, topOffset, bottomLimit, offsetSurrounding,
 * styles, …}}`; o valoare poate fi pe dispozitiv: `{"desktop", "tablet", "phone"}`, phone → tablet → desktop). Id-ul e o clasă
 * Divi care începe cu `et_pb_` (`et_pb_row_76`; `home` sau `page` ar prinde body-ul); `selector` trebuie să fie exact `.<id>`,
 * altfel intrarea e refuzată (nimic nu ajunge în querySelector).
 * Modurile, ca CSS-ul capturat: desktop ≥ 981 px, tablet 768–980 px, phone ≤ 767 px.
 *
 * La pornire (momentul 'dom'), pe desktop, fiecare element cu `styles.width.desktop` sau `styles.max-width.desktop` primește ca
 * ultim copil `<style class="et_pb_sticky_module_style">` cu regula observată, textul exact (lățimea = lățimea lui din flux, 0
 * dacă e ascuns; max-width: valoarea în px, iar un procent din lățimea părintelui); pe tabletă și telefon nu se pune (observat).
 * La resize regula se reface: lățimea se măsoară cu regula oprită (altfel ar rămâne cea de la prima măsurare), pe placeholder
 * cât e fixat; ieșit din desktop, `<style>`-ul e scos.
 *
 * La fiecare `scroll` (și la resize și load), pentru elementele cu `position` = `top` în modul curent și afișate, în ordinea
 * documentului: N = vârful lui natural în fereastră (al placeholder-ului cât e fixat), H = înălțimea, S = `topOffset` plus, doar
 * dacă elementul are `offsetSurrounding: on`, Σ (topOffset + H) al elementelor sticky de deasupra lui care au și ele `on`.
 *  - fixarea, când N < S (la scrollY 0 nimic): în locul lui, imediat după el, o copie întreagă (`cloneNode(true)`, fără `style` și
 *    fără `<style>`-ul de lățime, cu `et_pb_sticky_placeholder` și `data-sticky-placeholder-id="<id>"`; CSS-ul capturat o face
 *    invizibilă), clasele
 *    `et_pb_sticky et_pb_sticky--top` pe element, `has_et_pb_sticky` pe `.et_builder_inner_content`-ul lui, iar în `style`:
 *    `position: fixed !important; padding: <padding-ul lui> !important;` (+ `margin-right/left: 0px !important` dacă avea margini
 *    laterale) `z-index: 10000` (10010 în antetul Theme Builder) `width`, `left` (ale lui în flux), `top: S`, `bottom: auto`,
 *    `margin-top: 0px`. Cât e fixat, la resize padding-ul și marginile se recitesc din placeholder (urmează pragurile);
 *  - limita de jos (`bottomLimit`: section / row / column = cel mai apropiat strămoș de acel fel): dacă B − H < S (B = marginea de
 *    jos a strămoșului), `margin-top: (B − H − S)px !important`, deci vârful vizual = min(S, B − H);
 *  - revenirea, când N ≥ S: placeholder-ul scos, clasele scoase (și `has_et_pb_sticky` când nu mai e niciun element fixat în acel
 *    conținut), proprietățile scoase din `style`, iar valorile pe care `style` le avea înainte de fixare revin (cu prioritatea
 *    lor); `margin-top: 0px` rămâne doar dacă era limitat (ca pe live).
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

    var ID = /^et_pb_[a-z0-9_]{1,80}$/;
    var LIMITE = { section: 'et_pb_section', row: 'et_pb_row', column: 'et_pb_column' };
    var PROP_FIX = ['position', 'padding', 'margin-right', 'margin-left', 'z-index', 'width', 'left', 'top', 'bottom', 'margin-top'];
    // Proprietățile din `style` pe care le atinge fixarea, ca longhand-uri: valorile de dinainte (cu prioritatea lor) revin la eliberare.
    var PROP_INAINTE = ['position', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-right', 'margin-left',
        'z-index', 'width', 'left', 'top', 'bottom', 'margin-top'];
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
                intrari.push({ id: id, el: el, cfg: c, ph: null, fix: false, limitat: false, stil: null, inainte: null, susLasat: false, pad: '',
                    margini: false });
            }
        }
        intrari.sort(function (a, b) {
            return a.el.compareDocumentPosition(b.el) & 4 ? -1 : 1;
        });
    }

    /**
     * Lățimea din flux a elementului (a placeholder-ului cât e fixat), măsurată cu regula lui `<style>` oprită: altfel lățimea
     * rămâne cea de la prima măsurare (regula însăși o fixează) și depășește pagina după un resize.
     */
    function latimeFaraRegula(intr) {
        var foaie = intr.stil ? intr.stil.sheet : null;
        if (foaie) {
            foaie.disabled = true;
        }
        var l = (intr.fix && intr.ph ? intr.ph : intr.el).getBoundingClientRect().width;
        if (foaie) {
            foaie.disabled = false;
        }
        return l;
    }

    /** `<style class="et_pb_sticky_module_style">` de pe desktop (textul observat, cu spațiile lui); pe tabletă și telefon, scos. */
    function stilDesktop(intr) {
        var st = esteObiect(intr.cfg.styles) ? intr.cfg.styles : {};
        var lat = esteObiect(st.width) ? st.width.desktop : undefined;
        var max = esteObiect(st['max-width']) ? st['max-width'].desktop : undefined;
        var text = null;
        if (modAcum() === 'desktop' && ((typeof lat === 'string' && lat !== '') || (typeof max === 'string' && max !== ''))) {
            var parti = '';
            if (typeof lat === 'string' && lat !== '') {
                parti += 'width: ' + latimeFaraRegula(intr) + 'px;';
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

    /** Padding-ul (scurt) și dacă are margini laterale, din stilul calculat al unui element în flux. */
    function cutii(el) {
        var cs = w.getComputedStyle(el);
        return { pad: scurt(cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft),
            margini: numar(cs.marginRight) !== 0 || numar(cs.marginLeft) !== 0 };
    }

    function puneCutii(intr, c) {
        var st = intr.el.style;
        st.setProperty('padding', c.pad, 'important');
        if (c.margini) {
            st.setProperty('margin-right', '0px', 'important');
            st.setProperty('margin-left', '0px', 'important');
        } else if (intr.margini) {
            st.removeProperty('margin-right');
            st.removeProperty('margin-left');
        }
        intr.pad = c.pad;
        intr.margini = c.margini;
    }

    /** Cât e fixat, padding-ul și marginile urmează pragurile: recitite din placeholder (în flux, cu aceleași clase). */
    function recitesteCutii(intr) {
        if (!intr.fix || !intr.ph) {
            return;
        }
        var c = cutii(intr.ph);
        if (c.pad !== intr.pad || c.margini !== intr.margini) {
            puneCutii(intr, c);
        }
    }

    function fixeaza(intr, S, r) {
        var el = intr.el;
        // valorile din flux, citite înaintea oricărei schimbări (stilul calculat e viu), și cele din `style` de dinainte
        var c = cutii(el);
        var st = el.style;
        intr.inainte = [];
        for (var k = 0; k < PROP_INAINTE.length; k++) {
            // `margin-top: 0px` lăsat de o eliberare limitată anterioară e al nostru, nu „de dinainte”
            if (st.getPropertyValue(PROP_INAINTE[k]) !== '' && !(PROP_INAINTE[k] === 'margin-top' && intr.susLasat)) {
                intr.inainte.push([PROP_INAINTE[k], st.getPropertyValue(PROP_INAINTE[k]), st.getPropertyPriority(PROP_INAINTE[k])]);
            }
        }
        var ph = el.cloneNode(true);
        ph.removeAttribute('style');
        // regula de lățime a elementului rămâne doar pe el (copia ei din placeholder ar dubla-o)
        for (var cop = ph.firstElementChild; cop;) {
            var urm = cop.nextElementSibling;
            if (cop.nodeName === 'STYLE' && cop.classList.contains('et_pb_sticky_module_style')) {
                ph.removeChild(cop);
            }
            cop = urm;
        }
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
        st.removeProperty('margin-top');
        st.setProperty('position', 'fixed', 'important');
        intr.margini = false;
        puneCutii(intr, c);
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
        var aveaSus = false;
        var inainte = intr.inainte || [];
        for (var i = 0; i < inainte.length; i++) {
            el.style.setProperty(inainte[i][0], inainte[i][1], inainte[i][2]);
            aveaSus = aveaSus || inainte[i][0] === 'margin-top';
        }
        intr.susLasat = intr.limitat && !aveaSus;
        if (intr.susLasat) {
            el.style.setProperty('margin-top', '0px'); // ca pe live, după o limită
        }
        intr.inainte = null;
        intr.limitat = false;
        var lay = stramos(el, 'et_builder_inner_content');
        if (lay) {
            for (var j = 0; j < intrari.length; j++) {
                if (intrari[j].fix && stramos(intrari[j].el, 'et_builder_inner_content') === lay) {
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
        if (typeof lim !== 'string' || !Object.prototype.hasOwnProperty.call(LIMITE, lim)) {
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
                for (var k = 0; k < intrari.length; k++) {
                    recitesteCutii(intrari[k]);
                }
                actualizeaza();
            });
            w.addEventListener('load', actualizeaza);
        }
    }

    ax.sticky = { aplica: aplica, actualizeaza: actualizeaza, decalaj: decalaj };
    ax.modul('sticky', 'dom', aplica);
}(window, document));
