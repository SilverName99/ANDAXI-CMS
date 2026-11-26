/*
 * Bannerul de cookie-uri (views/site/components/banner-cookie.php; docs/PLUGINURI.md, „Bannerul de cookie-uri”).
 *
 * Alegerea se scrie în cookie-ul acordului (`2.<oferite>.<acceptate>`, App\Support\CookieConsent), iar serverul decide la
 * fiecare cerere ce scripturi trimite. Deci: o alegere care schimbă categoriile acceptate față de cele cu care a fost făcută
 * pagina o reîncarcă (acceptarea pornește serviciile, retragerea le scoate); una care nu schimbă nimic doar închide bannerul.
 * La retragere se șterg și cookie-urile pe care le poate șterge pagina (Google Analytics, Ads, Clarity, bv_gclid, nl_cid);
 * cele HttpOnly (ax_sursa) le șterge serverul la cererea următoare.
 *
 * Accesibilitate: butoane adevărate, comutatoare (checkbox cu role=switch) cu etichetă; la redeschidere focusul trece pe
 * banner, Escape îl închide fără schimbări și focusul revine pe butonul din colț. La prima afișare bannerul nu fură focusul
 * (e primul în pagină, deci primul Tab ajunge la el) și Escape nu face nimic: lipsa unei alegeri nu e un acord.
 */
