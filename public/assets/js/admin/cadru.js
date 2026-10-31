/*
 * Cadrul adminului „B — Compact” (faza D, WP-30; views/admin/layout.php): grupurile meniului, meniul ascuns sau arătat,
 * lista scurtăturilor de la „?”. Fără biblioteci; nimic cerut de pe alt server.
 *
 *  - Un grup se deschide și se închide cu butonul lui (aria-expanded); alegerea se ține minte în browser
 *    (admin_meniu_<cheie>), dar grupul paginii deschise rămâne mereu deschis.
 *  - Butonul ☰: pe ecran lat ascunde sau arată meniul (ținut minte: admin_sidebar_hidden, ca înainte); pe telefon
 *    deschide meniul peste pagină, iar un clic alături sau Esc îl închide.
 *  - „?” (Shift+/) în afara unui câmp de scris deschide lista scurtăturilor; Esc o închide.
 *  - WP-36: „G”, apoi o literă (în afara unui câmp de scris, fără fereastră sau panou deschis) duce la prima pagină a unui
 *    grup din meniu: literele și adresele vin din lista de la „?” (data-ax-salt, data-ax-salt-adresa; doar grupurile pe care
 *    le vede adminul). Tasta de după „G” e mereu a navigării, deci nu ajunge niciodată la scurtăturile paginii (A, C, P din
 *    lista comenzilor): „G C” deschide Comenzi, nu confirmă nimic. Navigarea doar deschide o pagină.
 */
