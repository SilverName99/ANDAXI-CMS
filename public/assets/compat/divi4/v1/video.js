/**
 * Runtime-ul compat, divi4/v1: coperta modulelor video Divi (adoptarea unui site WordPress, felia 3; decizia D1: reprodus). Scris
 * clean-room, din specificația `video.md` (cronometrul clicului pe copertă), fără niciun cod de furnizor.
 *
 * Clicul (tapul) pe `.et_pb_video_overlay` al unui `.et_pb_video` (sau pe linkul ei `a.et_pb_video_play[href="#"]`, care nu
 * navighează): `play()` pe `<video>`-ul modulului, imediat; coperta se estompează în 500 ms (opacitatea 1 − swing(p), swing(p) =
 * 0,5 − cos(πp)/2), apoi `display: none`. Video-ul continuă cu controalele lui native; coperta nu mai revine. Fără copertă (de
 * exemplu Parcare) nu e nimic de făcut: controalele native ale elementului `<video>`. Un `play()` refuzat de browser (fără gest,
 * fără sursă) nu aruncă nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.videoDivi) {
        return;
    }

    var DURATA = 500;
    var folosite = [];

    function stramos(el, sel) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.matches(sel)) {
                return n;
            }
        }
        return null;
    }

    function estompeaza(c) {
        var start = null;
        function pas(t) {
            if (start === null) {
                start = t;
            }
            var p = Math.min(1, (t - start) / DURATA);
            c.style.opacity = String(1 - (0.5 - Math.cos(p * Math.PI) / 2));
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else {
                c.style.opacity = '0';
                c.style.display = 'none';
            }
        }
        w.requestAnimationFrame(pas);
    }

    function laClic(e) {
        var c = stramos(e.target, '.et_pb_video_overlay');
        var modul = c ? stramos(c, '.et_pb_video') : null;
        if (!modul || folosite.indexOf(c) >= 0) {
            return;
        }
        e.preventDefault();
        folosite.push(c); // o copertă pornește o singură dată (fără atribute în DOM)
        var v = modul.querySelector('video');
        if (v && typeof v.play === 'function') {
            try {
                var r = v.play();
                if (r && typeof r.catch === 'function') {
                    r.catch(function () {});
                }
            } catch (x) {
                ax.noteaza('video: play() refuzat');
            }
        }
        estompeaza(c);
    }

    var pornit = false;

    function aplica() {
        if (!pornit && d.querySelector('.et_pb_video_overlay')) {
            pornit = true;
            d.addEventListener('click', laClic);
        }
    }

    ax.videoDivi = { aplica: aplica };
    ax.modul('video', 'dom', aplica);
}(window, document));
