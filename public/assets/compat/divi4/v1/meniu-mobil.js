/**
 * Runtime-ul compat, divi4/v1: meniul de telefon al modulelor meniu Divi (adoptarea unui site WordPress, felia 2, 8b-1; decizia
 * D1: reprodus). Scris clean-room, din specificația de comportament `meniu-mobil.md` și din observațiile OBS-06 (urma înălțimii,
 * clasa lui `.mobile_nav`, tapul dublu, resize-ul), fără niciun cod de furnizor.
 *
 * La pornire (momentul 'dom', doar cu ax-date valid), pentru fiecare `div.mobile_nav` din document, în ordine, se adaugă la
 * sfârșitul lui (după `span.mobile_menu_bar`) `<ul id="mobile_menuN" class="et_mobile_menu">` (N = 1, 2, 3 … în ordinea
 * documentului) cu o copie a copiilor listei desktop a aceluiași modul (`.et_pb_menu` → `nav.et-menu-nav > ul`): aceleași `li`,
 * aceleași clase, aceleași legături (și `aria-current`), aceleași spații între ele; primul `li` primește la sfârșit clasa
 * `et_first_mobile_item`. Copiile se fac cu `cloneNode` (niciun HTML construit), iar atributele noastre `data-ax-*` și id-urile se
 * scot din copie (pe live copiile n-au nici unele, iar un id dublat ar strica ancorele). Un modul fără listă desktop nu primește
 * nimic. Lista e ascunsă de CSS-ul capturat (`.et_mobile_menu{display:none}`).
 *
 * Clicul (tapul) pe `.mobile_nav`, în afara listei, comută: `closed` → `opened` și lista se deschide (`display:block`, înălțimea
 * de la 0 la înălțimea ei în 500 ms, curba „swing”: 0,5 − cos(πp)/2, cu `overflow:hidden` cât durează), apoi `opened` → `closed`
 * și lista se închide (de la înălțimea de acum la 0 în 500 ms, apoi `display:none`, care rămâne în `style`, ca pe live). Un tap în
 * timpul animației o întoarce din punctul în care a ajuns (OBS-06, tapul dublu la 90 ms). Un tap în afara meniului nu închide
 * nimic; un clic pe o legătură din listă doar navighează. Fără ARIA (nimic adăugat pe live, §5).
 *
 * `axCompat.meniuMobil.aplica()` nu dublează nimic (un `.mobile_nav` care are deja lista e sărit). O a doua includere nu face
 * nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.meniuMobil) {
        return;
    }

    var DURATA = 500;
    var animatii = [];

    function areClasa(el, clasa) {
        return !!el && el.nodeType === 1 && !!el.classList && el.classList.contains(clasa);
    }

    function stramos(el, clasa) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (areClasa(n, clasa)) {
                return n;
            }
        }
        return null;
    }

    function listaDesktop(modul) {
        var navuri = modul.querySelectorAll('nav.et-menu-nav');
        for (var i = 0; i < navuri.length; i++) {
            for (var c = navuri[i].firstElementChild; c; c = c.nextElementSibling) {
                if (c.nodeName === 'UL') {
                    return c;
                }
            }
        }
        return null;
    }

    function curata(nod) {
        if (nod.nodeType !== 1) {
            return;
        }
        var nume = [];
        for (var i = 0; i < nod.attributes.length; i++) {
            var n = nod.attributes[i].name;
            if (n === 'id' || n.indexOf('data-ax-') === 0) {
                nume.push(n);
            }
        }
        for (var j = 0; j < nume.length; j++) {
            nod.removeAttribute(nume[j]);
        }
        for (var c = nod.firstChild; c; c = c.nextSibling) {
            curata(c);
        }
    }

    function listaMobila(nav) {
        for (var c = nav.firstElementChild; c; c = c.nextElementSibling) {
            if (c.nodeName === 'UL' && areClasa(c, 'et_mobile_menu')) {
                return c;
            }
        }
        return null;
    }

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function opreste(ul) {
        for (var i = 0; i < animatii.length; i++) {
            if (animatii[i].ul === ul) {
                animatii[i].oprita = true;
                animatii.splice(i, 1);
                return;
            }
        }
    }

    function anim(ul, de, la, gata) {
        opreste(ul);
        var a = { ul: ul, oprita: false };
        animatii.push(a);
        var start = null;
        ul.style.overflow = 'hidden';
        ul.style.height = de + 'px';
        function pas(t) {
            if (a.oprita) {
                return;
            }
            if (start === null) {
                start = t;
            }
            var p = Math.min(1, (t - start) / DURATA);
            ul.style.height = (de + (la - de) * swing(p)) + 'px';
            if (p < 1) {
                w.requestAnimationFrame(pas);
                return;
            }
            opreste(ul);
            ul.style.removeProperty('height');
            ul.style.removeProperty('overflow');
            gata();
        }
        w.requestAnimationFrame(pas);
    }

    function inaltimeDeschisa(ul) {
        var h = ul.style.height;
        var o = ul.style.overflow;
        ul.style.height = '';
        ul.style.overflow = '';
        var rez = ul.scrollHeight;
        ul.style.height = h;
        ul.style.overflow = o;
        return rez;
    }

    function comuta(nav) {
        var ul = listaMobila(nav);
        if (!ul) {
            return;
        }
        var acum = ul.getBoundingClientRect().height;
        if (areClasa(nav, 'closed')) {
            nav.classList.remove('closed');
            nav.classList.add('opened');
            ul.style.display = 'block';
            var tinta = inaltimeDeschisa(ul);
            anim(ul, acum > 0 && acum < tinta ? acum : 0, tinta, function () {});
        } else {
            nav.classList.remove('opened');
            nav.classList.add('closed');
            anim(ul, acum, 0, function () {
                ul.style.display = 'none';
            });
        }
    }

    function laClic(e) {
        var nav = e.currentTarget;
        var t = e.target;
        for (var n = t; n && n !== nav; n = n.parentNode) {
            if (n.nodeName === 'UL' && areClasa(n, 'et_mobile_menu')) {
                return; // un clic în listă: doar legătura lui (navigarea)
            }
        }
        comuta(nav);
    }

    function aplica() {
        var navuri = d.querySelectorAll('div.mobile_nav');
        var n = d.querySelectorAll('ul.et_mobile_menu').length;
        for (var i = 0; i < navuri.length; i++) {
            var nav = navuri[i];
            if (listaMobila(nav)) {
                continue;
            }
            var modul = stramos(nav, 'et_pb_menu');
            var sursa = modul ? listaDesktop(modul) : null;
            if (!sursa) {
                continue;
            }
            var ul = d.createElement('ul');
            n++;
            ul.setAttribute('id', 'mobile_menu' + n);
            ul.setAttribute('class', 'et_mobile_menu');
            for (var c = sursa.firstChild; c; c = c.nextSibling) {
                var copie = c.cloneNode(true);
                curata(copie);
                ul.appendChild(copie);
            }
            var primul = ul.firstElementChild;
            if (primul && primul.nodeName === 'LI') {
                primul.classList.add('et_first_mobile_item');
            }
            nav.appendChild(ul);
            nav.addEventListener('click', laClic);
        }
    }

    ax.meniuMobil = { aplica: aplica, comuta: comuta };
    ax.modul('meniu-mobil', 'dom', aplica);
}(window, document));
