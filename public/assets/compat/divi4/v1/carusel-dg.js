/**
 * Runtime-ul compat, divi4/v1: caruselele dg (modulul `dica_divi_carousel`) și deschiderea lightbox-ului lor (adoptarea unui
 * site WordPress, felia 2 și VC-CAMERE-023; decizia coordonatorului: un carusel al nostru, fără biblioteca furnizorului). Scris
 * clean-room, din specificația de comportament `carusel-dg.md` (parametrii și urmele observate pe live) și din DOM-ul de după
 * scripturi al referinței (clasele, atributele, duplicatele, stilurile), fără niciun cod de furnizor.
 *
 * Configurația: `data-props` din `.dica-container` (JSON citit strict; doar cheile știute, cu valori numerice mărginite):
 * slide-uri pe ecran și spațiul pe punctul de rupere (≥981 `desktop`/`item_spacing`, 768–980 `tablet`/`item_spacing_tablet`,
 * <768 `mobile`/`item_spacing_phone`), `speed`, `loop`, `dots`. Tastatura, rotița și autoplay rămân oprite (ca pe live);
 * tragerea cu mouse-ul și glisarea cu degetul merg (inversarea observată a lui „off”, carusel-dg.md §2).
 *
 * La pornire (momentul 'dom'; o reașezare la 'load' și la resize, fără animație), pe fiecare carusel:
 *  - containerul `.swiper-container` primește `swiper-container-initialized swiper-container-horizontal`, iar la sfârșit
 *    `span.swiper-notification[aria-live=assertive][aria-atomic=true]`; săgețile `tabindex=0 role=button aria-label`; cu buline,
 *    paginarea primește `swiper-pagination-clickable swiper-pagination-bullets` și câte un `span.swiper-pagination-bullet
 *    [tabindex=0][role=button][aria-label="Go to slide K"]` (activă `swiper-pagination-bullet-active`);
 *  - cu buclă: `data-swiper-slide-index` pe slide-uri, apoi copiile (cloneNode) ultimelor N slide-uri în față și ale primelor N
 *    la sfârșit, cu `swiper-slide-duplicate` (N = slide-urile pe ecran);
 *  - lățimea slide-urilor: (lățimea containerului − spațiile) / numărul pe ecran, în `style` (`width`, apoi `margin-right`);
 *    banda (`.swiper-wrapper`) la poziția slide-ului activ (`translate3d(−x px, 0px, 0px)`; cu buclă și `transition-duration`);
 *  - clasele `swiper-slide-active`/`-next`/`-prev`, perechile lor `swiper-slide-duplicate-*` pe slide-urile cu același index,
 *    `swiper-slide-visible` pe cele care se văd în container; fără buclă, săgeata de la capăt `swiper-button-disabled`,
 *    `aria-disabled=true`, `tabindex=-1`;
 *  - un carusel ascuns (lățime 0, de exemplu doar pe telefon): ca pe live, fără lățimi și fără poziție (`transition-duration: 0ms`
 *    pe bandă), duplicatele și `swiper-slide-duplicate-active` pe copiile slide-ului N, paginarea goală, săgețile
 *    `aria-disabled=false` fără buclă; la primul resize cu lățime, așezarea completă.
 * Mișcarea: săgețile (cu buclă, un clic în timpul tranziției nu face nimic; anunțul `Next slide`/`Previous slide`, la capete
 * `This is the last slide`/`This is the first slide`, în `swiper-notification`), bulinele, tragerea (prag 15 px; banda urmează
 * degetul; la eliberare, după 300 ms, slide-ul cel mai apropiat de jumătate, sub 300 ms următorul în direcția tragerii; clicul
 * de după o tragere nu ajunge la legături). Tranziția: `transition-duration` = `speed` pe bandă, curba din CSS-ul capturat.
 *
 * Lightbox-ul dg (carusel-dg.md §7): clicul pe `a.image[data-lightbox=on]` dintr-un carusel pune `data-src` (o adresă trecută
 * prin `adresaSigura()`) în imaginea containerului `.dg-carousel-lightbox` (adăugat de dg-lightbox.js) și îi dă clasa `open`;
 * clicul pe ╳ sau pe fundal o scoate (nu și clicul pe imagine); Esc nu face nimic, ca pe live.
 *
 * `axCompat.caruselDg.aplica()` nu dublează nimic (un carusel pornit e sărit).
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.caruselDg) {
        return;
    }

    var PRAG = 15;
    var LUNG_MS = 300;
    var CLASE = ['swiper-slide-active', 'swiper-slide-next', 'swiper-slide-prev', 'swiper-slide-visible', 'swiper-slide-duplicate-active',
        'swiper-slide-duplicate-next', 'swiper-slide-duplicate-prev'];
    var instante = [];

    function areClasa(el, k) {
        return !!el && el.nodeType === 1 && !!el.classList && el.classList.contains(k);
    }

    function numar(v, implicit, min, max) {
        var n = typeof v === 'number' ? v : (typeof v === 'string' && /^[0-9]{1,5}(\.[0-9]{1,3})?$/.test(v) ? parseFloat(v) : NaN);
        return isFinite(n) && n >= min && n <= max ? n : implicit;
    }

    function citesteProps(cont) {
        var t = cont.getAttribute('data-props') || '';
        var p = null;
        try {
            p = JSON.parse(t);
        } catch (e) {
            p = null;
        }
        return p && typeof p === 'object' && Object.prototype.toString.call(p) === '[object Object]' ? p : {};
    }

    function punct() {
        var l = w.innerWidth;
        return l >= 981 ? 'desktop' : (l >= 768 ? 'tablet' : 'mobile');
    }

    function parametri(p) {
        var b = punct();
        var cheieSpatiu = b === 'desktop' ? 'item_spacing' : (b === 'tablet' ? 'item_spacing_tablet' : 'item_spacing_phone');
        return {
            pePagina: Math.floor(numar(p[b], 1, 1, 12)),
            spatiu: numar(p[cheieSpatiu], numar(p.item_spacing, 0, 0, 300), 0, 300)
        };
    }

    function copii(wr) {
        var rez = [];
        for (var c = wr.firstElementChild; c; c = c.nextElementSibling) {
            if (areClasa(c, 'dica_divi_carouselitem')) {
                rez.push(c);
            }
        }
        return rez;
    }

    function translatie(x) {
        return 'translate3d(' + x + 'px, 0px, 0px)';
    }

    function duplicate(c, pePagina) {
        var vechi = c.wr.querySelectorAll('.swiper-slide-duplicate');
        for (var i = 0; i < vechi.length; i++) {
            if (vechi[i].parentNode === c.wr) {
                c.wr.removeChild(vechi[i]);
            }
        }
        var n = c.originale.length;
        var k = Math.min(pePagina, n);
        var primul = c.originale[0];
        for (var a = n - k; a < n; a++) {
            var fata = c.originale[a].cloneNode(true);
            fata.classList.add('swiper-slide-duplicate');
            c.wr.insertBefore(fata, primul);
        }
        for (var b = 0; b < k; b++) {
            var spate = c.originale[b].cloneNode(true);
            spate.classList.add('swiper-slide-duplicate');
            c.wr.appendChild(spate);
        }
        c.dupl = k;
    }

    function index(el) {
        return parseInt(el.getAttribute('data-swiper-slide-index') || '-1', 10);
    }

    function clase(c) {
        var s = copii(c.wr);
        var i;
        for (i = 0; i < s.length; i++) {
            for (var j = 0; j < CLASE.length; j++) {
                s[i].classList.remove(CLASE[j]);
            }
        }
        if (c.ascuns) {
            if (c.loop) {
                var real = c.dupl % c.originale.length;
                for (i = 0; i < s.length; i++) {
                    if (areClasa(s[i], 'swiper-slide-duplicate') && index(s[i]) === real) {
                        s[i].classList.add('swiper-slide-duplicate-active');
                    }
                }
            }
            return;
        }
        // Vizibile (întâi, ca ordinea claselor de pe live): slide-urile care intră în container la poziția de acum (offsetLeft).
        var centru = -c.translate;
        for (i = 0; i < s.length; i++) {
            var inainte = -(centru - s[i].offsetLeft);
            var dupa = inainte + c.marime;
            if ((inainte >= 0 && inainte < c.latime - 1) || (dupa > 1 && dupa <= c.latime) || (inainte <= 0 && dupa >= c.latime)) {
                s[i].classList.add('swiper-slide-visible');
            }
        }
        var act = s[c.activ];
        var urm = s[c.activ + 1] || (c.loop ? s[0] : null);
        var ant = s[c.activ - 1] || (c.loop ? s[s.length - 1] : null);
        var perechi = [[act, 'active'], [urm, 'next'], [ant, 'prev']];
        for (i = 0; i < perechi.length; i++) {
            var el = perechi[i][0];
            if (!el) {
                continue;
            }
            el.classList.add('swiper-slide-' + perechi[i][1]);
            if (c.loop) {
                var dup = areClasa(el, 'swiper-slide-duplicate');
                for (var k = 0; k < s.length; k++) {
                    if (areClasa(s[k], 'swiper-slide-duplicate') !== dup && index(s[k]) === index(el)) {
                        s[k].classList.add('swiper-slide-duplicate-' + perechi[i][1]);
                    }
                }
            }
        }
    }

    function buline(c) {
        if (!c.pag || !c.dots) {
            return;
        }
        if (c.ascuns) {
            while (c.pag.firstChild) {
                c.pag.removeChild(c.pag.firstChild);
            }
            return;
        }
        var cate = c.loop ? c.originale.length : c.puncte.length;
        if (c.pag.children.length !== cate) {
            while (c.pag.firstChild) {
                c.pag.removeChild(c.pag.firstChild);
            }
            for (var i = 0; i < cate; i++) {
                var b = d.createElement('span');
                b.setAttribute('class', 'swiper-pagination-bullet');
                b.setAttribute('tabindex', '0');
                b.setAttribute('role', 'button');
                b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                c.pag.appendChild(b);
            }
        }
        var activ = c.loop ? index(copii(c.wr)[c.activ]) : c.activ;
        for (var j = 0; j < c.pag.children.length; j++) {
            if (j === activ) {
                c.pag.children[j].classList.add('swiper-pagination-bullet-active');
            } else {
                c.pag.children[j].classList.remove('swiper-pagination-bullet-active');
            }
        }
    }

    function sageata(el, dezactivata) {
        if (!el) {
            return;
        }
        if (dezactivata) {
            el.classList.add('swiper-button-disabled');
        } else {
            el.classList.remove('swiper-button-disabled');
        }
        el.setAttribute('tabindex', dezactivata ? '-1' : '0');
        el.setAttribute('aria-disabled', dezactivata ? 'true' : 'false');
    }

    function navigare(c) {
        if (c.loop) {
            return;
        }
        if (c.ascuns) {
            sageata(c.prev, false);
            sageata(c.next, false);
            return;
        }
        sageata(c.prev, c.activ <= 0);
        sageata(c.next, c.activ >= c.puncte.length - 1);
    }

    function anunta(c, text) {
        c.notif.textContent = '';
        c.notif.textContent = text;
    }

    function pune(c, x, durata) {
        c.translate = x;
        c.wr.style.transitionDuration = durata + 'ms';
        c.wr.style.transform = translatie(x);
    }

    function stare(c) {
        clase(c);
        buline(c);
        navigare(c);
    }

    // Slide-ul activ poate fi oricare (cu buclă și un duplicat de la capăt), dar banda nu trece de ultimul punct de oprire
    // (capătul: ultimul slide lipit de marginea dreaptă), ca să nu rămână spațiu gol.
    function mergi(c, i, durata) {
        i = Math.max(0, Math.min(i, (c.loop ? c.grila.length : c.puncte.length) - 1));
        c.activ = i;
        pune(c, -c.puncte[Math.min(i, c.puncte.length - 1)], durata);
        if (durata > 0) {
            c.anim = true;
            var gata = ++c.miscari;
            w.setTimeout(function () {
                if (gata === c.miscari) {
                    c.anim = false;
                }
            }, durata + 30);
        } else {
            c.anim = false;
        }
        stare(c);
    }

    // Cu buclă, înainte de o mișcare: de pe un duplicat pe slide-ul lui adevărat, fără animație (poziția echivalentă).
    function repara(c) {
        if (!c.loop) {
            return;
        }
        var n = c.originale.length;
        var nou = -1;
        if (c.activ < c.dupl) {
            nou = c.activ + n;
        } else if (c.activ >= n + c.dupl) {
            nou = c.activ - n;
        }
        if (nou >= 0) {
            mergi(c, nou, 0);
            c.wr.getBoundingClientRect(); // tranziția următoare pornește de la poziția nouă
        }
    }

    function asaza(c, initial) {
        var p = parametri(c.props);
        if (!initial && p.pePagina === c.pePagina && p.spatiu === c.spatiu && c.sc.clientWidth === c.latime) {
            return; // nimic schimbat (load-ul, un resize doar pe înălțime): nicio scriere în DOM
        }
        if (c.loop && (initial || p.pePagina !== c.pePagina)) {
            var real = initial ? 0 : index(copii(c.wr)[c.activ]);
            duplicate(c, p.pePagina);
            c.activ = c.dupl + Math.max(0, real);
        }
        c.pePagina = p.pePagina;
        c.spatiu = p.spatiu;
        c.latime = c.sc.clientWidth;
        var s = copii(c.wr);
        if (!(c.latime > 0)) {
            c.ascuns = true;
            if (initial) {
                c.wr.style.transitionDuration = '0ms';
            }
            stare(c);
            return;
        }
        var eraAscuns = c.ascuns;
        c.ascuns = false;
        c.marime = (c.latime - (c.pePagina - 1) * c.spatiu) / c.pePagina;
        var grila = [];
        var poz = 0;
        for (var i = 0; i < s.length; i++) {
            s[i].style.width = c.marime + 'px';
            s[i].style.marginRight = c.spatiu + 'px';
            grila.push(poz);
            poz = poz + c.marime + c.spatiu;
        }
        c.grila = grila;
        var max = Math.max(0, poz - c.spatiu - c.latime);
        c.puncte = [];
        for (var j = 0; j < grila.length && grila[j] < max - 0.5; j++) {
            c.puncte.push(grila[j]);
        }
        c.puncte.push(max);
        if (initial && !c.loop) {
            c.activ = 0;
            c.translate = 0;
            c.wr.style.transform = translatie(0);
            stare(c);
            return;
        }
        if (eraAscuns && c.loop) {
            c.activ = c.dupl;
        }
        mergi(c, c.activ, 0);
    }

    function urmatorul(c) {
        if (c.ascuns || (c.loop && c.anim)) {
            return;
        }
        repara(c);
        if (!c.loop && c.activ >= c.puncte.length - 1) {
            return;
        }
        mergi(c, c.activ + 1, c.viteza);
        anunta(c, !c.loop && c.activ >= c.puncte.length - 1 ? 'This is the last slide' : 'Next slide');
    }

    function anteriorul(c) {
        if (c.ascuns || (c.loop && c.anim)) {
            return;
        }
        repara(c);
        if (!c.loop && c.activ <= 0) {
            return;
        }
        mergi(c, c.activ - 1, c.viteza);
        anunta(c, !c.loop && c.activ <= 0 ? 'This is the first slide' : 'Previous slide');
    }

    // ---- tragerea (mouse și deget) ----
    function incepe(c, x, y, tactil) {
        if (c.ascuns) {
            return;
        }
        c.tras = { x0: x, y0: y, t0: Date.now(), tactil: tactil, pornit: false, vertical: false, start: c.translate, x: x };
    }

    function misca(c, x, y, e) {
        var t = c.tras;
        if (!t || t.vertical) {
            return;
        }
        var dx = x - t.x0;
        var dy = y - t.y0;
        if (!t.pornit) {
            if (t.tactil && Math.abs(dy) > Math.abs(dx)) {
                if (Math.abs(dy) >= PRAG / 3) {
                    t.vertical = true;
                }
                return;
            }
            if (Math.abs(dx) < PRAG) {
                return;
            }
            t.pornit = true;
            repara(c);
            t.start = c.translate;
            t.x0 = x;
            dx = 0;
            c.wr.style.transitionDuration = '0ms';
        }
        if (e && e.cancelable) {
            e.preventDefault();
        }
        var nou = t.start + dx;
        if (!c.loop) {
            var max = -c.puncte[c.puncte.length - 1];
            if (nou > 0) {
                nou = Math.pow(nou, 0.85);
            } else if (nou < max) {
                nou = max - Math.pow(max - nou, 0.85);
            }
        }
        t.x = x;
        c.translate = nou;
        c.wr.style.transform = translatie(nou);
    }

    function termina(c) {
        var t = c.tras;
        c.tras = null;
        if (!t || !t.pornit) {
            return;
        }
        c.blocheazaClic = true;
        w.setTimeout(function () {
            c.blocheazaClic = false;
        }, 0);
        var poz = -c.translate;
        var directie = t.x < t.x0 ? 1 : -1;
        var g = c.grila;
        var stop = 0;
        var grup = g.length > 1 ? g[1] - g[0] : 1;
        for (var i = 0; i < g.length; i++) {
            var urm = i + 1 < g.length ? g[i + 1] : g[i] + grup;
            if (poz >= g[i] && poz < urm) {
                stop = i;
                grup = urm - g[i];
            }
        }
        var raport = grup > 0 ? (poz - g[stop]) / grup : 0;
        var tinta;
        if (Date.now() - t.t0 > LUNG_MS) {
            tinta = directie > 0 ? (raport >= 0.5 ? stop + 1 : stop) : (raport > 0.5 ? stop + 1 : stop);
        } else {
            tinta = directie > 0 ? stop + 1 : stop;
        }
        if (poz < 0) {
            tinta = 0;
        }
        mergi(c, tinta, c.viteza);
    }

    function lightbox(a) {
        var lb = d.querySelector('.dg-carousel-lightbox');
        var img = lb ? lb.querySelector('.image-wrapper img') : null;
        var src = a.getAttribute('data-src') || '';
        if (!img || !/^(https:\/\/|\/)[^\s"'<>\\]{1,800}$/.test(src) || !ax.adresaSigura(src)) {
            return;
        }
        img.setAttribute('src', src);
        lb.classList.add('open');
    }

    function laClicLightbox(e) {
        var lb = e.currentTarget;
        var t = e.target;
        if (t && t.nodeName === 'IMG' && lb.contains(t)) {
            return;
        }
        lb.classList.remove('open');
    }

    function porneste(m) {
        var cont = m.querySelector('.dica-container[data-props]');
        var sc = cont ? cont.querySelector('.swiper-container') : null;
        var wr = sc ? sc.querySelector('.swiper-wrapper') : null;
        if (!wr || areClasa(sc, 'swiper-container-initialized')) {
            return;
        }
        var originale = copii(wr);
        if (!originale.length) {
            return;
        }
        var props = citesteProps(cont);
        var c = { modul: m, cont: cont, sc: sc, wr: wr, originale: originale, props: props, loop: props.loop === 'on',
            dots: props.dots === 'on', viteza: Math.floor(numar(props.speed, 300, 0, 5000)), activ: 0, translate: 0, dupl: 0, puncte: [0], grila: [0],
            marime: 0, latime: 0, ascuns: false, anim: false, miscari: 0, tras: null, blocheazaClic: false,
            prev: cont.querySelector('.swiper-button-prev'), next: cont.querySelector('.swiper-button-next'),
            pag: props.dots === 'on' ? cont.querySelector('.swiper-pagination') : null };
        sc.classList.add('swiper-container-initialized');
        sc.classList.add('swiper-container-horizontal');
        if (c.loop) {
            for (var i = 0; i < originale.length; i++) {
                originale[i].setAttribute('data-swiper-slide-index', String(i));
            }
        }
        var etichete = [[c.prev, 'Previous slide'], [c.next, 'Next slide']];
        for (var j = 0; j < etichete.length; j++) {
            if (etichete[j][0]) {
                etichete[j][0].setAttribute('tabindex', '0');
                etichete[j][0].setAttribute('role', 'button');
                etichete[j][0].setAttribute('aria-label', etichete[j][1]);
            }
        }
        if (c.pag) {
            c.pag.classList.add('swiper-pagination-clickable');
            c.pag.classList.add('swiper-pagination-bullets');
        }
        c.notif = d.createElement('span');
        c.notif.setAttribute('class', 'swiper-notification');
        c.notif.setAttribute('aria-live', 'assertive');
        c.notif.setAttribute('aria-atomic', 'true');
        sc.appendChild(c.notif);
        asaza(c, true);
        if (c.prev) {
            c.prev.addEventListener('click', function () { anteriorul(c); });
        }
        if (c.next) {
            c.next.addEventListener('click', function () { urmatorul(c); });
        }
        var taste = function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.currentTarget.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
            }
        };
        if (c.prev) {
            c.prev.addEventListener('keydown', taste);
        }
        if (c.next) {
            c.next.addEventListener('keydown', taste);
        }
        if (c.pag) {
            c.pag.addEventListener('click', function (e) {
                var b = e.target;
                if (!areClasa(b, 'swiper-pagination-bullet') || c.ascuns) {
                    return;
                }
                var k = Array.prototype.indexOf.call(c.pag.children, b);
                if (k >= 0) {
                    mergi(c, c.loop ? k + c.dupl : k, c.viteza);
                }
            });
            c.pag.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && areClasa(e.target, 'swiper-pagination-bullet')) {
                    e.preventDefault();
                    e.target.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
                }
            });
        }
        sc.addEventListener('mousedown', function (e) {
            if (e.button !== 0 || (e.target && /^(INPUT|SELECT|TEXTAREA|OPTION)$/.test(e.target.nodeName))) {
                return;
            }
            e.preventDefault();
            incepe(c, e.clientX, e.clientY, false);
        });
        d.addEventListener('mousemove', function (e) {
            if (c.tras && !c.tras.tactil) {
                misca(c, e.clientX, e.clientY, null);
            }
        });
        d.addEventListener('mouseup', function () {
            if (c.tras && !c.tras.tactil) {
                termina(c);
            }
        });
        sc.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) {
                incepe(c, e.touches[0].clientX, e.touches[0].clientY, true);
            }
        }, { passive: true });
        sc.addEventListener('touchmove', function (e) {
            if (c.tras && e.touches.length === 1) {
                misca(c, e.touches[0].clientX, e.touches[0].clientY, e);
            }
        }, { passive: false });
        sc.addEventListener('touchend', function () {
            termina(c);
        });
        sc.addEventListener('touchcancel', function () {
            termina(c);
        });
        sc.addEventListener('click', function (e) {
            if (c.blocheazaClic) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var a = null;
            for (var n = e.target; n && n !== sc; n = n.parentNode) {
                if (n.nodeName === 'A' && areClasa(n, 'image') && n.getAttribute('data-lightbox') === 'on') {
                    a = n;
                    break;
                }
            }
            if (a) {
                e.preventDefault();
                lightbox(a);
            }
        }, true);
        instante.push(c);
    }

    function reaseaza() {
        for (var i = 0; i < instante.length; i++) {
            if (!instante[i].tras) {
                asaza(instante[i], false);
            }
        }
    }

    function aplica() {
        var module = d.querySelectorAll('.dica_divi_carousel');
        var inainte = instante.length;
        for (var i = 0; i < module.length; i++) {
            porneste(module[i]);
        }
        if (inainte === 0 && instante.length > 0) {
            w.addEventListener('resize', reaseaza);
            var lb = d.querySelector('.dg-carousel-lightbox');
            if (lb) {
                lb.addEventListener('click', laClicLightbox);
            }
        }
    }

    ax.caruselDg = { aplica: aplica, reaseaza: reaseaza };
    ax.modul('carusel-dg', 'dom', aplica);
    ax.modul('carusel-dg-load', 'load', reaseaza);
}(window, document));
