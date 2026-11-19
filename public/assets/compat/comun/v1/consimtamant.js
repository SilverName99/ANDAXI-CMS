/**
 * Runtime-ul compat, comun/v1: consimțământul (bannerul, cookie-urile, tabul, hărțile blocate) al unui site adoptat de pe
 * WordPress (felia 2, plus preferințele și tabul din felia 3; decizia D1: reprodus). Scris clean-room, din specificația de
 * comportament `consimtamant.md` și din observațiile OBS-24 (ordinea cookie-urilor la fiecare acțiune, butonul hărții), fără
 * niciun cod de furnizor.
 *
 * Configurația vine doar din ax-date → `complianz` (citită strict; fiecare valoare verificată: prefixul, durata, politica,
 * regiunea, tipul, adresele documentelor prin `adresaSigura`). Fără ea, modulul nu face nimic. La momentul 'dom':
 *  - `<body data-cmplz="1">`; clasele `cmplz-<categorie>` pentru fiecare categorie cu `allow` în cookie-uri (doar după o alegere),
 *    apoi `cmplz-functional`, `cmplz-<regiune>`, `cmplz-<tip>`;
 *  - containerul bannerului devine primul copil al lui `<body>` (ca pe live); bannerul: `cmplz-hidden` → `cmplz-show` fără
 *    alegere, `cmplz-dismissed` după una; tabul (`#cmplz-manage-consent`): `cmplz-dismissed` fără alegere, `cmplz-show` după;
 *  - legăturile documentelor din banner: adresa paginii din `page_links.<regiune>` (plus `data-relative_url`), textul `{title}`
 *    înlocuit cu titlul ei; un document fără pagină (impressum) primește `cmplz-hidden`;
 *  - hărțile blocate (`iframe[data-src-cmplz]`, categoria lor din `data-category`): fără acord pe categorie, părintele primește
 *    `cmplz-placeholder-<n> cmplz-blocked-content-container` și imaginea placeholder-ului (două `<style>` la sfârșitul lui head,
 *    doar pentru un placeholder afișat: fundalul și înălțimea = lățimea × raportul imaginii, refăcute la resize), iframe-ul `cmplz-processed`, iar după el butonul
 *    `cmplz-blocked-content-notice` cu textul din `placeholdertext`; cu acord, `src` ← `data-src-cmplz` (doar https).
 * Acțiunile (clic; X și categoriile și cu Enter/Spațiu): Acceptă, Respinge, X, Vizualizați preferințele (categoriile cu
 * `cmplz-fade-in`, butonul de salvare în locul celui de preferințe), Salvați preferințele (după comutatoare), chevronul unei
 * categorii (`aria-expanded`, descrierea), tabul (redeschide bannerul, direct în preferințe dacă au fost deschise în sesiune),
 * butonul unei hărți (acceptă categoria ei). Cookie-urile, în ordinea observată (OBS-24): `<prefix><nume>=<valoare>;SameSite=Lax;
 * secure;expires=<+zile>;path=/`, doar valori dintr-o listă închisă. Nimic trimis la server.
 *
 * Trackerele (VC-TRACKING-001…003) nu pornesc de aici: politica CSP a site-ului (script-src 'self') nu permite scripturi de pe alte
 * gazde; `axCompat.consimtamant.permis(categorie)` le spune altor module ce e acceptat. Managerul din pagina Cookie Policy (HTML
 * din răspunsul înghețat) nu se construiește din text: rămâne textul fără JS al paginii.
 *
 * O a doua includere nu face nimic; `aplica()` nu dublează nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.consimtamant) {
        return;
    }

    var CATEGORII = ['marketing', 'statistics', 'preferences', 'functional'];
    var DOCUMENTE = ['cookie-statement', 'privacy-statement', 'impressum'];
    var NUME = /^[a-z][a-z0-9_-]{0,39}$/;
    var cfg = null;
    var aplicat = false;
    var harti = [];

    function areClasa(el, k) {
        return !!el && el.nodeType === 1 && !!el.classList && el.classList.contains(k);
    }

    function inlocuiesteClasa(el, vechi, noi) {
        if (!el) {
            return;
        }
        for (var i = 0; i < vechi.length; i++) {
            el.classList.remove(vechi[i]);
        }
        if (noi) {
            el.classList.add(noi);
        }
    }

    function text(v, max) {
        return typeof v === 'string' && v.length <= (max || 300) ? v : null;
    }

    function citesteConfig() {
        var c = ax.date('complianz');
        if (!c || typeof c !== 'object' || Object.prototype.toString.call(c) !== '[object Object]') {
            return null;
        }
        var prefix = text(c.prefix, 20);
        var zile = parseInt(c.cookie_expiry, 10);
        var regiune = text(c.region, 5);
        var tip = text(c.consenttype, 10);
        if (!prefix || !/^[a-z][a-z0-9_]{0,19}$/.test(prefix) || !regiune || !/^[a-z]{2,5}$/.test(regiune) || !tip || !/^[a-z]{2,10}$/.test(tip)) {
            ax.noteaza('consimtamant: configurație refuzată');
            return null;
        }
        var linkuri = c.page_links && typeof c.page_links === 'object' && c.page_links[regiune] && typeof c.page_links[regiune] === 'object'
            ? c.page_links[regiune] : {};
        return {
            prefix: prefix,
            zile: zile > 0 && zile <= 3650 ? zile : 365,
            politica: /^[0-9]{1,10}$/.test(String(c.current_policy_id || '')) ? String(c.current_policy_id) : null,
            regiune: regiune,
            tip: tip,
            linkuri: linkuri,
            textHarta: text(c.placeholdertext, 300) || 'Click to accept {category} cookies and enable this content'
        };
    }

    function cookieuri() {
        var rez = {};
        var parti = ax.cookie.citeste().split(';');
        for (var i = 0; i < parti.length; i++) {
            var p = parti[i];
            var j = p.indexOf('=');
            if (j > 0) {
                rez[p.slice(0, j).replace(/^\s+|\s+$/g, '')] = p.slice(j + 1).replace(/^\s+|\s+$/g, '');
            }
        }
        return rez;
    }

    function scrie(nume, valoare) {
        if (!/^[a-z_-]{1,40}$/.test(nume) || !/^(allow|deny|dismissed|[0-9]{0,10})$/.test(valoare)) {
            return;
        }
        var expira = new Date(Date.now() + cfg.zile * 86400000).toUTCString();
        ax.cookie.scrie(cfg.prefix + nume + '=' + valoare + ';SameSite=Lax;secure;expires=' + expira + ';path=/');
    }

    function scriePolitica() {
        if (cfg.politica !== null) {
            scrie('policy_id', cfg.politica);
        }
    }

    function stare() {
        var c = cookieuri();
        var rez = { ales: c[cfg.prefix + 'banner-status'] === 'dismissed', categorii: {} };
        for (var i = 0; i < CATEGORII.length; i++) {
            rez.categorii[CATEGORII[i]] = CATEGORII[i] === 'functional' || c[cfg.prefix + CATEGORII[i]] === 'allow';
        }
        return rez;
    }

    function permis(categorie) {
        if (!cfg) {
            return false;
        }
        return stare().categorii[categorie] === true && (categorie === 'functional' || stare().ales);
    }

    function claseCorp() {
        var b = d.body;
        var s = stare();
        b.setAttribute('data-cmplz', '1');
        for (var i = 0; i < CATEGORII.length; i++) {
            var k = 'cmplz-' + CATEGORII[i];
            if (CATEGORII[i] !== 'functional') {
                if (s.ales && s.categorii[CATEGORII[i]]) {
                    b.classList.add(k);
                } else {
                    b.classList.remove(k);
                }
            }
        }
        b.classList.add('cmplz-functional');
        b.classList.add('cmplz-' + cfg.regiune);
        b.classList.add('cmplz-' + cfg.tip);
    }

    function banner() {
        return d.querySelector('#cmplz-cookiebanner-container .cmplz-cookiebanner');
    }

    function tab() {
        return d.querySelector('#cmplz-manage-consent .cmplz-manage-consent');
    }

    function arataBannerul() {
        inlocuiesteClasa(banner(), ['cmplz-hidden', 'cmplz-dismissed'], 'cmplz-show');
        inlocuiesteClasa(tab(), ['cmplz-hidden', 'cmplz-show'], 'cmplz-dismissed');
    }

    function inchideBannerul() {
        inlocuiesteClasa(banner(), ['cmplz-hidden', 'cmplz-show'], 'cmplz-dismissed');
        inlocuiesteClasa(tab(), ['cmplz-hidden', 'cmplz-dismissed'], 'cmplz-show');
    }

    function legaturi(b) {
        var a = b.querySelectorAll('a.cmplz-link, a.cmplz-btn');
        for (var i = 0; i < a.length; i++) {
            var fel = null;
            for (var j = 0; j < DOCUMENTE.length; j++) {
                if (areClasa(a[i], DOCUMENTE[j])) {
                    fel = DOCUMENTE[j];
                }
            }
            if (!fel) {
                continue;
            }
            var pl = cfg.linkuri[fel];
            var url = pl && typeof pl === 'object' ? text(pl.url, 500) : null;
            var rel = a[i].getAttribute('data-relative_url') || '';
            if (url && ax.adresaSigura(url) && /^(#[A-Za-z0-9_-]{0,80})?$/.test(rel)) {
                a[i].setAttribute('href', url + rel);
                if (a[i].textContent === '{title}' && text(pl.title, 200) !== null) {
                    a[i].textContent = pl.title;
                }
            } else if (a[i].textContent === '{title}') {
                a[i].classList.add('cmplz-hidden');
            }
        }
    }

    function preferinte(b) {
        var cat = b.querySelector('.cmplz-categories');
        if (cat) {
            cat.classList.add('cmplz-fade-in');
        }
        var v = b.querySelector('.cmplz-buttons .cmplz-view-preferences');
        var s = b.querySelector('.cmplz-buttons .cmplz-save-preferences');
        if (v) {
            v.style.display = 'none';
        }
        if (s) {
            s.style.display = 'block';
        }
        ax.stocare('sesiune').scrie('ax_consimtamant_preferinte', '1');
    }

    function comutatoare(b) {
        var s = stare();
        var lista = b.querySelectorAll('input.cmplz-consent-checkbox');
        for (var i = 0; i < lista.length; i++) {
            var c = String(lista[i].getAttribute('data-category') || '').replace(/^cmplz_/, '');
            if (CATEGORII.indexOf(c) >= 0) {
                lista[i].checked = c === 'functional' || (s.ales && s.categorii[c]);
            }
        }
    }

    function scoateStiluri(h) {
        for (var i = 0; i < h.stiluri.length; i++) {
            if (h.stiluri[i].parentNode) {
                h.stiluri[i].parentNode.removeChild(h.stiluri[i]);
            }
        }
        h.stiluri = [];
    }

    // Ca pe live: stilurile doar pentru un placeholder afișat (lățime > 0), fundalul și apoi înălțimea, fără atribute în plus.
    function hartaStil(h, inaltime) {
        scoateStiluri(h);
        var reguli = [];
        if (h.imagine) {
            reguli.push('.cmplz-placeholder-' + h.n + ' {background-image: url(' + h.imagine + ') !important;}');
        }
        if (inaltime > 0 && isFinite(inaltime)) {
            reguli.push('.cmplz-placeholder-' + h.n + ' {height:' + String(inaltime) + 'px;}');
        }
        for (var j = 0; j < reguli.length; j++) {
            var s = d.createElement('style');
            s.setAttribute('type', 'text/css');
            s.textContent = reguli[j];
            d.head.appendChild(s);
            h.stiluri.push(s);
        }
    }

    function raport(h) {
        if (h.img && h.img.naturalWidth > 0) {
            return h.img.naturalHeight / h.img.naturalWidth;
        }
        var m = /([0-9]{2,5})x([0-9]{2,5})\.[a-z]{3,4}$/.exec(h.imagine || '');
        return m ? Number(m[2]) / Number(m[1]) : 0;
    }

    function masoara() {
        for (var i = 0; i < harti.length; i++) {
            var h = harti[i];
            if (h.activata) {
                continue;
            }
            var r = raport(h);
            var latime = h.parinte.getBoundingClientRect().width;
            if (!(latime > 0)) {
                scoateStiluri(h);
                continue;
            }
            hartaStil(h, r > 0 ? latime * r : 0);
        }
    }

    function activeaza(h) {
        if (h.activata) {
            return;
        }
        h.activata = true;
        var src = h.iframe.getAttribute('data-src-cmplz') || '';
        if (/^https:\/\//.test(src) && ax.adresaSigura(src)) {
            h.iframe.setAttribute('src', src);
        }
        h.iframe.classList.add('cmplz-activated');
        h.iframe.classList.add('cmplz-processed');
        h.parinte.classList.remove('cmplz-blocked-content-container');
        if (h.buton && h.buton.parentNode) {
            h.buton.parentNode.removeChild(h.buton);
        }
        scoateStiluri(h);
    }

    function harta(iframe, n) {
        var cat = String(iframe.getAttribute('data-category') || 'marketing');
        var serv = String(iframe.getAttribute('data-service') || '');
        if (!NUME.test(cat) || (serv !== '' && !NUME.test(serv))) {
            return null;
        }
        var imagine = iframe.getAttribute('data-placeholder-image') || '';
        if (!/^[A-Za-z0-9/._~:%-]{1,500}$/.test(imagine) || !ax.adresaSigura(imagine)) {
            imagine = '';
        }
        var h = { iframe: iframe, parinte: iframe.parentNode, categorie: cat, serviciu: serv, n: n, imagine: imagine, img: null, buton: null,
            activata: false, stiluri: [] };
        if (permis(cat)) {
            activeaza(h);
            return h;
        }
        h.parinte.classList.add('cmplz-placeholder-' + n);
        h.parinte.classList.add('cmplz-blocked-content-container');
        h.parinte.setAttribute('data-placeholder_class_index', String(n));
        if (imagine) {
            h.parinte.setAttribute('data-placeholder-image', imagine);
        }
        iframe.classList.add('cmplz-processed');
        var t = cfg.textHarta.split('{category}').join(cat);
        var b = d.createElement('button');
        b.setAttribute('class', 'cmplz-blocked-content-notice cmplz-accept-category cmplz-accept-' + cat);
        if (serv) {
            b.setAttribute('data-service', serv);
        }
        b.setAttribute('data-category', cat);
        b.setAttribute('aria-label', t);
        b.textContent = t;
        if (iframe.nextSibling) {
            h.parinte.insertBefore(b, iframe.nextSibling);
        } else {
            h.parinte.appendChild(b);
        }
        h.buton = b;
        if (imagine) {
            h.img = new w.Image();
            h.img.addEventListener('load', masoara);
            h.img.src = imagine;
        }
        return h;
    }

    function dupaAlegere() {
        claseCorp();
        for (var i = 0; i < harti.length; i++) {
            if (permis(harti[i].categorie)) {
                activeaza(harti[i]);
            }
        }
    }

    function accepta() {
        scrie('consented_services', '');
        var ordine = ['marketing', 'statistics', 'preferences', 'functional'];
        for (var i = 0; i < ordine.length; i++) {
            scriePolitica();
            scrie(ordine[i], 'allow');
        }
        scrie('banner-status', 'dismissed');
        inchideBannerul();
        dupaAlegere();
    }

    function respinge() {
        scrie('banner-status', 'dismissed');
        var ordine = ['marketing', 'statistics', 'preferences'];
        for (var i = 0; i < ordine.length; i++) {
            scriePolitica();
            scrie(ordine[i], 'deny');
        }
        scriePolitica();
        scrie('functional', 'allow');
        scriePolitica();
        scrie('consented_services', '');
        inchideBannerul();
        dupaAlegere();
    }

    function inchide() {
        scrie('banner-status', 'dismissed');
        inchideBannerul();
        dupaAlegere();
    }

    function salveaza(b) {
        var ales = {};
        var lista = b.querySelectorAll('input.cmplz-consent-checkbox');
        for (var i = 0; i < lista.length; i++) {
            ales[String(lista[i].getAttribute('data-category') || '').replace(/^cmplz_/, '')] = !!lista[i].checked;
        }
        var ordine = ['marketing', 'statistics', 'preferences'];
        for (var j = 0; j < ordine.length; j++) {
            scriePolitica();
            scrie(ordine[j], ales[ordine[j]] ? 'allow' : 'deny');
        }
        scriePolitica();
        scrie('functional', 'allow');
        scrie('banner-status', 'dismissed');
        inchideBannerul();
        dupaAlegere();
    }

    function acceptaCategoria(cat) {
        if (CATEGORII.indexOf(cat) < 0) {
            return;
        }
        scriePolitica();
        scrie(cat, 'allow');
        scrie('banner-status', 'dismissed');
        inchideBannerul();
        dupaAlegere();
    }

    function categorie(buton) {
        var deschis = buton.getAttribute('aria-expanded') === 'true';
        buton.setAttribute('aria-expanded', deschis ? 'false' : 'true');
        var id = buton.getAttribute('aria-controls') || '';
        var desc = /^[A-Za-z0-9_-]{1,80}$/.test(id) ? d.getElementById(id) : null;
        if (desc) {
            if (deschis) {
                desc.setAttribute('hidden', '');
            } else {
                desc.removeAttribute('hidden');
            }
        }
    }

    function cauta(el, selector, pana) {
        for (var n = el; n && n !== pana && n.nodeType === 1; n = n.parentNode) {
            if (n.matches(selector)) {
                return n;
            }
        }
        return null;
    }

    function laClicBanner(e) {
        var b = e.currentTarget;
        var t = e.target;
        if (cauta(t, '.cmplz-accept', b)) {
            accepta();
        } else if (cauta(t, '.cmplz-deny', b)) {
            respinge();
        } else if (cauta(t, '.cmplz-close', b)) {
            inchide();
        } else if (cauta(t, '.cmplz-view-preferences', b)) {
            preferinte(b);
        } else if (cauta(t, '.cmplz-save-preferences', b)) {
            salveaza(b);
        } else {
            var c = cauta(t, '.cmplz-category-toggle', b);
            if (c) {
                categorie(c);
            }
        }
    }

    function laTastaBanner(e) {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        if (cauta(e.target, '.cmplz-close', e.currentTarget)) {
            e.preventDefault();
            inchide();
        }
    }

    function laClicTab() {
        var b = banner();
        arataBannerul();
        if (b && ax.stocare('sesiune').citeste('ax_consimtamant_preferinte') === '1') {
            preferinte(b);
        }
    }

    function laClicHarta(e) {
        var t = cauta(e.target, '.cmplz-blocked-content-notice', d.body);
        if (t) {
            acceptaCategoria(String(t.getAttribute('data-category') || ''));
        }
    }

    function aplica() {
        if (aplicat) {
            return;
        }
        cfg = citesteConfig();
        if (!cfg || !d.body) {
            return;
        }
        aplicat = true;
        var s = stare();
        claseCorp();
        var cont = d.getElementById('cmplz-cookiebanner-container');
        if (cont && cont.parentNode === d.body && d.body.firstChild !== cont) {
            d.body.insertBefore(cont, d.body.firstChild);
        }
        var b = banner();
        if (b) {
            legaturi(b);
            comutatoare(b);
            if (s.ales) {
                inchideBannerul();
            } else {
                arataBannerul();
            }
            b.addEventListener('click', laClicBanner);
            b.addEventListener('keydown', laTastaBanner);
        }
        var tb = tab();
        if (tb) {
            tb.addEventListener('click', laClicTab);
        }
        var cadre = d.querySelectorAll('iframe[data-src-cmplz]');
        for (var i = 0; i < cadre.length; i++) {
            if (cadre[i].parentNode && cadre[i].parentNode.nodeType === 1) {
                var h = harta(cadre[i], harti.length + 1);
                if (h) {
                    harti.push(h);
                }
            }
        }
        if (harti.length) {
            masoara();
            w.addEventListener('resize', masoara);
            d.body.addEventListener('click', laClicHarta);
        }
    }

    ax.consimtamant = { aplica: aplica, permis: permis };
    ax.modul('consimtamant', 'dom', aplica);
}(window, document));
