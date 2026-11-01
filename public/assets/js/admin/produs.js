/*
 * Editorul produsului (faza D, WP-34; views/admin/products.php): numărul modificărilor nesalvate, Ctrl+S, Esc, cuprinsul
 * secțiunilor și reîmprospătarea panoului SEO („Cum apare în Google”, public/assets/js/admin/seo.js). Nu schimbă nimic din ce trimite formularul: același formular
 * (#product-form), aceleași câmpuri, aceeași adresă; scriptul paginii îl completează și îl deschide, ca înainte.
 *
 * Modificările nesalvate: la deschiderea editorului se ține minte ce ar trimite formularul (FormData, câmp cu câmp); un câmp
 * al cărui conținut diferă acum e o modificare. Câmpurile ascunse completate de scriptul paginii (galeria, perioadele,
 * ofertele, produsele similare) se recitesc periodic, cât timp editorul e deschis.
 */
(function () {
  'use strict';

  var modal = document.getElementById('product-modal');
  var formular = document.getElementById('product-form');
  if (!modal || !formular) return;
  var eticheta = document.getElementById('produs-nesalvate');
  var butonSalveaza = document.getElementById('produs-salveaza');
  var inchide = document.getElementById('close-product-modal');
  var initial = null;
  var temporizator = null;

  function stare() {
    var harta = {};
    new FormData(formular).forEach(function (valoare, nume) {
      var text = typeof valoare === 'string' ? valoare : (valoare && valoare.name ? valoare.name : '');
      (harta[nume] = harta[nume] || []).push(text);
    });
    return harta;
  }

  function numaraModificari() {
    if (!initial) return 0;
    var acum = stare();
    var nume = Object.keys(initial).concat(Object.keys(acum).filter(function (n) { return !(n in initial); }));
    return nume.filter(function (n) { return (initial[n] || []).join('\u0001') !== (acum[n] || []).join('\u0001'); }).length;
  }

  function actualizeaza() {
    var n = numaraModificari();
    if (eticheta) {
      eticheta.hidden = n === 0;
      eticheta.textContent = n === 1 ? '1 modificare nesalvată' : n + ' modificări nesalvate';
    }
    modal.classList.toggle('are-modificari', n > 0);
    previzualizareGoogle();
    return n;
  }

  function deschis() { return modal.classList.contains('open'); }

  // Deschiderea editorului (clasa „open”, pusă de scriptul paginii după ce a completat câmpurile).
  new MutationObserver(function () {
    if (deschis()) {
      if (initial === null) {
        setTimeout(function () { initial = stare(); actualizeaza(); }, 0);
        clearInterval(temporizator);
        temporizator = setInterval(actualizeaza, 700);
      }
    } else {
      initial = null;
      clearInterval(temporizator);
      actualizeaza();
    }
  }).observe(modal, { attributes: true, attributeFilter: ['class'] });
  formular.addEventListener('input', actualizeaza);
  formular.addEventListener('change', actualizeaza);

  // Închiderea cu modificări nesalvate cere confirmare (butonul ×, clicul alături, Esc).
  function poateInchide() {
    var n = numaraModificari();
    return n === 0 || window.confirm('Ai ' + (n === 1 ? 'o modificare nesalvată' : n + ' modificări nesalvate') + '. Închizi editorul fără să salvezi?');
  }
  if (inchide) {
    inchide.addEventListener('click', function (e) {
      if (!poateInchide()) { e.stopImmediatePropagation(); e.preventDefault(); }
    }, true);
  }
  modal.addEventListener('click', function (e) {
    if (e.target === modal && !poateInchide()) { e.stopImmediatePropagation(); e.preventDefault(); }
  }, true);
  formular.addEventListener('submit', function () { initial = stare(); });

  document.addEventListener('keydown', function (e) {
    if (!deschis()) return;
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      if (typeof formular.requestSubmit === 'function') formular.requestSubmit(butonSalveaza || undefined); else formular.submit();
      return;
    }
    if (e.key === 'Escape' && !e.defaultPrevented) {
      var alta = document.querySelector('.modal-overlay.open:not(#product-modal)');
      if (alta) return;
      e.preventDefault();
      if (poateInchide() && inchide) {
        initial = null;
        modal.classList.remove('open');
      }
    }
  });

  // Cuprinsul: derulează la secțiune și o arată pe cea vizibilă.
  var linkuri = Array.prototype.slice.call(modal.querySelectorAll('[data-produs-sectiune]'));
  linkuri.forEach(function (link) {
    link.addEventListener('click', function (e) {
      var tinta = document.getElementById(link.getAttribute('data-produs-sectiune'));
      if (!tinta) return;
      e.preventDefault();
      tinta.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var camp = tinta.querySelector('input:not([type="hidden"]), select, textarea, button');
      if (camp) setTimeout(function () { camp.focus({ preventScroll: true }); }, 250);
    });
  });
  if ('IntersectionObserver' in window) {
    var vizibile = {};
    var observator = new IntersectionObserver(function (intrari) {
      intrari.forEach(function (i) { vizibile[i.target.id] = i.isIntersecting; });
      var prima = linkuri.filter(function (l) { return vizibile[l.getAttribute('data-produs-sectiune')]; })[0];
      linkuri.forEach(function (l) { l.classList.toggle('este-activ', l === prima); });
    }, { root: formular, rootMargin: '-10% 0px -60% 0px' });
    linkuri.forEach(function (l) { var s = document.getElementById(l.getAttribute('data-produs-sectiune')); if (s) observator.observe(s); });
  }

  // „Cum apare în Google” (WP-37): panoul SEO comun (seo.js) se reface cât editorul e deschis (câmpurile le completează
  // scriptul paginii, fără evenimente „input”).
  function previzualizareGoogle() {
    if (window.axSeo) window.axSeo.actualizeaza();
  }
})();
