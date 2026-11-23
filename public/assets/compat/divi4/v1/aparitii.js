/**
 * Runtime-ul compat, divi4/v1: animațiile de apariție ale modulelor Divi (`.et-waypoint`, de exemplu iconițele cu timpii de drum
 * de pe Obiective; adoptarea unui site WordPress, felia 2; decizia D1: reprodus). Scris clean-room, din specificația de
 * comportament `aparitii.md` și din urmele luate pe pagina live rulată offline din cache-ul referinței (23.11: clasa fiecărui
 * element la fiecare pas de 40 px, la 1366, 390 și pe iPhone emulat), fără niciun cod de furnizor.
 *
 * CSS-ul capturat ține elementele `.et-waypoint` (afară de `.et_pb_counters`) la opacitate 0 până primesc `et-animated`, care
 * pornește animația lor (`fadeTop` 1 s). Un element primește `et-animated` o singură dată (derularea înapoi nu o scoate), când
 * vârful lui intră în fereastră (vârful < înălțimea ferestrei; pe live, la 1366, cu ~20 px mai devreme, pe telefon exact așa).
 * Prima verificare e la `load` (ca pe live): elementele ascunse pe lățimea curentă au cutia la 0, deci o primesc atunci (observat:
 * 18 din 36 la încărcare, cele de pe cealaltă lățime), iar cele vizibile când ajung în fereastră; apoi la fiecare `scroll` și
 * resize.
 *
 * `axCompat.aparitii.aplica()` nu dublează ascultătorii. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.aparitii) {
        return;
    }

    var asculta = false;

    function verifica() {
        var lista = d.querySelectorAll('.et-waypoint:not(.et_pb_counters):not(.et-animated)');
        var inaltime = w.innerHeight || d.documentElement.clientHeight || 0;
        for (var k = 0; k < lista.length; k++) {
            if (lista[k].getBoundingClientRect().top < inaltime) {
                lista[k].classList.add('et-animated');
            }
        }
    }

    function aplica() {
        if (!d.querySelector('.et-waypoint')) {
            return;
        }
        verifica();
        if (!asculta) {
            asculta = true;
            w.addEventListener('scroll', verifica, { passive: true });
            w.addEventListener('resize', verifica);
        }
    }

    ax.aparitii = { aplica: aplica };
    ax.modul('aparitii', 'load', aplica);
}(window, document));
