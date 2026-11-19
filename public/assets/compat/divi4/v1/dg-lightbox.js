/**
 * Runtime-ul compat, divi4/v1: containerul lightbox-ului dg-carousel (adoptarea unui site WordPress, felia 1, pasul 8a; decizia
 * D1: reprodus, pentru paritatea DOM-ului). Scris clean-room, din specificația de comportament `carusel-dg.md` §7, fără niciun
 * cod de furnizor.
 *
 * Pe live, pluginul caruselelor adaugă la pornire, pe fiecare pagină (și pe cele fără carusel), la sfârșitul lui `<body>`, un
 * container (structura, așa cum o cer CSS-ul capturat și DOM-ul paritații): un `div.dg-carousel-lightbox` cu un antet
 * `div.lightbox-header` (un `button.close-btn` cu semnul de închidere U+2573) și un `div.image-wrapper` cu un `span` care ține un
 * `img` cu `src` gol, fără spații între elemente; aici construit element cu element (aplica()), nu dintr-un text. E ascuns de CSS-ul capturat al pluginului; o imagine cu `src` gol nu face nicio cerere. Modulul
 * `dg-lightbox` îl adaugă la DOM gata (doar cu ax-date valid), cu exact acest marcaj, ca ultim copil al lui `<body>` în acel
 * moment. Deschiderea și închiderea lui (clicul pe o poză a caruselului) vin cu modulul caruselului (felia 2), care îl găsește
 * după clasă. Dacă pagina are deja un `.dg-carousel-lightbox`, nu se mai adaugă altul, deci o a doua rulare nu schimbă nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.dgLightbox) {
        return;
    }

    function element(tag, clasa) {
        var e = d.createElement(tag);
        if (clasa) {
            e.setAttribute('class', clasa);
        }
        return e;
    }

    function aplica() {
        var corp = d.body;
        if (!corp || d.querySelector('.dg-carousel-lightbox')) {
            return;
        }
        var cutie = element('div', 'dg-carousel-lightbox');
        var antet = element('div', 'lightbox-header');
        var buton = element('button', 'close-btn');
        buton.appendChild(d.createTextNode('╳'));
        antet.appendChild(buton);
        var invelis = element('div', 'image-wrapper');
        var span = element('span', '');
        var poza = element('img', '');
        poza.setAttribute('src', '');
        span.appendChild(poza);
        invelis.appendChild(span);
        cutie.appendChild(antet);
        cutie.appendChild(invelis);
        corp.appendChild(cutie);
    }

    ax.dgLightbox = { aplica: aplica };
    ax.modul('dg-lightbox', 'dom', aplica);
}(window, document));