(function () {
  'use strict';

  var memorie = {
    citeste: function (cheie) { try { return window.localStorage.getItem(cheie); } catch (e) { return null; } },
    scrie: function (cheie, valoare) { try { window.localStorage.setItem(cheie, valoare); } catch (e) { /* fără memorie */ } }
  };
  var ingust = window.matchMedia('(max-width: 900px)');
  var app = document.getElementById('admin-shell');
  var bara = document.getElementById('ax-bara');
  var fundal = document.querySelector('.ax-fundal-bara');
  var butonBara = document.getElementById('sidebar-toggle-btn');
  if (!app || !bara) return;

  // Grupurile meniului.
  function comutaGrup(grup, deschis, tine) {
    var buton = grup.querySelector('.ax-meniu-buton');
    var lista = grup.querySelector('.ax-meniu-sublista');
    if (!buton || !lista) return;
    grup.classList.toggle('deschis', deschis);
    buton.setAttribute('aria-expanded', deschis ? 'true' : 'false');
    lista.hidden = !deschis;
    if (tine) memorie.scrie('admin_meniu_' + lista.id.replace('ax-grup-', ''), deschis ? 'deschis' : 'inchis');
  }
  bara.querySelectorAll('.ax-meniu-grup').forEach(function (grup) {
    var lista = grup.querySelector('.ax-meniu-sublista');
    if (!lista) return;
    if (!grup.classList.contains('are-activa')) {
      var tinut = memorie.citeste('admin_meniu_' + lista.id.replace('ax-grup-', ''));
      if (tinut === 'deschis') comutaGrup(grup, true, false);
      if (tinut === 'inchis') comutaGrup(grup, false, false);
    }
    grup.querySelector('.ax-meniu-buton').addEventListener('click', function () {
      comutaGrup(grup, !grup.classList.contains('deschis'), true);
    });
  });

  // Meniul ascuns / arătat.
  function seteazaBara() {
    if (ingust.matches) {
      var deschisa = app.classList.contains('bara-deschisa');
      butonBara.setAttribute('aria-expanded', deschisa ? 'true' : 'false');
      if (fundal) fundal.hidden = !deschisa;
    } else {
      butonBara.setAttribute('aria-expanded', app.classList.contains('sidebar-hidden') ? 'false' : 'true');
      if (fundal) fundal.hidden = true;
    }
  }
  if (memorie.citeste('admin_sidebar_hidden') === '1') app.classList.add('sidebar-hidden');
  if (butonBara) {
    butonBara.addEventListener('click', function () {
      if (ingust.matches) {
        app.classList.toggle('bara-deschisa');
        if (app.classList.contains('bara-deschisa')) {
          var primul = bara.querySelector('a, button');
          if (primul) primul.focus();
        }
      } else {
        app.classList.toggle('sidebar-hidden');
        memorie.scrie('admin_sidebar_hidden', app.classList.contains('sidebar-hidden') ? '1' : '0');
      }
      seteazaBara();
    });
  }
  function inchideBara() {
    if (!app.classList.contains('bara-deschisa')) return false;
    app.classList.remove('bara-deschisa');
    seteazaBara();
    if (butonBara) butonBara.focus();
    return true;
  }
  if (fundal) fundal.addEventListener('click', inchideBara);
  if (ingust.addEventListener) ingust.addEventListener('change', seteazaBara);
  seteazaBara();

  // Lista scurtăturilor.
  var dialog = document.getElementById('ax-taste');
  var butonTaste = document.getElementById('ax-buton-taste');
  function deschideTaste() {
    if (!dialog || dialog.open) return;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }
  function inchideTaste() {
    if (!dialog || !dialog.open) return;
    if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    if (butonTaste) butonTaste.focus();
  }
  if (butonTaste) butonTaste.addEventListener('click', deschideTaste);
  if (dialog) {
    dialog.querySelectorAll('.ax-inchide-dialog').forEach(function (b) { b.addEventListener('click', inchideTaste); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) inchideTaste(); });
  }
  // Un câmp în care se scrie (WP-36: căsuțele, butoanele radio și celelalte intrări fără text nu sunt câmpuri de scris).
  var faraText = ['checkbox', 'radio', 'button', 'submit', 'reset', 'image', 'file', 'range', 'color'];
  function seScrie(element) {
    if (!element) return false;
    var tag = (element.tagName || '').toLowerCase();
    if (tag === 'input') return faraText.indexOf((element.getAttribute('type') || 'text').toLowerCase()) === -1;
    return tag === 'textarea' || tag === 'select' || !!element.isContentEditable;
  }
  // E deschisă o fereastră (alta decât lista de la „?”), un panou lateral, o fereastră veche a unei pagini (editorul produsului) sau meniul pe telefon?
  function cevaDeschis() {
    return !!(document.querySelector('dialog[open]:not(#ax-taste)') || document.querySelector('.adm-sertar:not([hidden])')
      || document.querySelector('.modal-overlay.open') || app.classList.contains('bara-deschisa'));
  }
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented) return;
    if (e.key === 'Escape') {
      if (dialog && dialog.open) { inchideTaste(); e.preventDefault(); return; }
      if (inchideBara()) e.preventDefault();
      return;
    }
    if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey && !seScrie(e.target)) {
      e.preventDefault();
      deschideTaste();
    }
  });

  // „G”, apoi litera grupului (WP-36). Ascultat la captură, pe fereastră: tasta de după „G” se oprește aici, înaintea
  // scurtăturilor paginii.
  var salturi = {};
  document.querySelectorAll('[data-ax-salt][data-ax-salt-adresa]').forEach(function (rand) {
    salturi[rand.getAttribute('data-ax-salt')] = rand.getAttribute('data-ax-salt-adresa');
  });
  var asteaptaLitera = false;
  var temporizatorG = null;
  var notaSalt = null;
  function opresteSalt() {
    asteaptaLitera = false;
    window.clearTimeout(temporizatorG);
    if (notaSalt) notaSalt.hidden = true;
  }
  function pornesteSalt() {
    asteaptaLitera = true;
    if (!notaSalt) {
      notaSalt = document.createElement('div');
      notaSalt.className = 'ax-nota-salt';
      notaSalt.setAttribute('role', 'status');
      document.body.appendChild(notaSalt);
    }
    notaSalt.innerHTML = '<kbd>G</kbd> … apasă litera paginii (lista: <kbd>?</kbd>)';
    notaSalt.hidden = false;
    window.clearTimeout(temporizatorG);
    temporizatorG = window.setTimeout(opresteSalt, 1500);
  }
  window.addEventListener('keydown', function (e) {
    if (e.isComposing) return;
    if (asteaptaLitera) {
      if (['Shift', 'Control', 'Alt', 'Meta', 'CapsLock'].indexOf(e.key) !== -1) return;
      opresteSalt();
      // Tab, Esc sau un câmp ales între timp: „G” se uită, tasta merge mai departe.
      if (e.key === 'Tab' || e.key === 'Escape' || seScrie(e.target)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      var adresa = salturi[(e.key || '').toLowerCase()];
      if (adresa) window.location.assign(adresa);
      return;
    }
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.repeat) return;
    if ((e.key === 'g' || e.key === 'G') && Object.keys(salturi).length && !seScrie(e.target) && !cevaDeschis()) {
      e.preventDefault();
      e.stopImmediatePropagation();
      pornesteSalt();
    }
  }, true);
  document.addEventListener('mousedown', function () { if (asteaptaLitera) opresteSalt(); });

  // Expus pentru componentele WP-31 (de exemplu panoul lateral închide meniul pe telefon) și pentru căutare (WP-36).
  window.axCadru = { inchideBara: inchideBara, deschideTaste: deschideTaste, inchideTaste: inchideTaste, seScrie: seScrie, cevaDeschis: cevaDeschis };
})();
