/**
 * Runtime-ul compat, divi4/v1: modulele DiviMenus (adoptarea unui site WordPress, feliile 2–3; decizia coordonatorului: scrise de
 * noi, fără codul furnizorului). Clean-room, din specificațiile `popup-divimenus.md` și `pini-overflow.md` și din DOM-ul de după
 * scripturi al referinței, fără niciun cod de furnizor.
 *
 * La pornire (momentul 'dom'; pinii și la 'load' și la resize):
 *  - coloana Divi care cuprinde un modul DiviMenus (`.et_pb_divimenus`, pinii; `.et_pb_divimenus_flex`, grila) primește
 *    `dd-has-divimenu`;
 *  - fiecare popup (`.dd-popup-c`, în captură în interiorul itemului lui) e mutat, în ordinea documentului, ca **prim** copil al lui
 *    `.et-l.et-l--post` (deci la sfârșit ordinea e inversă, ca pe live);
 *  - pinii (modulele cu `.dd-menu-bg`/`.dd-item`/`.dd-title` poziționate din CSS): un pin care iese din fereastră la dreapta primește
 *    pe modul `overflow-x: auto`, iar pe `nav.dd-divimenu` `margin-bottom` = înălțimea etichetei și `margin-left` = jumătate din
 *    lățimea ei; unul care iese la stânga e oglindit față de centrul ferestrei (`margin-left` = de două ori distanța centrului lui
 *    până la mijloc, `width` = lățimea etichetei, `margin-bottom` ca mai sus); un pin care nu mai iese își pierde stilurile.
 * Popup-urile: clicul pe `a.dd-modal[data-popup-id]` (sau pe eticheta `.dd-title-clickable` a itemului) deschide popup-ul cu acel
 * id: clasa `open`, `display: flex`, pe `<body>` `dd-popup-active` și, dacă linkul are `dd-disable-page-scroll`,
 * `dd-popup-active--disable-page-scroll` (CSS-ul capturat blochează derularea); cu `data-effect-in="slideup"`, cutia
 * `.dd-menu-item-modal` urcă din `translateY(<înălțimea ei>)` în `data-effect-ms` (curba „swing”, 0,5 − cos(πp)/2), apoi
 * `transform: none`. Închiderea: X-ul (`.dd-close-icon`), clicul pe fundal în afara cutiei, Esc; cu `data-effect-out="slidedown"`
 * cutia coboară întâi. Linkul `#` nu navighează. Un singur popup deschis o dată.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.divimenus) {
        return;
    }

    var ID = /^[A-Za-z][A-Za-z0-9_-]{0,80}$/;
    var deschis = null;
    var pornit = false;

    function stramos(el, sel) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.matches(sel)) {
                return n;
            }
        }
        return null;
    }

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function animeaza(el, de, la, ms, gata) {
        var start = null;
        function pas(t) {
            if (start === null) {
                start = t;
            }
            var p = ms > 0 ? Math.min(1, (t - start) / ms) : 1;
            el.style.transform = 'translateY(' + (de + (la - de) * swing(p)) + 'px)';
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else {
                gata();
            }
        }
        w.requestAnimationFrame(pas);
    }

    function efect(a, nume, implicit) {
        var v = a.getAttribute(nume) || '';
        return /^[a-z]{1,20}$/.test(v) ? v : implicit;
    }

    function deschide(a) {
        var id = a.getAttribute('data-popup-id') || '';
        var pop = ID.test(id) ? d.getElementById(id) : null;
        if (!pop || !pop.classList.contains('dd-popup-c') || deschis) {
            return;
        }
        var ms = parseInt(a.getAttribute('data-effect-ms') || '0', 10);
        deschis = { pop: pop, cutie: pop.querySelector('.dd-menu-item-modal'), blocheaza: a.classList.contains('dd-disable-page-scroll'),
            iesire: efect(a, 'data-effect-out', ''), ms: ms > 0 && ms <= 5000 ? ms : 0, inchidere: false };
        pop.classList.add('open');
        pop.style.display = 'flex';
        d.body.classList.add('dd-popup-active');
        if (deschis.blocheaza) {
            d.body.classList.add('dd-popup-active--disable-page-scroll');
        }
        if (efect(a, 'data-effect-in', '') === 'slideup' && deschis.cutie && deschis.ms > 0) {
            var c = deschis.cutie;
            var h = c.getBoundingClientRect().height;
            c.style.transform = 'translateY(' + h + 'px)';
            animeaza(c, h, 0, deschis.ms, function () {
                c.style.transform = 'none';
            });
        }
    }

    function inchide() {
        var o = deschis;
        if (!o || o.inchidere) {
            return;
        }
        o.inchidere = true;
        var gata = function () {
            o.pop.style.display = 'none';
            o.pop.classList.remove('open');
            d.body.classList.remove('dd-popup-active');
            d.body.classList.remove('dd-popup-active--disable-page-scroll');
            deschis = null;
        };
        if (o.iesire === 'slidedown' && o.cutie && o.ms > 0) {
            animeaza(o.cutie, 0, o.cutie.getBoundingClientRect().height, o.ms, function () {
                gata();
                o.cutie.style.transform = 'none';
            });
        } else {
            gata();
        }
    }

    function laClic(e) {
        var t = e.target;
        if (deschis) {
            if (stramos(t, '.dd-close-icon') || (deschis.pop.contains(t) && !(deschis.cutie && deschis.cutie.contains(t)))) {
                e.preventDefault();
                inchide();
            }
            return;
        }
        var a = stramos(t, 'a.dd-modal');
        if (!a) {
            var eticheta = stramos(t, '.dd-title-clickable');
            var item = eticheta ? stramos(eticheta, '.et_pb_divimenus_item, .et_pb_divimenus_flex_item') : null;
            a = item ? item.querySelector('a.dd-modal') : null;
        }
        if (a && a.hasAttribute('data-popup-id')) {
            e.preventDefault();
            deschide(a);
        }
    }

    function laTasta(e) {
        if (e.key === 'Escape' && deschis) {
            inchide();
        }
    }

    function cutii(m) {
        var rez = [];
        var lista = m.querySelectorAll('.dd-menu-bg, .dd-item, .dd-title');
        for (var i = 0; i < lista.length; i++) {
            var r = lista[i].getBoundingClientRect();
            if (r.width > 0 || r.height > 0) {
                rez.push(r);
            }
        }
        return rez;
    }

    function pini() {
        var module = d.querySelectorAll('.et_pb_divimenus');
        var W = w.innerWidth;
        for (var i = 0; i < module.length; i++) {
            var m = module[i];
            var nav = m.querySelector('nav.dd-divimenu');
            var titlu = m.querySelector('.dd-title');
            if (!nav || !titlu || !m.querySelector('.dd-menu-bg')) {
                continue;
            }
            m.style.removeProperty('overflow-x');
            nav.style.removeProperty('margin-bottom');
            nav.style.removeProperty('margin-left');
            nav.style.removeProperty('width');
            var c = cutii(m);
            if (!c.length) {
                continue;
            }
            var stanga = Infinity;
            var dreapta = -Infinity;
            for (var j = 0; j < c.length; j++) {
                stanga = Math.min(stanga, c[j].left);
                dreapta = Math.max(dreapta, c[j].right);
            }
            var rt = titlu.getBoundingClientRect();
            if (dreapta > W) {
                m.style.overflowX = 'auto';
                nav.style.marginBottom = rt.height + 'px';
                nav.style.marginLeft = (rt.width / 2) + 'px';
            } else if (stanga < 0) {
                var centru = nav.getBoundingClientRect();
                nav.style.marginBottom = rt.height + 'px';
                nav.style.marginLeft = (2 * (W / 2 - (centru.left + centru.width / 2))) + 'px';
                nav.style.width = rt.width + 'px';
            }
        }
    }

    function aplica() {
        var module = d.querySelectorAll('.et_pb_divimenus, .et_pb_divimenus_flex');
        for (var i = 0; i < module.length; i++) {
            var col = stramos(module[i].parentNode, '.et_pb_column');
            if (col && !col.classList.contains('dd-has-divimenu')) {
                col.classList.add('dd-has-divimenu');
            }
        }
        var tinta = d.querySelector('.et-l.et-l--post');
        var popupuri = d.querySelectorAll('.et_pb_divimenus .dd-popup-c, .et_pb_divimenus_flex .dd-popup-c');
        for (var j = 0; tinta && j < popupuri.length; j++) {
            tinta.insertBefore(popupuri[j], tinta.firstChild);
        }
        if (!pornit) {
            pornit = true;
            d.addEventListener('click', laClic);
            d.addEventListener('keydown', laTasta);
            if (d.querySelector('.et_pb_divimenus')) {
                w.addEventListener('resize', pini);
            }
        }
        if (d.querySelector('.et_pb_divimenus')) {
            pini();
        }
    }

    ax.divimenus = { aplica: aplica, inchide: inchide, pini: pini };
    ax.modul('divimenus', 'dom', aplica);
    ax.modul('divimenus-load', 'load', function () {
        if (d.querySelector('.et_pb_divimenus')) {
            pini();
        }
    });
}(window, document));
