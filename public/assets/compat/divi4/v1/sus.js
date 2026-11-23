/**
 * Runtime-ul compat, divi4/v1: butonul „înapoi sus” al temei Divi (`span.et_pb_scroll_top`; adoptarea unui site WordPress, felia 2;
 * decizia D1: reprodus). Scris clean-room, din specificația de comportament `sus.md` și din stările luate pe pagina live rulată
 * offline din cache-ul referinței (23.11: clasele, `style` și urma derulării după clic), fără niciun cod de furnizor.
 *
 * Butonul e în captură; CSS-ul capturat îl ascunde fără clasă de stare și îl animă cu `fadeInRight`/`fadeOutRight` pe
 * `.et-visible`/`.et-hidden`. La încărcare nu se schimbă nimic. La fiecare `scroll`:
 *  - scrollY > 800: clasa `et-hidden` înlocuită cu `et-visible`, iar `style` primește `display: inline;` (rămâne, ca pe live);
 *  - altfel: `et-visible` înlocuită cu `et-hidden` (la prima derulare, `et-hidden` se adaugă și fără `et-visible`).
 * Clicul (tapul) derulează fereastra animat de unde e până la 0, în 800 ms pe curba „swing” (0,5 − cos(πp)/2); adresa nu se
 * schimbă. Butonul e un `span` nefocalizabil, ca pe live (fără tastatură, fără ARIA).
 *
 * `axCompat.sus.aplica()` nu dublează ascultătorii. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.sus) {
        return;
    }

    var PRAG = 800;
    var DURATA = 800;
    var butoane = [];
    var stare = null;
    var animatie = null;

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function acum() {
        return w.performance && w.performance.now ? w.performance.now() : Date.now();
    }

    function laDerulare() {
        var vizibil = (w.pageYOffset || d.documentElement.scrollTop || 0) > PRAG;
        if (vizibil === stare) {
            return;
        }
        stare = vizibil;
        for (var k = 0; k < butoane.length; k++) {
            var b = butoane[k];
            b.classList.remove(vizibil ? 'et-hidden' : 'et-visible');
            b.classList.add(vizibil ? 'et-visible' : 'et-hidden');
            if (vizibil && b.style.display !== 'inline') {
                b.style.display = 'inline';
            }
        }
    }

    function laClic() {
        var de = w.pageYOffset || d.documentElement.scrollTop || 0;
        var a = { oprita: false };
        if (animatie) {
            animatie.oprita = true;
        }
        animatie = a;
        var start = acum();
        function pas() {
            if (a.oprita) {
                return;
            }
            var p = Math.max(0, Math.min(1, (acum() - start) / DURATA));
            w.scrollTo(0, Math.round(de * (1 - swing(p))));
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else if (animatie === a) {
                animatie = null;
            }
        }
        w.requestAnimationFrame(pas);
    }

    function aplica() {
        var lista = d.querySelectorAll('.et_pb_scroll_top');
        var noi = 0;
        for (var k = 0; k < lista.length; k++) {
            if (butoane.indexOf(lista[k]) < 0) {
                butoane.push(lista[k]);
                lista[k].addEventListener('click', laClic);
                noi++;
            }
        }
        if (noi && butoane.length === noi) {
            w.addEventListener('scroll', laDerulare, { passive: true });
        }
    }

    ax.sus = { aplica: aplica, prag: PRAG, durata: DURATA };
    ax.modul('sus', 'dom', aplica);
}(window, document));
