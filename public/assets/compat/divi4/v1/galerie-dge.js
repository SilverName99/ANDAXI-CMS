/**
 * Runtime-ul compat, divi4/v1: grila galeriei (modulul `el_dynamic_masonry_gallery`, adoptarea unui site WordPress, felia 2;
 * decizia coordonatorului: scrisă de noi, fără biblioteca furnizorului). Clean-room, din specificația `galerie-dge.md` §2 și din
 * DOM-ul de după scripturi al referinței (stilurile fiecărui element), fără niciun cod de furnizor.
 *
 * Așezarea „masonry”: în `.el_masonry_gallery_wrapper`, fiecare `.el_masonry_gallery_item`, în ordinea DOM, intră în coloana cea
 * mai scurtă (la egalitate, cea din stânga). Coloana = lățimea primului element (din stilul calculat, cu marginile) plus
 * spațiul dat de `.el_masonry_gallery_item_gutter`; numărul de coloane = (lățimea grilei + spațiu) / coloană, rotunjit în jos
 * (în sus doar când lipsește mai puțin de 1 px). Elementul primește `style="position: absolute; left: <x / lățimea grilei>%;
 * top: <y>px;"` (stânga în procente, ca pe live), grila `style="position: relative; height: <coloana cea mai înaltă>px;"`;
 * înălțimea unui element = înălțimea lui calculată plus marginile (deci `margin-bottom` din CSS-ul capturat e spațiul vertical).
 * Re-așezare: la momentul 'dom', la încărcarea fiecărei imagini din grilă, la 'load' și la resize; o așezare care nu schimbă
 * nimic nu scrie nimic.
 *
 * Filtrele (felia 3, cu răspunsul serverului) nu sunt aici. `axCompat.galerieDge.aplica()` poate fi chemat din nou (re-așează).
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.galerieDge) {
        return;
    }

    var grile = [];

    function px(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    function elemente(g) {
        var rez = [];
        for (var c = g.firstElementChild; c; c = c.nextElementSibling) {
            if (c.classList.contains('el_masonry_gallery_item') && w.getComputedStyle(c).display !== 'none') {
                rez.push(c);
            }
        }
        return rez;
    }

    function stil(el, valori) {
        for (var i = 0; i < valori.length; i++) {
            if (el.style.getPropertyValue(valori[i][0]) !== valori[i][1]) {
                el.style.setProperty(valori[i][0], valori[i][1]);
            }
        }
    }

    function aseaza(g) {
        var lista = elemente(g);
        if (!lista.length) {
            return;
        }
        var cs = w.getComputedStyle(g);
        var latime = px(cs.width) - px(cs.paddingLeft) - px(cs.paddingRight) - px(cs.borderLeftWidth) - px(cs.borderRightWidth);
        if (cs.boxSizing !== 'border-box') {
            latime = px(cs.width);
        }
        if (!(latime > 0)) {
            return;
        }
        var gut = g.querySelector('.el_masonry_gallery_item_gutter');
        var spatiu = gut ? gut.getBoundingClientRect().width : 0;
        var p = w.getComputedStyle(lista[0]);
        var coloana = px(p.width) + px(p.marginLeft) + px(p.marginRight) + spatiu;
        if (!(coloana > 0)) {
            return;
        }
        var total = latime + spatiu;
        var cate = total / coloana;
        var lipsa = coloana - total % coloana;
        cate = Math.max(1, lipsa && lipsa < 1 ? Math.round(cate) : Math.floor(cate));
        var y = [];
        for (var k = 0; k < cate; k++) {
            y.push(0);
        }
        stil(g, [['position', 'relative']]);
        for (var i = 0; i < lista.length; i++) {
            var min = Math.min.apply(Math, y);
            var col = y.indexOf(min);
            var e = w.getComputedStyle(lista[i]);
            var inalt = px(e.height) + px(e.marginTop) + px(e.marginBottom);
            stil(lista[i], [['position', 'absolute'], ['left', (col * coloana / latime * 100) + '%'], ['top', min + 'px']]);
            y[col] = min + inalt;
        }
        stil(g, [['height', Math.max.apply(Math, y) + 'px']]);
    }

    function reaseaza() {
        for (var i = 0; i < grile.length; i++) {
            aseaza(grile[i]);
        }
    }

    function aplica() {
        var lista = d.querySelectorAll('.el_masonry_gallery_wrapper');
        for (var i = 0; i < lista.length; i++) {
            if (grile.indexOf(lista[i]) < 0) {
                grile.push(lista[i]);
                var imagini = lista[i].querySelectorAll('img');
                for (var j = 0; j < imagini.length; j++) {
                    if (!imagini[j].complete) {
                        imagini[j].addEventListener('load', reaseaza);
                    }
                }
                if (grile.length === 1) {
                    w.addEventListener('resize', reaseaza);
                }
            }
        }
        reaseaza();
    }

    ax.galerieDge = { aplica: aplica, reaseaza: reaseaza };
    ax.modul('galerie-dge', 'dom', aplica);
    ax.modul('galerie-dge-load', 'load', reaseaza);
}(window, document));
