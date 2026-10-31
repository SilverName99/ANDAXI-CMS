/*
 * Componentele tabel și bara-actiuni-masa (WP-31): bifele rândurilor, „toate”, Shift+clic pentru un șir, numărul
 * rândurilor alese și bara acțiunilor în masă, care apare doar cu ceva bifat. Esc golește alegerea. O acțiune cu
 * „confirmare” întreabă înainte. Fără biblioteci.
 */
(function () {
  'use strict';

  function bare() { return Array.prototype.slice.call(document.querySelectorAll('.adm-masa[data-adm-masa-tabel]')); }
  function bifeRanduri(tabel) { return Array.prototype.slice.call(tabel.querySelectorAll('input.adm-tabel__rand')); }

  function actualizeaza(tabel) {
    var bife = bifeRanduri(tabel);
    var alese = bife.filter(function (b) { return b.checked; });
    var toate = tabel.querySelector('input.adm-tabel__toate');
    if (toate) {
      toate.checked = alese.length > 0 && alese.length === bife.length;
      toate.indeterminate = alese.length > 0 && alese.length < bife.length;
    }
    bife.forEach(function (b) {
      var rand = b.closest('tr');
      if (rand) rand.classList.toggle('este-ales', b.checked);
    });
    bare().forEach(function (bara) {
      if (bara.getAttribute('data-adm-masa-tabel') !== tabel.id) return;
      bara.hidden = alese.length === 0;
      var cate = bara.querySelector('.adm-masa__cate');
      if (cate) cate.textContent = String(alese.length);
    });
  }

  function goleste(tabel) {
    bifeRanduri(tabel).forEach(function (b) { b.checked = false; });
    actualizeaza(tabel);
  }

  document.querySelectorAll('table.adm-tabel--selectie').forEach(function (tabel) {
    var ultima = null;
    tabel.addEventListener('click', function (e) {
      var bifa = e.target.closest('input.adm-tabel__rand');
      if (bifa && e.shiftKey && ultima && ultima !== bifa) {
        var bife = bifeRanduri(tabel);
        var a = bife.indexOf(ultima);
        var b = bife.indexOf(bifa);
        bife.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { x.checked = bifa.checked; });
      }
      if (bifa) ultima = bifa;
    });
    tabel.addEventListener('change', function (e) {
      if (e.target.classList.contains('adm-tabel__toate')) {
        bifeRanduri(tabel).forEach(function (b) { b.checked = e.target.checked; });
      }
      actualizeaza(tabel);
    });
    actualizeaza(tabel);
  });

  bare().forEach(function (bara) {
    var tabel = document.getElementById(bara.getAttribute('data-adm-masa-tabel') || '');
    var gol = bara.querySelector('.adm-masa__goleste');
    if (gol && tabel) {
      gol.addEventListener('click', function () {
        goleste(tabel);
        var toate = tabel.querySelector('input.adm-tabel__toate');
        if (toate) toate.focus();
      });
    }
    bara.addEventListener('click', function (e) {
      var buton = e.target.closest('[data-adm-confirmare]');
      if (buton && !window.confirm(buton.getAttribute('data-adm-confirmare'))) e.preventDefault();
    });
  });

  // Esc golește alegerea tabelului în care e focusul (sau a singurului tabel cu bife din pagină), dacă nu e deschis un
  // panou sau o fereastră (acelea se închid întâi).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    if (document.querySelector('.adm-sertar:not([hidden])') || document.querySelector('dialog[open]')) return;
    var tabele = Array.prototype.slice.call(document.querySelectorAll('table.adm-tabel--selectie'));
    var tinta = tabele.filter(function (t) {
      var bara = bare().filter(function (b) { return b.getAttribute('data-adm-masa-tabel') === t.id; })[0];
      return t.contains(document.activeElement) || (bara && bara.contains(document.activeElement));
    })[0] || (tabele.length === 1 ? tabele[0] : null);
    if (!tinta || !bifeRanduri(tinta).some(function (b) { return b.checked; })) return;
    e.preventDefault();
    goleste(tinta);
  });
})();
