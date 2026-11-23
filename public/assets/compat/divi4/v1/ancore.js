/**
 * Runtime-ul compat, divi4/v1: derularea lină a ancorelor din pagină și aterizarea cu #hash (adoptarea unui site WordPress,
 * felia 3; decizia D1: reprodus). Scris clean-room, din specificația de comportament `ancore-aterizare.md` și din urmele luate
 * pe pagina live rulată offline din cache-ul referinței (23.11: clic pe `#duble` la 390 și 1366), fără niciun cod de furnizor.
 *
 *  - Clicul pe o legătură spre `#<id>` din aceeași pagină (`href="#<id>"`, nu „#” singur, sau scrisă cu calea: `/camere/#duble`,
 *    `https://<gazda>/camere/#duble`; rezolvată față de baza documentului, aceeași origine și aceeași cale ca pagina, ca la Divi:
 *    query-ul nu contează) spre un element afișat: derulare animată până la vârful țintei minus antetul fixat
 *    (`axCompat.sticky.decalaj()`: 0 pe desktop, înălțimea rândului de antet sticky pe tabletă și telefon), în 800 ms pe curba
 *    „swing” (0,5 − cos(πp)/2); implicitul browserului e împiedicat, deci adresa nu primește hash-ul. Un clic deja tratat de
 *    alt modul (`defaultPrevented`), cu altă tastă decât cea principală sau cu Ctrl/Meta/Shift/Alt rămâne al browserului; la
 *    fel o țintă ascunsă sau inexistentă. Pe site-ul capturat toate legăturile interne cu hash sunt scrise `#<id>` (meniurile
 *    n-au niciuna); forma cu cale e pentru conținut adăugat în CMS.
 *  - Aterizarea cu `#<id>` în adresă: la `load`, dacă ținta există și e afișată, pagina sare la 0 (browserul sărise deja la
 *    țintă) și `history.scrollRestoration` devine `manual`; după 700 ms alunecă spre țintă (aceeași regulă a decalajului) în 800 ms,
 *    sau în 1600 ms dacă ținta e mai jos de 4000 px; hash-ul rămâne în adresă. Ținta ascunsă sau inexistentă: nimic.
 * Pe site-ul adoptat, Acasă n-are nicio legătură `#bottom-section` (scriptul inline al live-ului care o caută aruncă o eroare la
 * fiecare încărcare); celelalte pagini au ancorele de mai sus.
 * Cu `prefers-reduced-motion: reduce`, derularea e un salt (la aterizare: direct la țintă, refăcut după pauză). O rotiță sau o
 * atingere a utilizatorului oprește animația în curs (și alunecarea care aștepta după aterizare).
 *
 * `axCompat.ancore.aplica()` nu dublează ascultătorul. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.ancore) {
        return;
    }

    var DURATA = 800;
    var DURATA_LUNGA = 1600;
    var PRAG_LUNG = 4000;
    var PAUZA = 700;
    var ID = /^[A-Za-z][A-Za-z0-9_:.-]{0,120}$/;
    var animatie = null;
    var pornit = false;
    var ascultaUtilizatorul = false;

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function acum() {
        return w.performance && w.performance.now ? w.performance.now() : Date.now();
    }

    function y() {
        return w.pageYOffset || d.documentElement.scrollTop || 0;
    }

    function tinta(id) {
        if (!ID.test(id)) {
            return null;
        }
        var el = d.getElementById(id);
        return el && el.getClientRects().length ? el : null;
    }

    function decalaj() {
        return ax.sticky && typeof ax.sticky.decalaj === 'function' ? ax.sticky.decalaj() : 0;
    }

    function pozitie(el) {
        var maxim = Math.max(0, d.documentElement.scrollHeight - w.innerHeight);
        return Math.max(0, Math.min(maxim, el.getBoundingClientRect().top + y() - decalaj()));
    }

    function miscareRedusa() {
        try {
            return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    function opreste() {
        if (animatie) {
            animatie.oprita = true;
            animatie = null;
        }
    }

    /** Rotița sau atingerea utilizatorului câștigă în fața animației. */
    function asculta() {
        if (!ascultaUtilizatorul) {
            ascultaUtilizatorul = true;
            w.addEventListener('wheel', opreste, { passive: true });
            w.addEventListener('touchstart', opreste, { passive: true });
        }
    }

    function deruleaza(la, ms) {
        opreste();
        if (miscareRedusa()) {
            w.scrollTo(0, la);
            return;
        }
        var de = y();
        var a = { oprita: false };
        animatie = a;
        var start = acum();
        function pas() {
            if (a.oprita) {
                return;
            }
            var p = Math.max(0, Math.min(1, (acum() - start) / ms));
            w.scrollTo(0, Math.round(de + (la - de) * swing(p)));
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else if (animatie === a) {
                animatie = null;
            }
        }
        w.requestAnimationFrame(pas);
    }

    /** Id-ul țintei unei legături din aceeași pagină, sau null. */
    function idDin(a) {
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#') {
            return href.length > 1 ? href.slice(1) : null;
        }
        if (href.indexOf('#') < 0) {
            return null;
        }
        try {
            var u = new URL(href, d.baseURI || w.location.href);
            var aici = new URL(w.location.href);
            if (u.origin !== aici.origin || u.pathname.replace(/^\//, '') !== aici.pathname.replace(/^\//, '') || u.hash.length < 2) {
                return null;
            }
            return decodeURIComponent(u.hash.slice(1));
        } catch (e) {
            return null;
        }
    }

    function laClic(e) {
        if (e.defaultPrevented || (e.button !== undefined && e.button !== 0) || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
            return;
        }
        var a = null;
        for (var n = e.target; n && n.nodeType === 1; n = n.parentNode) {
            if (n.nodeName === 'A' && n.hasAttribute('href')) {
                a = n;
                break;
            }
        }
        var id = a ? idDin(a) : null;
        if (id === null) {
            return;
        }
        var el = tinta(id);
        if (!el) {
            return;
        }
        e.preventDefault();
        deruleaza(pozitie(el), DURATA);
    }

    function aterizare() {
        var h = String(w.location.hash || '');
        if (h.length < 2) {
            return;
        }
        var id;
        try {
            id = decodeURIComponent(h.slice(1));
        } catch (e) {
            return;
        }
        var el0 = tinta(id);
        if (!el0) {
            return;
        }
        asculta();
        var redusa = miscareRedusa();
        w.scrollTo(0, redusa ? pozitie(el0) : 0);
        try {
            w.history.scrollRestoration = 'manual';
        } catch (e) {
            ax.noteaza('ancore: scrollRestoration indisponibil');
        }
        opreste();
        var asteapta = { oprita: false };
        animatie = asteapta;
        w.setTimeout(function () {
            if (asteapta.oprita) {
                return; // utilizatorul a derulat între timp
            }
            animatie = null;
            var el = tinta(id);
            if (!el) {
                return;
            }
            var sus = el.getBoundingClientRect().top + y();
            deruleaza(pozitie(el), sus > PRAG_LUNG ? DURATA_LUNGA : DURATA);
        }, PAUZA);
    }

    function aplica() {
        if (!pornit) {
            pornit = true;
            d.addEventListener('click', laClic);
        }
        asculta();
    }

    ax.ancore = { aplica: aplica, durata: DURATA };
    ax.modul('ancore', 'dom', aplica);
    ax.modul('ancore-aterizare', 'load', aterizare);
}(window, document));
