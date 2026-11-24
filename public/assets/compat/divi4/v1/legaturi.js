/**
 * Runtime-ul compat, divi4/v1: legătura unui modul întreg (opțiunea Divi „Module Link URL”; adoptarea unui site WordPress, felia 2;
 * decizia D1: reprodus). Scris clean-room, din comportamentul paginii live (subsolul: „Politica de confidențialitate”, „Contact”,
 * „Galerie” sunt module text fără `<a>`, cu clasa `et_clickable`, care duc la pagina lor la clic), fără niciun cod de furnizor.
 *
 * Datele: ax-date → `et_link_options_data`, o listă de `{class, url, target}` (adresa verificată la import: relativă sau a
 * site-ului ori http(s); fără `javascript:`). Pentru fiecare intrare cu o clasă de modul (`[A-Za-z0-9_-]`), o adresă text și
 * `target` `_self` sau `_blank`, fiecare element cu clasa primește un ascultător de clic:
 *  - un clic pe o legătură, un buton, un câmp de formular sau titlul unui toggle din modul își face treaba lui (modulul nu
 *    navighează), ca pe live;
 *  - altfel: `_blank` deschide adresa într-o filă nouă (fără acces la pagina de acum: `noopener`), `_self` o încarcă în fila
 *    curentă; un clic pe un modul cu legătură aflat în alt modul cu legătură îl duce doar la adresa celui interior.
 * Modulul nu primește focalizare sau roluri ARIA (ca pe live: CSS-ul capturat îi dă doar `cursor: pointer`). O intrare greșită
 * se sare, cu un rând în jurnalul runtime-ului.
 *
 * `axCompat.legaturi.aplica()` nu dublează ascultătorii. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.legaturi) {
        return;
    }

    var CLASA = /^[A-Za-z0-9_-]{1,200}$/;
    var PROPRII = 'a[href], area[href], button, input, select, textarea, label, summary, .et_pb_toggle_title, .et_pb_contact_captcha';
    var legate = [];

    function propriu(tinta, modul) {
        for (var n = tinta; n && n !== modul && n.nodeType === 1; n = n.parentNode) {
            if (n.matches && n.matches(PROPRII)) {
                return true;
            }
        }
        return false;
    }

    function asculta(el, url, tinta) {
        el.addEventListener('click', function (e) {
            if (e.__axLegatura || e.defaultPrevented || propriu(e.target, el)) {
                return;
            }
            e.__axLegatura = true;
            if (tinta === '_blank') {
                w.open(url, '_blank', 'noopener');
                return;
            }
            w.location.assign(url);
        });
    }

    function aplica() {
        var date = ax.date('et_link_options_data');
        if (!date || Object.prototype.toString.call(date) !== '[object Array]') {
            return;
        }
        for (var i = 0; i < date.length; i++) {
            var o = date[i];
            if (!o || typeof o !== 'object' || typeof o['class'] !== 'string' || !CLASA.test(o['class']) || typeof o.url !== 'string'
                || o.url === '' || /^\s*(?:javascript|data|vbscript):/i.test(o.url) || (o.target !== '_self' && o.target !== '_blank')) {
                ax.noteaza('legaturi: intrare refuzată în „et_link_options_data”');
                continue;
            }
            var lista = d.getElementsByClassName(o['class']);
            for (var k = 0; k < lista.length; k++) {
                if (legate.indexOf(lista[k]) < 0) {
                    legate.push(lista[k]);
                    asculta(lista[k], o.url, o.target);
                }
            }
        }
    }

    ax.legaturi = { aplica: aplica };
    ax.modul('legaturi', 'dom', aplica);
})(window, document);
