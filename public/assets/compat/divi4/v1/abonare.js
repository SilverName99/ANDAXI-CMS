/**
 * Runtime-ul compat, divi4/v1: formularele de abonare Divi (`et_pb_signup`, furnizorul MailPoet) ale unui site mutat de pe WordPress
 * (pista E a mutării unui site WooCommerce; docs/MAGAZIN-WC.md, docs/PLUGINURI.md „Newsletter”). Scris clean-room, din comportamentul
 * observat pe site-ul live (baseline-ul formularelor: NL-010…NL-015, SH-007, SH-008) și din specificația mailpoet §4.5.1, fără niciun
 * cod de furnizor. Serverul e App\Support\AbonareCapturata (`POST /_andaxi/abonare/<n>`).
 *
 * Pentru fiecare modul `.et_pb_newsletter` cu formular, la clicul pe `.et_pb_newsletter_button` sau la Enter într-un câmp
 * `.et_pb_newsletter_field .input` (delegat pe document; trimiterea nativă a formularului e oprită, ca pagina să nu se reîncarce):
 *  1. de la capăt: clasa `et_pb_signup_error` scoasă de pe câmpuri, blocul de eroare (`.et_pb_newsletter_error`) ascuns și golit;
 *  2. validarea lui Divi, ca pe live: `form.reportValidity()` (trece mereu: câmpurile sunt text simplu), apoi prenumele și numele (doar
 *     dacă formularul le are) negoale și adresa, neretezată, după regula Divi (EMAIL); un câmp greșit primește doar `et_pb_signup_error`
 *     (chenarul roșu): niciun mesaj și nicio cerere;
 *  3. trimiterea: `fetch` POST pe originea paginii, cu calea paginii (`cale`), adresa, numele și nonce-ul zilei din `et_pb_custom`
 *     (`et_frontend_nonce`); cât merge, butonul are `et_pb_button_text_loading` și se vede `.et_subscribe_loader`;
 *  4. răspunsul: `{"success": true}` → câmpurile (`.et_pb_newsletter_fields`) ascunse, mesajul de succes capturat
 *     (`.et_pb_newsletter_success`) arătat; `{"error": "…"}` → textul în blocul de eroare, arătat; un răspuns gol sau `false` → textul
 *     Divi `subscription_failed` din `et_pb_custom`; o eroare de rețea, un status care nu e 2xx sau un răspuns care nu e JSON → nimic
 *     (ca jQuery-ul lui Divi, care n-avea ramură de eroare). Tot textul prin `textContent`.
 * Formularele se numără în ordinea documentului (cele cu câmpul `et_pb_signup_provider`): serverul găsește lista în același formular al
 * documentului capturat, nu în cerere. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.abonare) {
        return;
    }

    var EMAIL = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
    var ESEC = 'Please, check the fields below to make sure you entered the correct information.';
    var ADRESA = '/_andaxi/abonare/';
    var pornit = false;
    var inCurs = [];

    function config(cheie) {
        var c = ax.date('et_pb_custom');
        return c && typeof c === 'object' && typeof c[cheie] === 'string' ? c[cheie] : '';
    }

    function modulul(el) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList.contains('et_pb_newsletter')) {
                return n;
            }
        }
        return null;
    }

    /** Formularele de abonare ale paginii, în ordinea documentului (ca serverul). */
    function formularele() {
        var rez = [];
        var toate = d.getElementsByTagName('form');
        for (var i = 0; i < toate.length; i++) {
            if (toate[i].querySelector('input[name="et_pb_signup_provider"]')) {
                rez.push(toate[i]);
            }
        }
        return rez;
    }

    /** Ca jQuery `.show()`: afișarea implicită a elementului (block pentru un div, inline pentru un span). */
    function arata(el) {
        if (el) {
            el.style.display = /^(SPAN|A|STRONG|EM|B|I|LABEL)$/.test(el.nodeName) ? 'inline' : 'block';
        }
    }

    function ascunde(el) {
        if (el) {
            el.style.display = 'none';
        }
    }

    function golesteSiScrie(el, text) {
        if (!el) {
            return;
        }
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        if (text) {
            el.appendChild(d.createTextNode(text));
        }
    }

    function camp(form, nume) {
        return form.querySelector('input[name="' + nume + '"]');
    }

    /** Validarea Divi; true = se poate trimite (altfel doar chenarul roșu pe câmpurile greșite). */
    function valideaza(modul) {
        var form = modul.querySelector('form');
        if (!form) {
            return false;
        }
        var prenume = camp(form, 'et_pb_signup_firstname');
        var nume = camp(form, 'et_pb_signup_lastname');
        var email = camp(form, 'et_pb_signup_email');
        var lista = [prenume, nume, email];
        for (var i = 0; i < lista.length; i++) {
            if (lista[i]) {
                lista[i].classList.remove('et_pb_signup_error');
            }
        }
        var eroare = modul.querySelector('.et_pb_newsletter_error');
        ascunde(eroare);
        golesteSiScrie(eroare, '');
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return false;
        }
        var bun = true;
        if (prenume && prenume.value === '') {
            prenume.classList.add('et_pb_signup_error');
            bun = false;
        }
        if (nume && nume.value === '') {
            nume.classList.add('et_pb_signup_error');
            bun = false;
        }
        if (!email || !EMAIL.test(String(email.value))) {
            if (email) {
                email.classList.add('et_pb_signup_error');
            }
            bun = false;
        }
        return bun;
    }

    function incarca(modul, da) {
        var buton = modul.querySelector('.et_pb_newsletter_button');
        var roata = modul.querySelector('.et_subscribe_loader');
        if (buton) {
            buton.classList[da ? 'add' : 'remove']('et_pb_button_text_loading');
        }
        if (roata) {
            if (da) {
                arata(roata);
            } else {
                ascunde(roata);
            }
        }
    }

    function aplicaRaspuns(modul, t) {
        var eroare = modul.querySelector('.et_pb_newsletter_error');
        if (!t) {
            golesteSiScrie(eroare, config('subscription_failed') || ESEC);
            arata(eroare);
            return;
        }
        if (typeof t.error === 'string' && t.error !== '') {
            golesteSiScrie(eroare, t.error);
            arata(eroare);
            return;
        }
        if (t.success) {
            ascunde(modul.querySelector('.et_pb_newsletter_fields'));
            arata(modul.querySelector('.et_pb_newsletter_success'));
        }
    }

    function trimite(modul) {
        if (inCurs.indexOf(modul) >= 0 || !valideaza(modul)) {
            return;
        }
        var form = modul.querySelector('form');
        var n = formularele().indexOf(form);
        if (n < 0 || !w.fetch || !w.FormData) {
            return;
        }
        var date = new FormData();
        date.append('cale', w.location.pathname + w.location.search);
        date.append('et_frontend_nonce', config('et_frontend_nonce'));
        var nume = ['et_pb_signup_email', 'et_pb_signup_firstname', 'et_pb_signup_lastname'];
        for (var i = 0; i < nume.length; i++) {
            var c = camp(form, nume[i]);
            if (c) {
                date.append(nume[i], String(c.value));
            }
        }
        inCurs.push(modul);
        incarca(modul, true);
        function gata() {
            incarca(modul, false);
            var k = inCurs.indexOf(modul);
            if (k >= 0) {
                inCurs.splice(k, 1);
            }
        }
        w.fetch(ADRESA + n, { method: 'POST', body: date, credentials: 'same-origin', redirect: 'error' })
            .then(function (res) {
                if (!res.ok || !/^application\/json\b/i.test(String(res.headers.get('content-type') || ''))) {
                    throw new Error('raspuns');
                }
                return res.json();
            })
            .then(function (t) {
                gata();
                aplicaRaspuns(modul, t);
            })
            .catch(function () {
                gata();
                ax.noteaza('abonare: trimiterea n-a mers');
            });
    }

    function laClic(e) {
        var t = e.target && e.target.nodeType === 1 ? e.target : null;
        var buton = null;
        for (var n = t; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList.contains('et_pb_newsletter_button')) {
                buton = n;
                break;
            }
        }
        var modul = buton ? modulul(buton) : null;
        if (!modul || !modul.querySelector('form')) {
            return;
        }
        e.preventDefault();
        trimite(modul);
    }

    function laTasta(e) {
        if (e.key !== 'Enter' && e.keyCode !== 13) {
            return;
        }
        var t = e.target;
        if (!t || t.nodeName !== 'INPUT' || !t.classList.contains('input')) {
            return;
        }
        var p = t.parentNode;
        var modul = p && p.classList && p.classList.contains('et_pb_newsletter_field') ? modulul(p) : null;
        if (!modul) {
            return;
        }
        e.preventDefault();
        trimite(modul);
    }

    function laTrimitere(e) {
        var f = e.target;
        if (f && f.nodeName === 'FORM' && f.querySelector('input[name="et_pb_signup_provider"]')) {
            e.preventDefault();
        }
    }

    function aplica() {
        if (!pornit) {
            pornit = true;
            d.addEventListener('click', laClic);
            d.addEventListener('keypress', laTasta);
            d.addEventListener('submit', laTrimitere);
        }
    }

    ax.abonare = { aplica: aplica, valideaza: valideaza, EMAIL: EMAIL };
    ax.modul('abonare', 'dom', aplica);
}(window, document));
