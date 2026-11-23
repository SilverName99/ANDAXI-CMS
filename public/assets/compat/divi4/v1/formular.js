/**
 * Runtime-ul compat, divi4/v1: formularele de contact Divi trimise pe loc (adoptarea unui site WordPress, felia 3, pasul 9b).
 * Scris clean-room, din specificația `formular-divi.md` și din comportamentul observat pe pagina live rulată offline din cache-ul
 * referinței (23.11, /contact/: fiecare caz într-o filă nouă, fără rețea, orice POST abortat), fără niciun cod de furnizor.
 * Serverul e App\Support\FormularCapturat (docs/DEPLOY.md, „Formularele de contact ale site-ului capturat”); fără JavaScript,
 * formularul se trimite nativ și pagina revine cu rezultatul.
 *
 * Pentru fiecare `form.et_pb_contact_form` dintr-un `.et_pb_contact_form_container`, la `submit` (delegat pe document):
 *  1. validarea din browser, ca pe live (nicio cerere dacă pică): un câmp cu `data-required_mark="required"` gol (valoarea exactă:
 *     spațiile trec, ca pe live) și un câmp `data-field_type="email"` completat care nu e o adresă (nume@domeniu.tld) primesc
 *     `et_contact_error` (clasa se scoate de pe celelalte); în `.et-pb-contact-message`, construit nod cu nod:
 *     `<p>{fill_message}</p><ul><li>{eticheta}…</ul>` pentru cele goale, apoi, dacă sunt și erori, `<p class="et_normal_padding">
 *     {contact_error_message}</p>`, apoi `<ul><li>{invalid}</li></ul>` (gol când nu sunt erori, dar sunt câmpuri goale; doar el
 *     când e doar emailul greșit). Textele vin din ax-date → `et_pb_custom` (`fill_message`, `contact_error_message`, `invalid`),
 *     altfel cele observate. Captcha-ul Divi nu apare pe formularele capturate (VC-CONTACT-017): nu se verifică;
 *  2. trimiterea: `fetch` POST cu `new FormData(form)` la `form.action`, doar pe originea paginii (și prin `axCompat.adresaSigura`;
 *     altfel trimiterea nativă), cu antetul `X-Ax-Formular: 1`; butonul `disabled` cât timp merge (un clic dublu = o trimitere);
 *     containerul se estompează la `opacity: 0.2` în 200 ms („swing”), apoi, după răspuns, revine la 1 în 200 ms (rămâne
 *     `style="opacity: 1;"`, ca pe live);
 *  3. răspunsul JSON `{stare, mesaje, campuri, redirect?}`: `trimis` → `.et_pb_contact` (formularul) scos, mesajele ca `<p>` în
 *     `.et-pb-contact-message`; un `redirect` pe originea paginii (http/https) se urmează, altul se ignoră; `eroare` (și cu 403/413/
 *     429) → mesajele ca `<p class="et_pb_contact_error_text">`, câmpurile din `campuri` (id-urile Divi) cu `et_contact_error`,
 *     butonul activ din nou. Tot textul prin `textContent`, niciodată HTML;
 *  4. o eroare de rețea sau un răspuns care nu e JSON: trimiterea nativă a formularului (`HTMLFormElement.submit`), deci pagina cu
 *     rezultatul de la server, ca fără JavaScript. (Pe live, o cerere eșuată golea containerul fără niciun mesaj.)
 * Cu `prefers-reduced-motion: reduce`, fără estompare (doar `opacity: 1` la sfârșit). `axCompat.formular.aplica()` nu dublează ascultătorul; `valideaza(form)`
 * e expus pentru teste. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.formular) {
        return;
    }

    var DURATA = 200;
    var EMAIL = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
    var TEXTE = { fill_message: 'Please, fill in the following fields:', contact_error_message: 'Please, fix the following errors:', invalid: 'Invalid email' };
    var pornit = false;
    var inCurs = [];

    function text(cheie) {
        var c = ax.date('et_pb_custom');
        return c && typeof c === 'object' && typeof c[cheie] === 'string' && c[cheie] !== '' ? c[cheie] : TEXTE[cheie];
    }

    function swing(p) {
        return 0.5 - Math.cos(p * Math.PI) / 2;
    }

    function acum() {
        return w.performance && w.performance.now ? w.performance.now() : Date.now();
    }

    function miscareRedusa() {
        try {
            return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    function container(form) {
        for (var n = form.parentNode; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList.contains('et_pb_contact_form_container')) {
                return n;
            }
        }
        return null;
    }

    /** Câmpurile vizibile ale formularului (din `p.et_pb_contact_field`), fără capcană și fără cele ascunse. */
    function campuri(form) {
        var rez = [];
        var lista = form.querySelectorAll('.et_pb_contact_field input, .et_pb_contact_field textarea, .et_pb_contact_field select');
        for (var i = 0; i < lista.length; i++) {
            var t = String(lista[i].getAttribute('type') || '').toLowerCase();
            if (t !== 'hidden' && t !== 'submit' && t !== 'checkbox' && t !== 'radio' && t !== 'file' && !lista[i].classList.contains('ax-capcana')) {
                rez.push(lista[i]);
            }
        }
        return rez;
    }

    function eticheta(form, camp) {
        var id = camp.getAttribute('id');
        var lab = id ? form.querySelector('label[for="' + (w.CSS && w.CSS.escape ? w.CSS.escape(id) : id) + '"]') : null;
        var t = lab ? lab.textContent : (camp.getAttribute('placeholder') || camp.getAttribute('name') || '');
        return String(t).replace(/^\s+|\s+$/g, '');
    }

    function element(nume, clasa, continut) {
        var e = d.createElement(nume);
        if (clasa) {
            e.className = clasa;
        }
        if (continut !== undefined) {
            e.textContent = continut;
        }
        return e;
    }

    function lista(texte) {
        var ul = d.createElement('ul');
        for (var i = 0; i < texte.length; i++) {
            ul.appendChild(element('li', '', texte[i]));
        }
        return ul;
    }

    function mesajul(c) {
        return c ? c.querySelector('.et-pb-contact-message') : null;
    }

    function golesteMesajul(m) {
        while (m && m.firstChild) {
            m.removeChild(m.firstChild);
        }
    }

    /** Validarea din browser; true = se poate trimite. Pune mesajul și clasele ca pe live. */
    function valideaza(form) {
        var c = container(form);
        var lipsa = [];
        var erori = [];
        var lista0 = campuri(form);
        for (var i = 0; i < lista0.length; i++) {
            var camp = lista0[i];
            camp.classList.remove('et_contact_error');
            var v = String(camp.value);
            if (camp.getAttribute('data-required_mark') === 'required' && v === '') {
                lipsa.push(eticheta(form, camp));
                camp.classList.add('et_contact_error');
            } else if (camp.getAttribute('data-field_type') === 'email' && v !== '' && !EMAIL.test(v)) {
                erori.push(text('invalid'));
                camp.classList.add('et_contact_error');
            }
        }
        var m = mesajul(c);
        golesteMesajul(m);
        if (!lipsa.length && !erori.length) {
            return true;
        }
        if (m) {
            if (lipsa.length) {
                m.appendChild(element('p', '', text('fill_message')));
                m.appendChild(lista(lipsa));
                if (erori.length) {
                    m.appendChild(element('p', 'et_normal_padding', text('contact_error_message')));
                }
            }
            m.appendChild(lista(erori));
        }
        return false;
    }

    /** Opacitatea containerului de la valoarea de acum la `la`, în DURATA ms; apoi `gata`. */
    function estompeaza(c, la, gata) {
        if (!c || miscareRedusa()) {
            if (c && la === 1) {
                c.style.setProperty('opacity', '1');
            }
            gata();
            return;
        }
        var de = parseFloat(w.getComputedStyle(c).opacity);
        de = isFinite(de) ? de : 1;
        var start = acum();
        function pas() {
            var p = Math.max(0, Math.min(1, (acum() - start) / DURATA));
            c.style.setProperty('opacity', String(de + (la - de) * swing(p)));
            if (p < 1) {
                w.requestAnimationFrame(pas);
            } else {
                gata();
            }
        }
        w.requestAnimationFrame(pas);
    }

    function peOrigine(adresa) {
        try {
            var u = new URL(adresa, d.baseURI || w.location.href);
            return /^https?:$/.test(u.protocol) && u.origin === w.location.origin && ax.adresaSigura(u.href);
        } catch (e) {
            return false;
        }
    }

    function nativ(form, c, buton) {
        if (c) {
            c.style.setProperty('opacity', '1');
        }
        if (buton) {
            buton.disabled = false;
        }
        scoate(form);
        HTMLFormElement.prototype.submit.call(form);
    }

    function scoate(form) {
        var k = inCurs.indexOf(form);
        if (k >= 0) {
            inCurs.splice(k, 1);
        }
    }

    function numarFormular(form) {
        var h = form.querySelector('input[type="hidden"][name^="et_pb_contactform_submit_"]');
        var m = h ? /^et_pb_contactform_submit_([0-9]{1,4})$/.exec(h.getAttribute('name')) : null;
        return m ? m[1] : null;
    }

    function textele(r) {
        var rez = [];
        var lista1 = r && Array.isArray(r.mesaje) ? r.mesaje : [];
        for (var i = 0; i < lista1.length && i < 20; i++) {
            if (typeof lista1[i] === 'string' && lista1[i] !== '') {
                rez.push(lista1[i]);
            }
        }
        return rez;
    }

    function aplicaRaspuns(form, c, buton, r) {
        var m = mesajul(c);
        golesteMesajul(m);
        var texte = textele(r);
        if (r.stare === 'trimis') {
            var contact = c ? c.querySelector('.et_pb_contact') : null;
            if (contact && contact.parentNode) {
                contact.parentNode.removeChild(contact);
            }
            for (var i = 0; i < texte.length; i++) {
                if (m) {
                    m.appendChild(element('p', '', texte[i]));
                }
            }
            scoate(form);
            if (typeof r.redirect === 'string' && r.redirect !== '' && peOrigine(r.redirect)) {
                w.location.assign(new URL(r.redirect, d.baseURI || w.location.href).href);
            }
            return;
        }
        for (var j = 0; j < texte.length; j++) {
            if (m) {
                m.appendChild(element('p', 'et_pb_contact_error_text', texte[j]));
            }
        }
        var n = numarFormular(form);
        var ids = Array.isArray(r.campuri) ? r.campuri : [];
        for (var k = 0; n !== null && k < ids.length; k++) {
            if (typeof ids[k] === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(ids[k])) {
                var camp = form.querySelector('[name="et_pb_contact_' + ids[k] + '_' + n + '"]');
                if (camp) {
                    camp.classList.add('et_contact_error');
                }
            }
        }
        if (buton) {
            buton.disabled = false;
        }
        scoate(form);
    }

    function trimite(form) {
        var c = container(form);
        var buton = form.querySelector('button[type="submit"], input[type="submit"]');
        inCurs.push(form);
        if (buton) {
            buton.disabled = true;
        }
        var date = new FormData(form);
        var raspuns = null;
        var jos = false;
        var gata = false;
        function incheie() {
            if (gata || !jos || raspuns === null) {
                return;
            }
            gata = true;
            if (raspuns === 'nativ') {
                nativ(form, c, buton);
                return;
            }
            aplicaRaspuns(form, c, buton, raspuns);
            estompeaza(c, 1, function () {});
        }
        estompeaza(c, 0.2, function () {
            jos = true;
            incheie();
        });
        w.fetch(form.action, { method: 'POST', body: date, credentials: 'same-origin', redirect: 'error', headers: { 'X-Ax-Formular': '1' } })
            .then(function (res) {
                var tip = String(res.headers.get('content-type') || '');
                if (!/^application\/json\b/i.test(tip)) {
                    return 'nativ';
                }
                return res.json().then(function (j) {
                    return j && typeof j === 'object' && (j.stare === 'trimis' || j.stare === 'eroare') ? j : 'nativ';
                });
            })
            .catch(function () {
                return 'nativ';
            })
            .then(function (r) {
                if (r === 'nativ') {
                    ax.noteaza('formular: trimiterea pe loc n-a mers; trimiterea nativă');
                }
                raspuns = r;
                incheie();
            });
    }

    function laTrimitere(e) {
        var form = e.target;
        if (!form || form.nodeName !== 'FORM' || !form.classList.contains('et_pb_contact_form') || !container(form) || e.defaultPrevented) {
            return;
        }
        if (!w.fetch || !w.FormData || !peOrigine(form.action)) {
            return; // trimiterea nativă
        }
        e.preventDefault();
        if (inCurs.indexOf(form) >= 0) {
            return;
        }
        if (valideaza(form)) {
            trimite(form);
        }
    }

    function aplica() {
        if (!pornit) {
            pornit = true;
            d.addEventListener('submit', laTrimitere);
        }
    }

    ax.formular = { aplica: aplica, valideaza: valideaza };
    ax.modul('formular', 'dom', aplica);
}(window, document));
