/**
 * Observatorul paginilor de probă ale porții G-compat (tools/dev/compat-browser.cjs). Stă primul în head-ul fiecărei pagini
 * inventate, înaintea runtime-ului, și nu schimbă nimic în DOM: doar notează, în `window.__axProba`, ce fac piesele runtime-ului
 * și când (mutațiile pe `class`, `rel` și `style` și nodurile adăugate, cu ora `performance.now()`; evenimentele `load`/`error`
 * ale linkurilor; starea preload-urilor la DOMContentLoaded; momentele „interactive”, DOMContentLoaded și load; încălcările CSP;
 * erorile).
 * Pe site-uri nu ajunge niciodată (e doar în tools/dev/).
 */
(function () {
    'use strict';
    var P = window.__axProba = {
        jurnal: [], linkuri: [], csp: [], erori: [], ordine: [], interactiv: -1, dcl: -1, load: -1, laDcl: null, axLaDcl: null, lateLaDcl: null, moduleLaDcl: null, inline: 0
    };

    // Ora față de începutul navigării (aceeași bază ca Resource Timing), ca să se poată compara cu sfârșitul fiecărei cereri.
    function acum() {
        return Math.round(performance.now() * 10) / 10;
    }

    function eticheta(n) {
        if (!n || n.nodeType !== 1) {
            return '#' + (n ? n.nodeType : '?');
        }
        return n.nodeName.toLowerCase() + (n.id ? '#' + n.id : '');
    }

    new MutationObserver(function (lista) {
        var t = acum();
        lista.forEach(function (m) {
            if (m.type === 'attributes') {
                P.jurnal.push({ t: t, fel: 'atribut', tinta: eticheta(m.target), atribut: m.attributeName, vechi: m.oldValue,
                    nou: m.target.getAttribute(m.attributeName) });
            } else {
                for (var i = 0; i < m.addedNodes.length; i++) {
                    if (m.addedNodes[i].nodeType === 1) {
                        P.jurnal.push({ t: t, fel: 'nod', tinta: eticheta(m.addedNodes[i]), parinte: eticheta(m.target) });
                    }
                }
            }
        });
    }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeOldValue: true,
        attributeFilter: ['class', 'rel', 'style'] });

    function evLink(fel) {
        return function (e) {
            var l = e.target;
            if (l && l.nodeName === 'LINK') {
                P.linkuri.push({ t: acum(), fel: fel, id: l.id, rel: l.getAttribute('rel'), axGata: !!window.axCompat });
            }
        };
    }
    document.addEventListener('load', evLink('load'), true);
    document.addEventListener('error', evLink('error'), true);

    document.addEventListener('securitypolicyviolation', function (e) {
        P.csp.push({ directiva: e.violatedDirective, blocat: e.blockedURI, sursa: e.sourceFile, linia: e.lineNumber });
    }, true);
    window.addEventListener('error', function (e) {
        P.erori.push(String(e.message || e.error || 'eroare'));
    });
    window.addEventListener('unhandledrejection', function (e) {
        P.erori.push('promisiune: ' + String(e.reason));
    });

    document.addEventListener('readystatechange', function () {
        if (document.readyState === 'interactive' && P.interactiv < 0) {
            P.interactiv = acum();
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
        P.dcl = acum();
        P.laDcl = Array.prototype.map.call(document.querySelectorAll('link[data-ax-tarziu]'), function (l) {
            return l.id + '=' + l.getAttribute('rel');
        });
        P.axLaDcl = !!window.axCompat;
        P.lateLaDcl = !!document.getElementById('et-dynamic-late-css');
    });
    // Pe window, deci după toate handlerele de pe document (și după cel al lui incarcare.js): modulele 'dom' n-au pornit încă.
    window.addEventListener('DOMContentLoaded', function () {
        P.moduleLaDcl = !!document.getElementById('fit-vids-style') || !!document.querySelector('.dg-carousel-lightbox, .et_pb_column_single');
    });
    window.addEventListener('load', function () {
        P.load = acum();
    });
}());
