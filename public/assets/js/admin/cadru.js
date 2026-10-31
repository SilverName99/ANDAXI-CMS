/*
 * Cadrul adminului „B — Compact” (faza D, WP-30; views/admin/layout.php): grupurile meniului, meniul ascuns sau arătat,
 * lista scurtăturilor de la „?”. Fără biblioteci; nimic cerut de pe alt server.
 *
 *  - Un grup se deschide și se închide cu butonul lui (aria-expanded); alegerea se ține minte în browser
 *    (admin_meniu_<cheie>), dar grupul paginii deschise rămâne mereu deschis.
 *  - Butonul ☰: pe ecran lat ascunde sau arată meniul (ținut minte: admin_sidebar_hidden, ca înainte); pe telefon
 *    deschide meniul peste pagină, iar un clic alături sau Esc îl închide.
 *  - „?” (Shift+/) în afara unui câmp de scris deschide lista scurtăturilor; Esc o închide.
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
  function seScrie(element) {
    if (!element) return false;
    var tag = (element.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || element.isContentEditable;
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

  // Expus pentru componentele WP-31 (de exemplu panoul lateral închide meniul pe telefon).
  window.axCadru = { inchideBara: inchideBara, deschideTaste: deschideTaste, inchideTaste: inchideTaste };
})();
