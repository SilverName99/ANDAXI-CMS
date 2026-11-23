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
 * Filtrele (`.el_masonry_gallery_filter_categories li[data-category]`, „Toate / Hotelul / …” pe Galerie): pe live, clicul cere
 * imaginile categoriei serverului WordPress (admin-ajax, `dge_get_images`). Aici fără server: toate imaginile tuturor categoriilor
 * sunt deja în pagină („Toate” e lista încărcată), iar fiecare `<img>` poartă ca clase slug-urile categoriilor lui (observat pe
 * live: răspunsul pentru „Hotelul” = exact cele 12 imagini cu clasa `hotelul`, în aceeași ordine). Clicul pe o categorie
 * neactivă: clasa `el_masonry_gallery_active_category` se mută pe ea; grila se estompează la `opacity: 0.2` în 400 ms
 * („swing”), apoi în ea rămân doar elementele categoriei (slug-ul numelui: litere mici, fără diacritice, spațiile → „-”;
 * „Toate”, `data-category` gol, = toate), în ordinea inițială, re-așezate, cu `opacity: 1` pe grilă; elementele apar din
 * `opacity: 0; transform: scale(0.001)` în 400 ms, apoi rămân doar cu poziția (ca pe live). Ultimul clic câștigă; cu
 * `prefers-reduced-motion: reduce`, schimbarea e pe loc. Lightbox-ul (lightbox-mfp.js) vede doar elementele din grilă.
 * `axCompat.galerieDge.aplica()` poate fi chemat din nou (re-așează).
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.galerieDge) {
        return;
    }

    var grile = [];
    var filtre = [];
    var ACTIV = 'el_masonry_gallery_active_category';
    var DURATA = 400;

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

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function miscareRedusa() {
        try {
            return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    function stramos(el, clasa) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList.contains(clasa)) {
                return n;
            }
        }
        return null;
    }

    /** Slug-ul unui nume de categorie, ca la WordPress: litere mici, fără diacritice, orice altceva → „-”. */
    function slug(text) {
        return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function dinCategorie(item, s) {
        if (item.classList.contains(s)) {
            return true;
        }
        var img = item.querySelectorAll('img');
        for (var i = 0; i < img.length; i++) {
            if (img[i].classList.contains(s)) {
                return true;
            }
        }
        return false;
    }

    /** Grila cu elementele alese (din lista inițială), re-așezată; cu apariția lor, ca pe live. */
    function schimba(f, alese, anim) {
        var g = f.grila;
        for (var i = 0; i < f.toate.length; i++) {
            if (f.toate[i].parentNode === g && alese.indexOf(f.toate[i]) < 0) {
                g.removeChild(f.toate[i]);
            }
        }
        for (var j = 0; j < alese.length; j++) {
            g.appendChild(alese[j]);
            alese[j].style.removeProperty('transition-property');
            alese[j].style.removeProperty('transition-duration');
            if (anim) {
                alese[j].style.setProperty('opacity', '0');
                alese[j].style.setProperty('transform', 'scale(0.001)');
            }
        }
        aseaza(g);
        g.style.setProperty('opacity', '1');
        if (!anim) {
            return;
        }
        void g.offsetHeight; // stilurile de pornire aplicate înaintea tranziției
        var gen = f.generatie;
        for (var k = 0; k < alese.length; k++) {
            alese[k].style.setProperty('transition-property', 'opacity, transform');
            alese[k].style.setProperty('transition-duration', DURATA + 'ms');
            alese[k].style.setProperty('opacity', '1');
            alese[k].style.setProperty('transform', 'scale(1)');
        }
        w.setTimeout(function () {
            if (f.generatie !== gen) {
                return;
            }
            for (var m = 0; m < alese.length; m++) {
                ['transition-property', 'transition-duration', 'opacity', 'transform'].forEach(function (p) {
                    alese[m].style.removeProperty(p);
                });
            }
        }, DURATA + 50);
    }

    function laFiltru(e) {
        var li = e.target && e.target.closest ? e.target.closest('.el_masonry_gallery_filter_categories li') : null;
        var modul = li ? stramos(li, 'el_dynamic_masonry_gallery') : null;
        var f = null;
        for (var i = 0; modul && i < filtre.length; i++) {
            if (filtre[i].modul === modul) {
                f = filtre[i];
            }
        }
        if (!f || li.classList.contains(ACTIV)) {
            return;
        }
        var lis = li.parentNode.children;
        for (var j = 0; j < lis.length; j++) {
            lis[j].classList.remove(ACTIV);
        }
        li.classList.add(ACTIV);
        var s = (li.getAttribute('data-category') || '') === '' ? '' : slug(li.textContent);
        var alese = s === '' ? f.toate.slice() : f.toate.filter(function (it) {
            return dinCategorie(it, s);
        });
        f.generatie++;
        var gen = f.generatie;
        if (miscareRedusa()) {
            schimba(f, alese, false);
            return;
        }
        var de = parseFloat(w.getComputedStyle(f.grila).opacity);
        de = isFinite(de) ? de : 1;
        var start = w.performance && w.performance.now ? w.performance.now() : Date.now();
        function pas() {
            if (f.generatie !== gen) {
                return;
            }
            var acum = w.performance && w.performance.now ? w.performance.now() : Date.now();
            var p = Math.max(0, Math.min(1, (acum - start) / DURATA));
            f.grila.style.setProperty('opacity', String(de + (0.2 - de) * swing(p)));
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else {
                schimba(f, alese, true);
            }
        }
        w.requestAnimationFrame(pas);
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
                var modul = stramos(lista[i], 'el_dynamic_masonry_gallery');
                if (modul && modul.querySelector('.el_masonry_gallery_filter_categories li')) {
                    var toate = [];
                    for (var c = lista[i].firstElementChild; c; c = c.nextElementSibling) {
                        if (c.classList.contains('el_masonry_gallery_item')) {
                            toate.push(c);
                        }
                    }
                    filtre.push({ modul: modul, grila: lista[i], toate: toate, generatie: 0 });
                    if (filtre.length === 1) {
                        d.addEventListener('click', laFiltru);
                    }
                }
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
