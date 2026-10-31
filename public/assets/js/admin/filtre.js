/*
 * Componenta bara-filtre (WP-31): filtrele stau în adresă (formular GET), deci Înapoi duce la aceeași listă. Cu
 * data-adm-filtre-automat, o listă aleasă sau o dată se aplică imediat, iar căutarea după 500 ms fără tastare (sau cu
 * Enter). Un câmp cu data-adm-tasta="/" primește focusul la apăsarea tastei, în afara unui câmp de scris.
 */
(function () {
  'use strict';
  document.querySelectorAll('form.adm-filtre[data-adm-filtre-automat]').forEach(function (formular) {
    var temporizator = null;
    function trimite() {
      if (typeof formular.requestSubmit === 'function') formular.requestSubmit(); else formular.submit();
    }
    formular.addEventListener('change', function (e) {
      if (e.target.matches('select, input[type="date"]')) trimite();
    });
    formular.addEventListener('input', function (e) {
      if (!e.target.matches('input[type="search"]')) return;
      window.clearTimeout(temporizator);
      temporizator = window.setTimeout(trimite, 500);
    });
  });

  function seScrie(el) {
    var tag = ((el && el.tagName) || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || (el && el.isContentEditable);
  }
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || seScrie(e.target)) return;
    var camp = Array.prototype.slice.call(document.querySelectorAll('[data-adm-tasta]')).filter(function (c) {
      return c.getAttribute('data-adm-tasta') === e.key;
    })[0];
    if (!camp) return;
    e.preventDefault();
    camp.focus();
    if (typeof camp.select === 'function') camp.select();
  });
})();
