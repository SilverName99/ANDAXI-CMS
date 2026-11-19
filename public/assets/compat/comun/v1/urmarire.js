/**
 * Runtime-ul compat, comun/v1: încărcătorul trackerelor unui site adoptat de pe WordPress (VC-TRACKING-001…003; decizia
 * coordonatorului: trackerele live se păstrează, cu aceleași identificatoare, dar pornesc doar după acord, pe categoria lor,
 * fără reîncărcare). Scris de noi; fără niciun script inline și fără cod de furnizor: pornirea fiecărui fel e cea documentată
 * public (coada `dataLayer`, coada `clarity`), apoi un `<script async src>` spre adresa din configurație.
 *
 * Configurația vine doar din ax-date → `ax_urmarire` (pusă de cadrul capturat la randare, din pachet și din fișierul privat al
 * site-ului; importul nu o poate scrie): o listă de `{fel, categorie, id, src, cookie_retragere, parametri?, seturi?}`, fiecare
 * verificată (felul și categoria dintr-o listă închisă, id-ul simplu, `src` https trecut prin `adresaSigura()`). CSP-ul site-ului
 * permite exact gazdele trackerelor active (Urmarire, în pachet), nimic altceva.
 *
 * Nu pornește nimic singur: `axCompat.urmarire.actualizeaza({statistics, marketing, preferences})` îl cheamă consimtamant.js la
 * încărcare (doar după o alegere salvată) și după fiecare alegere. Atunci:
 *  - fiecare tracker al unei categorii acceptate pornește, o singură dată pe pagină (GTM o dată, chiar dacă live îl are de două ori):
 *    `gtag`: `dataLayer`, `gtag('set', 'linker', {domains: [gazda]})`, `gtag('js', data)`, valorile `seturi`, `gtag('config', id,
 *    parametri)`; `gtm`: `dataLayer.push({'gtm.start', event: 'gtm.js'})`; `clarity`: coada `clarity`; apoi scriptul, înaintea
 *    primului `<script>` al paginii (ca pe live);
 *  - Clarity primește, după fiecare actualizare, `clarity('consentv2', {analytics_Storage, ad_Storage})` (statistics, marketing),
 *    ca puntea Complianz de pe live, și `clarity('consent', false)` când ambele sunt refuzate;
 *  - retragerea unei categorii al cărei tracker a pornit: cookie-urile din `cookie_retragere` (numele exacte și prefixele cu `*`)
 *    se șterg, pe gazdă și pe domeniile ei părinte, apoi pagina se reîncarcă o dată (după reîncărcare nu mai pornește nimic, deci
 *    fără buclă).
 *
 * O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.urmarire) {
        return;
    }

    var FELURI = ['gtag', 'gtm', 'clarity'];
    var CATEGORII = ['statistics', 'marketing', 'preferences'];
    var lista = null;
    var pornite = {};
    var ultim = null;

    function obiect(v) {
        return !!v && typeof v === 'object' && Object.prototype.toString.call(v) === '[object Object]';
    }

    function simplu(o, re) {
        var rez = {};
        if (!obiect(o)) {
            return rez;
        }
        for (var k in o) {
            if (Object.prototype.hasOwnProperty.call(o, k) && re.test(k)
                && (typeof o[k] === 'boolean' || (typeof o[k] === 'string' && /^[A-Za-z0-9 _.:-]{0,100}$/.test(o[k])))) {
                rez[k] = o[k];
            }
        }
        return rez;
    }

    function citeste() {
        if (lista !== null) {
            return lista;
        }
        lista = [];
        var brut = ax.date('ax_urmarire');
        if (!Array.isArray(brut)) {
            return lista;
        }
        var vazute = {};
        for (var i = 0; i < brut.length && i < 10; i++) {
            var t = brut[i];
            if (!obiect(t) || FELURI.indexOf(t.fel) < 0 || CATEGORII.indexOf(t.categorie) < 0 || vazute[t.fel]
                || typeof t.id !== 'string' || !/^[A-Za-z0-9_-]{4,40}$/.test(t.id)
                || typeof t.src !== 'string' || !/^https:\/\/[a-z0-9.-]+\/[A-Za-z0-9._~\/%?=&-]{0,300}$/.test(t.src) || !ax.adresaSigura(t.src)) {
                ax.noteaza('urmarire: un tracker refuzat');
                continue;
            }
            var cookie = [];
            if (Array.isArray(t.cookie_retragere)) {
                for (var j = 0; j < t.cookie_retragere.length && j < 30; j++) {
                    if (typeof t.cookie_retragere[j] === 'string' && /^[A-Za-z0-9_]{1,40}\*?$/.test(t.cookie_retragere[j])) {
                        cookie.push(t.cookie_retragere[j]);
                    }
                }
            }
            vazute[t.fel] = true;
            lista.push({ fel: t.fel, categorie: t.categorie, id: t.id, src: t.src, cookie: cookie,
                parametri: simplu(t.parametri, /^[a-z][a-z0-9_]{0,39}$/), seturi: simplu(t.seturi, /^[A-Za-z][A-Za-z0-9_.]{0,59}$/) });
        }
        return lista;
    }

    function coadaGtag() {
        w.dataLayer = w.dataLayer || [];
        if (typeof w.gtag !== 'function') {
            w.gtag = function () {
                w.dataLayer.push(arguments);
            };
        }
        return w.gtag;
    }

    function coadaClarity() {
        if (typeof w.clarity !== 'function') {
            w.clarity = function () {
                (w.clarity.q = w.clarity.q || []).push(arguments);
            };
        }
        return w.clarity;
    }

    function script(src) {
        var s = d.createElement('script');
        s.async = true;
        s.src = src;
        var primul = d.getElementsByTagName('script')[0];
        if (primul && primul.parentNode) {
            primul.parentNode.insertBefore(s, primul);
        } else {
            (d.head || d.documentElement).appendChild(s);
        }
    }

    function porneste(t) {
        if (t.fel === 'gtag') {
            var g = coadaGtag();
            g('set', 'linker', { domains: [w.location.hostname] });
            g('js', new Date());
            for (var k in t.seturi) {
                if (Object.prototype.hasOwnProperty.call(t.seturi, k)) {
                    g('set', k, t.seturi[k]);
                }
            }
            g('config', t.id, t.parametri);
        } else if (t.fel === 'gtm') {
            w.dataLayer = w.dataLayer || [];
            w.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
        } else if (t.fel === 'clarity') {
            coadaClarity();
        }
        script(t.src);
        pornite[t.fel] = t;
    }

    function domenii() {
        var parti = String(w.location.hostname || '').split('.');
        var rez = [null];
        for (var i = 0; i + 2 <= parti.length; i++) {
            var dom = parti.slice(i).join('.');
            rez.push(dom);
            rez.push('.' + dom);
        }
        return rez;
    }

    function stergeCookie(tipare) {
        if (!tipare.length) {
            return;
        }
        var nume = [];
        var parti = ax.cookie.citeste().split(';');
        for (var i = 0; i < parti.length; i++) {
            var n = parti[i].split('=')[0].replace(/^\s+|\s+$/g, '');
            for (var j = 0; j < tipare.length; j++) {
                var p = tipare[j];
                if (/^[A-Za-z0-9_-]{1,80}$/.test(n) && (p.charAt(p.length - 1) === '*' ? n.indexOf(p.slice(0, -1)) === 0 : n === p)) {
                    nume.push(n);
                    break;
                }
            }
        }
        var dom = domenii();
        for (var a = 0; a < nume.length; a++) {
            for (var b = 0; b < dom.length; b++) {
                ax.cookie.scrie(nume[a] + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/' + (dom[b] ? ';domain=' + dom[b] : ''));
            }
        }
    }

    function actualizeaza(cat) {
        var c = {};
        for (var i = 0; i < CATEGORII.length; i++) {
            c[CATEGORII[i]] = !!(cat && cat[CATEGORII[i]] === true);
        }
        var l = citeste();
        var retrase = [];
        for (var j = 0; j < l.length; j++) {
            if (c[l[j].categorie] && !pornite[l[j].fel]) {
                porneste(l[j]);
            } else if (!c[l[j].categorie] && pornite[l[j].fel]) {
                retrase.push(l[j]);
            }
        }
        if (pornite.clarity) {
            var stareCl = function (b) {
                return b ? 'granted' : 'denied';
            };
            w.clarity('consentv2', { analytics_Storage: stareCl(c.statistics), ad_Storage: stareCl(c.marketing) });
            if (!c.statistics && !c.marketing) {
                w.clarity('consent', false);
            }
        }
        ultim = c;
        if (retrase.length) {
            var tipare = [];
            for (var k = 0; k < retrase.length; k++) {
                tipare = tipare.concat(retrase[k].cookie);
            }
            stergeCookie(tipare);
            w.location.reload();
        }
    }

    ax.urmarire = {
        actualizeaza: actualizeaza,
        pornite: function () {
            var rez = [];
            for (var f in pornite) {
                if (Object.prototype.hasOwnProperty.call(pornite, f)) {
                    rez.push(f);
                }
            }
            return rez;
        },
        ultima: function () {
            return ultim;
        }
    };
}(window, document));
