/**
 * Săriturile dintr-un articol de blog, făcute lin (WP-22: B2, din Biovitality 6ea2cb1; se încarcă doar pe un articol
 * care are cuprins, citări sau bibliografie, App\Support\BlogAvansat::fisiere).
 *
 * Trei feluri de linkuri duc dintr-un loc în altul al aceleiași pagini:
 * cuprinsul către titluri, citările „[1]" către bibliografie și săgeata de
 * întoarcere din bibliografie înapoi în text. Fără nimic, browserul teleportează
 * pagina și nu-ți dai seama unde ai ajuns.
 *
 * Aici derulăm lin și aprindem o clipă locul în care s-a ajuns. Nu punem
 * `scroll-behavior: smooth` pe toată pagina, ca să nu schimbăm felul în care se
 * mișcă restul site-ului.
 */
(function () {
    'use strict';

    var CLASA_APRINSA = 'bv-anchor-flash';
    var DURATA_APRINDERE = 1400;

    function respectaPreferintaDeMiscare() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /** Linkurile din articol care duc în altă parte a aceleiași pagini. */
    function esteLinkIntern(element) {
        if (!element || element.tagName !== 'A') {
            return false;
        }
        var href = element.getAttribute('href') || '';
        if (href.charAt(0) !== '#' || href.length < 2) {
            return false;
        }

        return !!element.closest('.blog-toc, .blog-references, .blog-cite')
            || element.classList.contains('blog-cite');
    }

    function aprinde(tinta) {
        tinta.classList.remove(CLASA_APRINSA);
        // Repornirea animației cere o citire între ștergere și adăugare;
        // fără ea browserul nu observă că s-a schimbat ceva.
        void tinta.offsetWidth;
        tinta.classList.add(CLASA_APRINSA);
        window.setTimeout(function () {
            tinta.classList.remove(CLASA_APRINSA);
        }, DURATA_APRINDERE);
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        var link = e.target instanceof Element ? e.target.closest('a[href^="#"]') : null;
        if (!esteLinkIntern(link)) {
            return;
        }

        var id = decodeURIComponent(link.getAttribute('href').slice(1));
        var tinta = document.getElementById(id);
        if (!tinta) {
            return;
        }

        e.preventDefault();
        tinta.scrollIntoView({
            behavior: respectaPreferintaDeMiscare() ? 'auto' : 'smooth',
            block: 'start',
        });
        aprinde(tinta);

        // Adresa se actualizează, ca linkul să poată fi trimis mai departe, dar
        // fără `location.hash`, care ar sări instantaneu peste derularea lină.
        if (window.history && window.history.pushState) {
            window.history.pushState(null, '', '#' + id);
        }
    });

    // Intrarea directă pe o adresă cu ancoră (dintr-un link trimis, din Google):
    // browserul sare acolo singur, dar nu se vede unde. O aprindem și pe asta.
    window.addEventListener('load', function () {
        var id = window.location.hash.slice(1);
        if (id === '') {
            return;
        }
        var tinta = document.getElementById(decodeURIComponent(id));
        if (tinta && tinta.closest('.blog-references, .blog-post-template-v3, .blog-article-default')) {
            window.setTimeout(function () {
                aprinde(tinta);
            }, 120);
        }
    });
})();
