/**
 * Runtime-ul compat, divi4/v1: lightbox-urile de imagini ale galeriei și ale modulelor imagine Divi (adoptarea unui site
 * WordPress, felia 3; decizia coordonatorului: scrise de noi, fără biblioteca furnizorului). Clean-room, din specificația
 * `lightbox-mfp.md` și din DOM-ul observat la deschidere (marcajul, clasele, stilurile), fără niciun cod de furnizor; elementele
 * se construiesc unul câte unul, nimic din text.
 *
 * Declanșatoare (clic, delegat pe document):
 *  - galeria: un `.el_masonry_gallery_item` dintr-un `.el_masonry_gallery_wrapper[data-enable_lightbox=on]`: setul = elementele
 *    afișate ale grilei, în ordinea grilei; sursa = `data-mfp-src`; clasele principale `mfp-img-mobile el_masonry_gallery_lightbox
 *    el_dynamic_masonry_gallery_<N>_lightbox dipl_mfp_<efect>`, galerie (săgeți, ← →, circular) cu `data-enable_navigation=on`;
 *    butonul × în afara conținutului; fără animație;
 *  - imaginea Divi: `a.et_pb_lightbox_image[href]`: o singură imagine, clasa `mfp-fade` (fundalul trece la 0,8 prin CSS-ul capturat),
 *    butonul × în figură, închiderea după 500 ms (`mfp-removing`).
 * Fiecare adresă trece prin `adresaSigura()` (https sau a site-ului); altfel nu se deschide nimic.
 *
 * DOM-ul (la sfârșitul lui `<body>`): `div.mfp-bg` și `div.mfp-wrap[tabindex=-1]` > `div.mfp-container.mfp-s-ready.mfp-image-holder` >
 * `div.mfp-content` > `div.mfp-figure` (galeria: `div.mfp-close`, `img.mfp-img`, `div.mfp-bottom-bar > div.mfp-title`; imaginea Divi:
 * `button.mfp-close`, `figure` > `img.mfp-img`, `figcaption` > bara cu titlul și contorul), apoi `div.mfp-preloader` («Loading...»),
 * săgețile `button.mfp-arrow.mfp-arrow-left|right.mfp-prevent-close[title=""]` și, pentru galerie, `button.mfp-close[title="Close (Esc)"]`
 * «×» după container. `mfp-ready` vine la 16 ms (fade-ul pornește din CSS). Imaginea: `max-height` = înălțimea ferestrei minus
 * umplutura ei. Pe desktop wrap-ul are `overflow: hidden auto` și `<html>` `overflow: hidden` (plus lățimea barei de derulare ca
 * `margin-right`); pe telefon (după UA, ca pe live) fundalul acoperă tot documentul (`position: absolute`), iar derularea rămâne.
 * Închidere: Esc, ×, clicul în afara conținutului; clicul pe imagine sau pe săgeți nu închide. Focusul trece pe wrap la deschidere
 * și revine la elementul de dinainte la închidere.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.lightboxMfp) {
        return;
    }

    var MOBIL = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i;
    var deschis = null;

    function el(tag, clasa, text) {
        var e = d.createElement(tag);
        if (clasa) {
            e.setAttribute('class', clasa);
        }
        if (text) {
            e.appendChild(d.createTextNode(text));
        }
        return e;
    }

    function buton(clasa, titlu, text) {
        var b = d.createElement('button');
        b.setAttribute('title', titlu);
        b.setAttribute('type', 'button');
        b.setAttribute('class', clasa);
        if (text) {
            b.appendChild(d.createTextNode(text));
        }
        return b;
    }

    function sigura(src) {
        return typeof src === 'string' && /^(https:\/\/|\/)[^\s"'<>\\]{1,800}$/.test(src) && ax.adresaSigura(src);
    }

    function cauta(t, sel) {
        for (var n = t; n && n.nodeType === 1; n = n.parentNode) {
            if (n.matches(sel)) {
                return n;
            }
        }
        return null;
    }

    function arata(i) {
        var o = deschis;
        o.index = (i + o.surse.length) % o.surse.length;
        o.img.setAttribute('src', o.surse[o.index]);
        var cs = w.getComputedStyle(o.img);
        var scade = (parseInt(cs.paddingTop, 10) || 0) + (parseInt(cs.paddingBottom, 10) || 0);
        o.img.style.maxHeight = ((o.fix ? w.innerHeight : d.documentElement.clientHeight) - scade) + 'px';
    }

    function inchide() {
        var o = deschis;
        if (!o) {
            return;
        }
        deschis = null;
        d.removeEventListener('keydown', laTasta);
        var scoate = function () {
            if (o.bg.parentNode) {
                o.bg.parentNode.removeChild(o.bg);
            }
            if (o.wrap.parentNode) {
                o.wrap.parentNode.removeChild(o.wrap);
            }
        };
        if (o.intarziere > 0) {
            o.bg.classList.add('mfp-removing');
            o.wrap.classList.add('mfp-removing');
            w.setTimeout(scoate, o.intarziere);
        } else {
            scoate();
        }
        if (o.fix) {
            d.documentElement.style.overflow = '';
            d.documentElement.style.marginRight = '';
        }
        if (o.focus && typeof o.focus.focus === 'function') {
            o.focus.focus();
        }
    }

    function laTasta(e) {
        if (!deschis) {
            return;
        }
        if (e.key === 'Escape') {
            inchide();
        } else if (deschis.galerie && e.key === 'ArrowLeft') {
            arata(deschis.index - 1);
        } else if (deschis.galerie && e.key === 'ArrowRight') {
            arata(deschis.index + 1);
        }
    }

    function laClicWrap(e) {
        var o = deschis;
        if (!o) {
            return;
        }
        var t = e.target;
        var sageata = cauta(t, '.mfp-arrow');
        if (sageata) {
            arata(o.index + (sageata.classList.contains('mfp-arrow-left') ? -1 : 1));
            return;
        }
        if (cauta(t, '.mfp-prevent-close')) {
            return;
        }
        if (cauta(t, '.mfp-close') || t === o.preloader || !o.continut.contains(t)) {
            inchide();
        }
    }

    function deschide(surse, index, fel) {
        if (deschis || !surse.length) {
            return;
        }
        var galerie = fel.galerie;
        var fix = !MOBIL.test(w.navigator.userAgent || '');
        var bg = el('div', 'mfp-bg' + (fel.clase ? ' ' + fel.clase : ''));
        var wrap = el('div', 'mfp-wrap' + (galerie ? ' mfp-gallery' : '') + (fel.inchidereInauntru ? ' mfp-close-btn-in' : '') + ' mfp-auto-cursor'
            + (fel.clase ? ' ' + fel.clase : ''));
        wrap.setAttribute('tabindex', '-1');
        var cont = el('div', 'mfp-container mfp-s-ready mfp-image-holder');
        var continut = el('div', 'mfp-content');
        var fig = el('div', 'mfp-figure');
        var img = el('img', 'mfp-img');
        img.setAttribute('alt', '');
        if (fel.inchidereInauntru) {
            fig.appendChild(buton('mfp-close', 'Close (Esc)', '×'));
            var figura = el('figure', '');
            figura.removeAttribute('class');
            figura.appendChild(img);
            var leg = el('figcaption', '');
            leg.removeAttribute('class');
            var bara = el('div', 'mfp-bottom-bar');
            bara.appendChild(el('div', 'mfp-title'));
            bara.appendChild(el('div', 'mfp-counter'));
            leg.appendChild(bara);
            figura.appendChild(leg);
            fig.appendChild(figura);
        } else {
            fig.appendChild(el('div', 'mfp-close'));
            fig.appendChild(img);
            var bara2 = el('div', 'mfp-bottom-bar');
            bara2.appendChild(el('div', 'mfp-title'));
            fig.appendChild(bara2);
        }
        continut.appendChild(fig);
        cont.appendChild(continut);
        var preloader = el('div', 'mfp-preloader', 'Loading...');
        cont.appendChild(preloader);
        if (galerie) {
            cont.appendChild(buton('mfp-arrow mfp-arrow-left mfp-prevent-close', '', ''));
            cont.appendChild(buton('mfp-arrow mfp-arrow-right mfp-prevent-close', '', ''));
        }
        wrap.appendChild(cont);
        if (!fel.inchidereInauntru) {
            wrap.appendChild(buton('mfp-close', 'Close (Esc)', '×'));
        }
        if (fix) {
            wrap.style.overflowX = 'hidden';
            wrap.style.overflowY = 'auto';
            var bara3 = w.innerWidth - d.documentElement.clientWidth;
            if (bara3 > 0) {
                d.documentElement.style.marginRight = bara3 + 'px';
            }
            d.documentElement.style.overflow = 'hidden';
        } else {
            wrap.style.top = (w.pageYOffset || 0) + 'px';
            wrap.style.position = 'absolute';
            bg.style.height = d.documentElement.scrollHeight + 'px';
            bg.style.position = 'absolute';
        }
        d.body.appendChild(bg);
        d.body.appendChild(wrap);
        deschis = { surse: surse, index: 0, galerie: galerie, fix: fix, bg: bg, wrap: wrap, continut: continut, img: img, preloader: preloader,
            intarziere: fel.intarziere, focus: d.activeElement };
        arata(index);
        wrap.addEventListener('click', laClicWrap);
        d.addEventListener('keydown', laTasta);
        w.setTimeout(function () {
            if (deschis && deschis.wrap === wrap) {
                bg.classList.add('mfp-ready');
                wrap.classList.add('mfp-ready');
                wrap.focus();
            }
        }, 16);
    }

    function laClic(e) {
        if (e.defaultPrevented || e.button > 0) {
            return;
        }
        var t = e.target;
        var item = cauta(t, '.el_masonry_gallery_item');
        var grila = item ? item.parentNode : null;
        if (item && grila && grila.classList.contains('el_masonry_gallery_wrapper') && grila.getAttribute('data-enable_lightbox') === 'on') {
            var surse = [];
            var index = -1;
            for (var c = grila.firstElementChild; c; c = c.nextElementSibling) {
                if (c.classList.contains('el_masonry_gallery_item') && w.getComputedStyle(c).display !== 'none' && sigura(c.getAttribute('data-mfp-src'))) {
                    if (c === item) {
                        index = surse.length;
                    }
                    surse.push(c.getAttribute('data-mfp-src'));
                }
            }
            if (index < 0) {
                return;
            }
            e.preventDefault();
            var modul = cauta(grila, '.el_dynamic_masonry_gallery');
            var n = modul ? /\bel_dynamic_masonry_gallery_([0-9]{1,3})\b/.exec(modul.className) : null;
            var efect = /^[a-z_-]{1,20}$/.test(grila.getAttribute('data-lightbox_effect') || '') ? grila.getAttribute('data-lightbox_effect') : 'none';
            deschide(surse, index, { galerie: grila.getAttribute('data-enable_navigation') === 'on', inchidereInauntru: false, intarziere: 0,
                clase: 'mfp-img-mobile el_masonry_gallery_lightbox' + (n ? ' el_dynamic_masonry_gallery_' + n[1] + '_lightbox' : '') + ' dipl_mfp_' + efect });
            return;
        }
        var a = cauta(t, 'a.et_pb_lightbox_image');
        if (a && sigura(a.getAttribute('href'))) {
            e.preventDefault();
            deschide([a.getAttribute('href')], 0, { galerie: false, inchidereInauntru: true, intarziere: 500, clase: 'mfp-fade' });
        }
    }

    function aplica() {
        if (!ax.lightboxMfp.pornit) {
            ax.lightboxMfp.pornit = true;
            d.addEventListener('click', laClic);
        }
    }

    ax.lightboxMfp = { aplica: aplica, inchide: inchide, pornit: false };
    ax.modul('lightbox-mfp', 'dom', aplica);
}(window, document));
