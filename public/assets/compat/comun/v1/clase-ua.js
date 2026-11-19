/**
 * Runtime-ul compat, comun/v1: clasele de browser și de dispozitiv de pe `<body>` (adoptarea unui site WordPress, pasul 8a;
 * DECIZIE §2.6.4, INSTRUCTIUNI-BUILDER-C2 §8; VC-GLOBAL-069). Scris clean-room, din specificația de comportament
 * `clase-ua.md` (pasul 0.C: regula ca fapt, plus clasele observate după scripturi), fără niciun cod de furnizor.
 *
 * CSS-ul capturat are reguli pe aceste clase (de exemplu formularele pe iPhone), deci fără ele unele module arată altfel.
 * Clasele nu se pun pe server: pagina din cache e aceeași pentru orice UA (clasa de sistem — windows, osx, linux — o pune deja
 * serverul, în captură). Modulul `clase-ua` pornește o singură dată (doar cu ax-date valid), la execuția lui din slotul final,
 * deci înainte de DOMContentLoaded, ca pe live (OBS-01), și nu reacționează la resize sau la rotirea ecranului (OBS-03).
 * Adaugă, după clasele serverului, în această ordine:
 *  1. clasele de dispozitiv: `et_mobile_device` pe un UA de telefon sau tabletă, plus `et_mobile_device_not_ipad` când nu e
 *     iPad. Potrivit cu ce s-a observat (OBS-03): Chrome și Firefox desktop, Safari pe Mac: niciuna; iPhone și Android: ambele;
 *     iPad cu iPadOS (UA de Mac): niciuna;
 *  2. o singură clasă de browser:
 *     - `ie` dacă UA-ul conține „trident” (fără deosebire de litere mari);
 *     - altfel, dacă primul cuvânt găsit în UA dintre opera, chrome, safari, firefox, msie, trident (fără deosebire de litere
 *       mari) e „Chrome”: `opera` dacă UA-ul conține „OPR”, `edge` dacă UA-ul conține „Edge”;
 *     - altfel `uiwebview`, dacă `navigator` are proprietatea `standalone` (și moștenită), ea e `false` și UA-ul e al unui
 *       dispozitiv iOS (iPhone, iPad, iPod): așa pe iPhone; iPadOS, cu UA de Mac și standalone `false`, dă `safari` (OBS-03);
 *     - altfel acel prim cuvânt, cu litere mici, cu `firefox` → `gecko` și `msie` → `ie`; niciun cuvânt, nicio clasă;
 *  3. `iphone` dacă UA-ul conține „iPhone”.
 * Cu clasa `safari`, după load-ul ferestrei, `<body>` primește `style="display: block;"` (observat pe Safari pe Mac, pe fiecare
 * pagină și lățime; inventarul mutațiilor).
 *
 * `axCompat.claseUa.calculeaza(ua, navigator)` dă lista fără s-o pună (și pentru teste), `axCompat.claseUa.clase` lista pusă,
 * `axCompat.claseUa.aplica()` o pune din nou, fără efect (classList nu dublează). O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.claseUa) {
        return;
    }

    var DISPOZITIV = /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|webOS/i;
    var IOS = /iPhone|iPad|iPod/;

    function standaloneFals(nav) {
        try {
            return !!nav && ('standalone' in nav) && nav.standalone === false;
        } catch (e) {
            return false;
        }
    }

    function calculeaza(ua, nav) {
        ua = String(ua || '');
        var clase = [];
        if (DISPOZITIV.test(ua)) {
            clase.push('et_mobile_device');
            if (!/iPad/i.test(ua)) {
                clase.push('et_mobile_device_not_ipad');
            }
        }
        var browser = '';
        if (/trident/i.test(ua)) {
            browser = 'ie';
        } else {
            var gasit = ua.match(/(opera|chrome|safari|firefox|msie|trident)/i);
            var primul = gasit ? gasit[1] : '';
            if (primul === 'Chrome' && ua.indexOf('OPR') !== -1) {
                browser = 'opera';
            } else if (primul === 'Chrome' && ua.indexOf('Edge') !== -1) {
                browser = 'edge';
            } else if (standaloneFals(nav) && IOS.test(ua)) {
                browser = 'uiwebview';
            } else if (primul !== '') {
                browser = primul.toLowerCase();
                if (browser === 'firefox') {
                    browser = 'gecko';
                } else if (browser === 'msie') {
                    browser = 'ie';
                }
            }
        }
        if (browser !== '') {
            clase.push(browser);
        }
        if (ua.indexOf('iPhone') !== -1) {
            clase.push('iphone');
        }
        return clase;
    }

    var api = { clase: null, calculeaza: calculeaza, aplica: aplica };

    function aplica() {
        var corp = d.body;
        if (!corp || !corp.classList) {
            return;
        }
        var nav = w.navigator;
        var clase = calculeaza(nav ? nav.userAgent : '', nav);
        for (var i = 0; i < clase.length; i++) {
            corp.classList.add(clase[i]);
        }
        api.clase = clase;
    }

    function corpSafari() {
        if (!api.clase || api.clase.indexOf('safari') < 0) {
            return;
        }
        w.setTimeout(function () {
            if (d.body && d.body.style.display !== 'block') {
                d.body.style.display = 'block';
            }
        }, 0);
    }

    ax.claseUa = api;
    ax.modul('clase-ua', 'imediat', aplica);
    ax.modul('clase-ua-safari', 'load', corpSafari);
}(window, document));
