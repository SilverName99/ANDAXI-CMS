/**
 * Runtime-ul compat, comun/v1: pagina văzută pentru pluginul „Statistici site” (App\Support\Statistici; docs/PLUGINURI.md,
 * „Statistici site”) pe paginile capturate. Pe paginile nucleului o trimite scriptul inline al layout-ului
 * (`Statistici::scriptPagina`, din `layout.final_body`); paginile capturate nu au layout-ul nucleului, iar politica lor CSP nu
 * permite niciun script inline, deci același cod stă aici, ca fișier: aceleași evenimente, cu aceleași câmpuri în aceeași
 * ordine, la același punct de primire (`POST /api/statistici`, pe aceeași origine: `connect-src 'self'`), fără cookie-uri și fără
 * nimic ținut în browser.
 *
 * Cadrul capturat îl pune pe slotul final doar cu pluginul pornit, pe un răspuns web reușit (nu pe 404, nu în previzualizarea din
 * admin, nu în P1), împreună cu configurația din ax-date → `ax_statistici` (`Statistici::dateCapturat`): `t` felul paginii, `n`
 * dacă se respectă „Do Not Track” / „Global Privacy Control” (setarea `statistici_respecta_dnt`), `q` numele parametrului
 * căutării pe site (`s` pe căutarea WordPress, altfel `q`). Fără configurație, nimic.
 *
 *  - la pagina văzută (o filă vizibilă; după `prerenderingchange` la o preîncărcare): `{e:"v", p: calea fără parametri, r: Referer,
 *    w: lățimea, u: utm_source/medium/campaign/content/term (cel mult 100 de caractere), c: gclid/gbraid/wbraid (g), fbclid (f),
 *    ttclid (t), q: 1 pe o pagină de căutare, t: felul paginii, i: 0}`, cu `navigator.sendBeacon` (altfel `fetch` cu `keepalive`);
 *  - la ascunderea filei sau la plecare: `{e:"l", p, d: secundele active}`; la întoarcerea din bfcache, din nou pagina văzută;
 *  - nimic de la un browser automatizat (`navigator.webdriver`), cu DNT/GPC (dacă `n`), dintr-un cadru izolat (origine opacă:
 *    previzualizarea din admin).
 * Restul (roboții după User-Agent, administratorul logat, plafoanele, „Acum pe site”) îl face punctul de primire, ca pentru nucleu.
 *
 * O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.statistici) {
        return;
    }
    ax.statistici = { versiune: 1 };

    var CALE = '/api/statistici';

    function porneste() {
        var c = ax.date('ax_statistici');
        if (!c || typeof c !== 'object' || Object.prototype.toString.call(c) !== '[object Object]') {
            return;
        }
        var n = w.navigator;
        try {
            if (c.n === true && (n.doNotTrack == '1' || w.doNotTrack == '1' || n.globalPrivacyControl)) {
                return;
            }
            if (n.webdriver || String(w.origin) === 'null') {
                return;
            }
            var l = w.location;
            var t = typeof c.t === 'string' && /^[a-z]{1,20}$/.test(c.t) ? c.t : 'pagina';
            var cautare = c.q === 's' ? 's' : 'q';
            var v = 0;
            var a = 0;
            var b = function (e) {
                var x = JSON.stringify(e);
                try {
                    if (n.sendBeacon && n.sendBeacon(CALE, new Blob([x], { type: 'text/plain' }))) {
                        return;
                    }
                } catch (_) {
                    // mai jos, fetch
                }
                try {
                    fetch(CALE, { method: 'POST', body: x, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } });
                } catch (_) {
                    // nimic: statisticile nu opresc pagina
                }
            };
            var g = function () {
                if (v || d.visibilityState == 'hidden') {
                    return;
                }
                v = 1;
                a = Date.now();
                var p = new URLSearchParams(l.search);
                var o = {};
                var clic = '';
                ['source', 'medium', 'campaign', 'content', 'term'].forEach(function (k) {
                    var y = p.get('utm_' + k);
                    if (y) {
                        o[k] = y.slice(0, 100);
                    }
                });
                if (p.get('gclid') || p.get('gbraid') || p.get('wbraid')) {
                    clic += 'g';
                }
                if (p.get('fbclid')) {
                    clic += 'f';
                }
                if (p.get('ttclid')) {
                    clic += 't';
                }
                b({ e: 'v', p: l.pathname.slice(0, 300), r: d.referrer.slice(0, 500), w: w.innerWidth || 0, u: o, c: clic, q: p.has(cautare) ? 1 : 0, t: t, i: 0 });
            };
            var h = function () {
                if (!v) {
                    return;
                }
                if (d.visibilityState == 'hidden') {
                    if (a) {
                        var s = Math.round((Date.now() - a) / 1e3);
                        a = 0;
                        if (s > 0) {
                            b({ e: 'l', p: l.pathname.slice(0, 300), d: s });
                        }
                    }
                } else if (!a) {
                    a = Date.now();
                }
            };
            d.addEventListener('visibilitychange', function () {
                if (v) {
                    h();
                } else {
                    g();
                }
            });
            w.addEventListener('pagehide', h);
            w.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    v = 0;
                    g();
                }
            });
            if (d.prerendering) {
                d.addEventListener('prerenderingchange', g, { once: true });
            } else {
                g();
            }
        } catch (_) {
            // nimic: statisticile nu opresc pagina
        }
    }

    ax.modul('statistici', 'imediat', porneste);
}(window, document));