(function () {
    'use strict';

    var dateEl = document.getElementById('ax-cc-date');
    var banner = document.querySelector('[data-ax-cc-banner]');
    var tab = document.querySelector('.ax-cc-tab');
    if (!dateEl || !banner || !tab) {
        return;
    }
    var date;
    try {
        date = JSON.parse(dateEl.textContent || '{}');
    } catch (e) {
        return;
    }
    if (!date || typeof date.nume !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(date.nume)) {
        return;
    }
    var active = Array.isArray(date.active) ? date.active : [];
    var acceptatePagina = Array.isArray(date.acceptate) ? date.acceptate.slice().sort() : [];
    var panou = document.getElementById('ax-cc-setari');
    var butonSetari = banner.querySelector('[data-ax-cc="setari"]');
    var butonSalveaza = banner.querySelector('[data-ax-cc="salveaza"]');
    var butonInchide = banner.querySelector('[data-ax-cc="inchide"]');
    var cutie = banner.querySelector('.ax-cc__cutie');
    var redeschis = false;

    function citesteCookie() {
        var parti = ('; ' + document.cookie).split('; ' + date.nume + '=');
        return parti.length > 1 ? parti[1].split(';')[0] : '';
    }

    function valoare(acceptate) {
        var litere = date.litere || {};
        var oferite = '';
        var da = '';
        Object.keys(litere).forEach(function (c) {
            if (active.indexOf(c) !== -1) {
                oferite += litere[c];
                if (acceptate.indexOf(c) !== -1) {
                    da += litere[c];
                }
            }
        });
        return date.versiune + '.' + oferite + '.' + (da || '-');
    }

    function aceleasi(a, b) {
        return a.slice().sort().join(',') === b.slice().sort().join(',');
    }

    // Cookie-urile serviciilor unei categorii retrase, pe gazda paginii și pe fiecare domeniu părinte (Google Analytics și
    // Ads le pun pe domeniul principal).
    var URME = {
        statistici: [/^_ga(_.*)?$/, /^_gid$/, /^_gat/, /^_clck$/, /^_clsk$/, /^bv_gclid$/, /^nl_cid$/],
        marketing: [/^_gcl_/],
        extern: [/^twk_/, /^__tawkuuid$/, /^TawkConnectionTime$/]
    };

    function stergeUrme(retrase) {
        var nume = document.cookie.split(';').map(function (p) { return p.split('=')[0].trim(); });
        var parti = location.hostname.split('.');
        var domenii = [''];
        for (var i = 0; i < parti.length - 1; i++) {
            domenii.push(';domain=.' + parti.slice(i).join('.'));
        }
        retrase.forEach(function (c) {
            (URME[c] || []).forEach(function (tipar) {
                nume.forEach(function (n) {
                    if (tipar.test(n)) {
                        domenii.forEach(function (d) {
                            document.cookie = n + '=;path=/;max-age=0' + d;
                        });
                    }
                });
            });
        });
    }

    function actualizeazaGoogle(acceptate) {
        if (typeof window.gtag !== 'function') {
            return;
        }
        var stare = function (c) { return acceptate.indexOf(c) !== -1 ? 'granted' : 'denied'; };
        window.gtag('consent', 'update', {
            analytics_storage: stare('statistici'),
            ad_storage: stare('marketing'),
            ad_user_data: stare('marketing'),
            ad_personalization: stare('marketing')
        });
    }

    function alege(acceptate) {
        acceptate = acceptate.filter(function (c) { return active.indexOf(c) !== -1; });
        var securizat = location.protocol === 'https:' ? ';Secure' : '';
        document.cookie = date.nume + '=' + valoare(acceptate) + ';path=/;max-age=' + (date.zile * 86400) + ';SameSite=Lax' + securizat;
        actualizeazaGoogle(acceptate);
        var retrase = acceptatePagina.filter(function (c) { return acceptate.indexOf(c) === -1; });
        if (retrase.length) {
            stergeUrme(retrase);
        }
        if (!aceleasi(acceptate, acceptatePagina)) {
            // Abia acum serverul trimite (sau nu mai trimite) serviciile categoriilor schimbate.
            location.reload();
            return;
        }
        date.valoare = citesteCookie();
        inchide(true);
    }

    function comutatoare() {
        return Array.prototype.slice.call(banner.querySelectorAll('[data-ax-cc-categorie]'));
    }

    function arataSetari(deschis) {
        if (!panou || !butonSetari || !butonSalveaza) {
            return;
        }
        panou.hidden = !deschis;
        butonSetari.hidden = deschis;
        butonSetari.setAttribute('aria-expanded', deschis ? 'true' : 'false');
        butonSalveaza.hidden = !deschis;
        if (deschis) {
            var primul = comutatoare()[0];
            if (primul) {
                primul.focus();
            }
        }
    }

    function deschide(cuSetari) {
        redeschis = true;
        // Comutatoarele arată alegerea de acum (pagina poate fi mai veche decât cookie-ul).
        comutatoare().forEach(function (c) {
            c.checked = acceptatePagina.indexOf(c.getAttribute('data-ax-cc-categorie')) !== -1;
        });
        banner.hidden = false;
        if (butonInchide) {
            butonInchide.hidden = false;
        }
        tab.setAttribute('aria-expanded', 'true');
        tab.hidden = true;
        arataSetari(!!cuSetari);
        if (!cuSetari && cutie) {
            cutie.focus();
        }
    }

    function inchide(dupaAlegere) {
        banner.hidden = true;
        arataSetari(false);
        tab.hidden = false;
        tab.setAttribute('aria-expanded', 'false');
        if (redeschis || dupaAlegere) {
            tab.focus();
        }
        redeschis = false;
    }

    banner.addEventListener('click', function (e) {
        var buton = e.target.closest('[data-ax-cc]');
        if (!buton) {
            return;
        }
        var actiune = buton.getAttribute('data-ax-cc');
        if (actiune === 'toate') {
            alege(active.slice());
        } else if (actiune === 'refuz') {
            alege([]);
        } else if (actiune === 'setari') {
            arataSetari(true);
        } else if (actiune === 'salveaza') {
            alege(comutatoare().filter(function (c) { return c.checked; }).map(function (c) {
                return c.getAttribute('data-ax-cc-categorie');
            }));
        } else if (actiune === 'inchide') {
            inchide(false);
        }
    });

    tab.addEventListener('click', function () {
        deschide(false);
    });

    // Un link din pagină (de exemplu din Politica de cookie-uri) deschide direct setările.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-cookie-setari], a[href="#setari-cookie"]');
        if (!link || banner.contains(link)) {
            return;
        }
        e.preventDefault();
        deschide(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && redeschis && !banner.hidden) {
            inchide(false);
        }
    });

    // O pagină adusă înapoi din memoria browserului (Înapoi) a fost făcută pentru alegerea de atunci: alta acum = reîncărcare.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && citesteCookie() !== (date.valoare || '')) {
            location.reload();
        }
    });
})();
