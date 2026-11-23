/**
 * Runtime-ul compat, divi4/v1: modulele Divi „toggle” și „accordion” (adoptarea unui site WordPress, felia 3; decizia D1:
 * reprodus). Scris clean-room, din specificația de comportament `toggle.md` și din urmele luate pe pagina live rulată offline din
 * cache-ul referinței (23.11: atributul `style` al conținutului și clasele modulului la fiecare cadru, la deschidere și închidere),
 * fără niciun cod de furnizor.
 *
 * Starea e dată numai de clasă (CSS-ul capturat): `et_pb_toggle_close` ascunde conținutul (`.et_pb_toggle_content`, copil direct
 * al modulului), `et_pb_toggle_open` îl arată. Clicul (tapul) pe titlul `.et_pb_toggle_title` comută:
 *  - deschiderea: conținutul primește `display: block`, apoi, în 700 ms pe curba „swing” (0,5 − cos(πp)/2), cu `overflow: hidden`,
 *    înălțimea, `padding-top`, `margin-top`, `padding-bottom` și `margin-bottom` cresc de la 0 la valorile lui naturale (în ordinea
 *    asta în `style`, ca pe live; înălțimea în cutia lui `box-sizing`). La sfârșit rămâne doar `display: block;`, iar modulul își
 *    schimbă clasa: `et_pb_toggle_close` scoasă, `et_pb_toggle_open` adăugată (fundalul, glifa și culoarea titlului se schimbă abia
 *    atunci, ca pe live);
 *  - închiderea: invers, de la valorile de acum la 0, apoi `display: none;` și `et_pb_toggle_open` → `et_pb_toggle_close`.
 * Un clic în timpul animației o oprește și pornește animația inversă din punctul atins (neobservat pe live, OBS-15). Clicul în
 * conținut nu închide nimic. Toggle-urile sunt independente; într-un acordeon (`.et_pb_accordion`), deschiderea unui element le
 * închide pe celelalte deschise, iar clicul pe cel deschis nu face nimic (regula acordeonului Divi; pe site-ul adoptat nu există
 * acordeoane, deci neobservat). Tastatura: titlul nu e focalizabil pe live (fără `tabindex`, fără rol, Enter fără efect, observat)
 * și nici aici; fără ARIA (nimic adăugat pe live).
 *
 * Un singur ascultător pe document (delegat); `axCompat.toggle.aplica()` nu-l dublează. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.toggle) {
        return;
    }

    var DURATA = 700;
    var PROP = ['height', 'padding-top', 'margin-top', 'padding-bottom', 'margin-bottom'];
    var animatii = [];
    var pornit = false;

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function acum() {
        return w.performance && w.performance.now ? w.performance.now() : Date.now();
    }

    function stramos(el, clasa, oprire) {
        for (var n = el; n && n.nodeType === 1 && n !== oprire; n = n.parentNode) {
            if (n.classList && n.classList.contains(clasa)) {
                return n;
            }
        }
        return null;
    }

    function continut(modul) {
        for (var c = modul.firstElementChild; c; c = c.nextElementSibling) {
            if (c.classList.contains('et_pb_toggle_content')) {
                return c;
            }
        }
        return null;
    }

    function animatiaDe(c) {
        for (var k = 0; k < animatii.length; k++) {
            if (animatii[k].c === c) {
                return animatii[k];
            }
        }
        return null;
    }

    function opreste(a) {
        a.oprita = true;
        animatii.splice(animatii.indexOf(a), 1);
    }

    function curata(c) {
        c.style.removeProperty('overflow');
        for (var k = 0; k < PROP.length; k++) {
            c.style.removeProperty(PROP[k]);
        }
    }

    function px(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    /** Valorile naturale ale conținutului (afișat, fără proprietățile animației). */
    function naturale(c) {
        curata(c);
        var cs = w.getComputedStyle(c);
        var h = c.getBoundingClientRect().height;
        if (cs.boxSizing !== 'border-box') {
            h -= px(cs.paddingTop) + px(cs.paddingBottom) + px(cs.borderTopWidth) + px(cs.borderBottomWidth);
        }
        return [Math.max(0, h), px(cs.paddingTop), px(cs.marginTop), px(cs.paddingBottom), px(cs.marginBottom)];
    }

    function scrie(c, valori) {
        for (var k = 0; k < PROP.length; k++) {
            c.style.setProperty(PROP[k], valori[k] + 'px');
        }
    }

    function anim(modul, c, deschide, de, la) {
        var a = { c: c, modul: modul, deschide: deschide, oprita: false, acum: de.slice() };
        animatii.push(a);
        var start = acum();
        c.style.overflow = 'hidden';
        scrie(c, de);
        function pas() {
            if (a.oprita) {
                return;
            }
            var p = Math.max(0, Math.min(1, (acum() - start) / DURATA));
            var e = swing(p);
            for (var k = 0; k < PROP.length; k++) {
                a.acum[k] = de[k] + (la[k] - de[k]) * e;
            }
            scrie(c, a.acum);
            if (p < 1) {
                w.requestAnimationFrame(pas);
                return;
            }
            opreste(a);
            if (deschide) {
                curata(c);
                modul.classList.remove('et_pb_toggle_close');
                modul.classList.add('et_pb_toggle_open');
            } else {
                c.style.display = 'none';
                curata(c);
                modul.classList.remove('et_pb_toggle_open');
                modul.classList.add('et_pb_toggle_close');
            }
        }
        w.requestAnimationFrame(pas);
    }

    function deschide(modul, c) {
        var a = animatiaDe(c);
        var de = [0, 0, 0, 0, 0];
        if (a) {
            de = a.acum.slice();
            opreste(a);
        }
        c.style.display = 'block';
        var la = naturale(c);
        anim(modul, c, true, de, la);
    }

    function inchide(modul, c) {
        var a = animatiaDe(c);
        var de;
        if (a) {
            de = a.acum.slice();
            opreste(a);
        } else {
            c.style.display = 'block';
            de = naturale(c);
        }
        anim(modul, c, false, de, [0, 0, 0, 0, 0]);
    }

    /** Starea spre care merge modulul: a animației în curs, altfel a clasei. */
    function deschisSau(modul, c) {
        var a = animatiaDe(c);
        if (a) {
            return a.deschide;
        }
        return modul.classList.contains('et_pb_toggle_open');
    }

    function comuta(modul) {
        var c = continut(modul);
        if (!c) {
            return;
        }
        var acordeon = modul.parentNode && modul.parentNode.classList && modul.parentNode.classList.contains('et_pb_accordion') ? modul.parentNode : null;
        if (deschisSau(modul, c)) {
            if (acordeon) {
                return;
            }
            inchide(modul, c);
            return;
        }
        if (acordeon) {
            for (var f = acordeon.firstElementChild; f; f = f.nextElementSibling) {
                var cf = f !== modul && f.classList.contains('et_pb_toggle') ? continut(f) : null;
                if (cf && deschisSau(f, cf)) {
                    inchide(f, cf);
                }
            }
        }
        deschide(modul, c);
    }

    function laClic(e) {
        if (e.button !== undefined && e.button !== 0) {
            return;
        }
        var titlu = stramos(e.target, 'et_pb_toggle_title', null);
        var modul = titlu ? titlu.parentNode : null;
        if (!modul || !modul.classList || !modul.classList.contains('et_pb_toggle')) {
            return;
        }
        comuta(modul);
    }

    function aplica() {
        if (!pornit && d.querySelector('.et_pb_toggle')) {
            pornit = true;
            d.addEventListener('click', laClic);
        }
    }

    ax.toggle = { aplica: aplica, comuta: comuta, durata: DURATA };
    ax.modul('toggle', 'dom', aplica);
}(window, document));
