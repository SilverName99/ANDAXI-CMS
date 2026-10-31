/*
 * Căutarea în tot adminul (faza D, WP-36; views/admin/layout.php → #ax-cautare, App\Support\CautareAdmin): fereastra de la
 * Ctrl+K (pe Mac ⌘K) sau de la clicul pe căutarea din antet. Fără biblioteci; cererile merg doar la acest site.
 *
 *  - Ctrl+K o deschide din orice pagină, dar nu cât scrii într-un câmp (acolo tasta e a câmpului: de exemplu în editorul unei
 *    postări pune un link) și nu peste o fereastră sau un panou deschis. Esc o închide și focusul revine unde era.
 *  - Ce scrii se caută după o scurtă pauză (GET /admin/cauta?q=, Accept: application/json; serverul dă doar sursele pe care
 *    le vede adminul). Săgețile aleg un rezultat, Enter îl deschide; Enter fără rezultate (sau „Vezi toate rezultatele”)
 *    deschide pagina rezultatelor.
 *  - Un rezultat doar deschide o pagină. Pe o comandă, panoul ei se deschide singur; orice acțiune (AWB, confirmare, ERP…)
 *    cere tot butonul ei și confirmarea de până acum.
 */
(function () {
  'use strict';

  var dialog = document.getElementById('ax-cautare');
  var camp = document.getElementById('ax-cautare-camp');
  var lista = document.getElementById('ax-cautare-rezultate');
  var stare = document.getElementById('ax-cautare-stare');
  var link = document.querySelector('[data-ax-cautare]');
  if (!dialog || !camp || !lista || !stare) return;

  var MINIM = 2; // App\Support\CautareAdmin::MINIM
  var textInitial = stare.textContent;
  var deUnde = null;
  var temporizator = null;
  var cerere = null;
  var cautat = null;
  var optiuni = [];
  var ales = -1;

  function seScrie(el) {
    if (window.axCadru && typeof window.axCadru.seScrie === 'function') return window.axCadru.seScrie(el);
    var tag = ((el && el.tagName) || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || !!(el && el.isContentEditable);
  }

  function deschide() {
    if (dialog.open) { camp.focus(); camp.select(); return; }
    deUnde = document.activeElement;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    camp.focus();
    camp.select();
  }
  function inchide() {
    if (!dialog.open) return;
    if (typeof dialog.close === 'function') dialog.close(); else { dialog.removeAttribute('open'); laInchidere(); }
  }
  function laInchidere() {
    if (cerere) cerere.abort();
    window.clearTimeout(temporizator);
    var inapoi = deUnde && document.contains(deUnde) && deUnde !== document.body ? deUnde : link;
    deUnde = null;
    if (inapoi && typeof inapoi.focus === 'function') inapoi.focus();
  }
  dialog.addEventListener('close', laInchidere);
  dialog.addEventListener('click', function (e) { if (e.target === dialog) inchide(); });
  dialog.querySelectorAll('.ax-inchide-dialog').forEach(function (b) { b.addEventListener('click', inchide); });

  // Esc: închide fereastra (și din câmpul de căutare, unde browserul ar goli doar textul).
  dialog.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    e.preventDefault();
    e.stopPropagation();
    inchide();
  });

  function alege(index) {
    optiuni.forEach(function (o, i) { o.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
    ales = index;
    if (index >= 0 && optiuni[index]) {
      camp.setAttribute('aria-activedescendant', optiuni[index].id);
      optiuni[index].scrollIntoView({ block: 'nearest' });
    } else {
      camp.removeAttribute('aria-activedescendant');
    }
  }

  function element(tag, clasa, text) {
    var el = document.createElement(tag);
    if (clasa) el.className = clasa;
    if (text !== undefined) el.textContent = text;
    return el;
  }

  function arata(date, q) {
    lista.textContent = '';
    optiuni = [];
    var grupuri = (date && Array.isArray(date.grupuri)) ? date.grupuri : [];
    var n = 0;
    grupuri.forEach(function (grup, g) {
      var bloc = element('div', 'ax-paleta-grup');
      bloc.setAttribute('role', 'group');
      bloc.setAttribute('data-sursa', grup.sursa);
      var titlu = element('p', 'ax-paleta-grup-titlu', grup.eticheta);
      titlu.id = 'ax-cautare-grup-' + g;
      bloc.setAttribute('aria-labelledby', titlu.id);
      bloc.appendChild(titlu);
      (grup.rezultate || []).forEach(function (r) {
        var a = element('a', 'ax-paleta-rezultat');
        a.href = r.url;
        a.id = 'ax-cautare-optiune-' + n;
        a.setAttribute('role', 'option');
        a.setAttribute('tabindex', '-1');
        a.appendChild(element('span', 'ax-paleta-titlu', r.titlu));
        if (r.detaliu) a.appendChild(element('span', 'ax-paleta-detaliu', r.detaliu));
        a.addEventListener('mousemove', function () { alege(optiuni.indexOf(a)); });
        bloc.appendChild(a);
        optiuni.push(a);
        n++;
      });
      if (grup.incomplet) bloc.appendChild(element('p', 'ax-paleta-nota', 'Căutarea în lista aceasta a durat prea mult; scrie mai exact.'));
      lista.appendChild(bloc);
    });
    camp.setAttribute('aria-expanded', n > 0 ? 'true' : 'false');
    stare.textContent = n > 0 ? (n === 1 ? 'Un rezultat' : n + ' rezultate') + ' pentru „' + q + '”.'
      : 'Nimic găsit pentru „' + q + '”. Încearcă doar o parte dintr-un cuvânt sau alt cuvânt.';
    alege(n > 0 ? 0 : -1);
  }

  function cauta() {
    var q = camp.value.replace(/\s+/g, ' ').trim();
    if (q === cautat) return;
    cautat = q;
    if (cerere) cerere.abort();
    if (q.length < MINIM) {
      lista.textContent = '';
      optiuni = [];
      alege(-1);
      camp.setAttribute('aria-expanded', 'false');
      stare.textContent = textInitial;
      return;
    }
    cerere = typeof AbortController === 'function' ? new AbortController() : null;
    stare.textContent = 'Se caută…';
    fetch('/admin/cauta?q=' + encodeURIComponent(q), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: cerere ? cerere.signal : undefined
    }).then(function (r) {
      return r.json().then(function (date) { return { cod: r.status, date: date }; });
    }).then(function (raspuns) {
      if (q !== cautat) return;
      if (raspuns.cod !== 200) {
        lista.textContent = '';
        optiuni = [];
        alege(-1);
        stare.textContent = (raspuns.date && raspuns.date.eroare) || 'Căutarea nu a mers. Încearcă din nou.';
        return;
      }
      arata(raspuns.date, q);
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      if (q === cautat) stare.textContent = 'Căutarea nu a mers (legătura cu serverul). Încearcă din nou.';
    });
  }

  camp.addEventListener('input', function () {
    window.clearTimeout(temporizator);
    temporizator = window.setTimeout(cauta, 150);
  });

  // Deschide adresa: pe aceeași pagină, doar cu altă ancoră (de exemplu alt produs din lista produselor), pagina se reîncarcă,
  // ca ancora să deschidă editorul.
  function mergi(adresa) {
    var tinta = new URL(adresa, window.location.href);
    if (tinta.origin !== window.location.origin) return;
    if (tinta.pathname === window.location.pathname && tinta.search === window.location.search && tinta.hash) {
      window.location.hash = tinta.hash;
      window.location.reload();
      return;
    }
    window.location.assign(tinta.href);
  }

  camp.addEventListener('keydown', function (e) {
    if (e.isComposing) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!optiuni.length) return;
      e.preventDefault();
      var pas = e.key === 'ArrowDown' ? 1 : -1;
      alege((ales + pas + optiuni.length) % optiuni.length);
      return;
    }
    if (e.key === 'Enter') {
      // Rezultatele sunt cele ale textului din câmp: altfel (încă se caută) Enter duce la pagina rezultatelor.
      var q = camp.value.replace(/\s+/g, ' ').trim();
      if (ales >= 0 && optiuni[ales] && q === cautat) {
        e.preventDefault();
        mergi(optiuni[ales].getAttribute('href'));
      }
    }
  });
  lista.addEventListener('click', function (e) {
    var a = e.target.closest('a.ax-paleta-rezultat');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    mergi(a.getAttribute('href'));
  });

  if (link) {
    link.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
      e.preventDefault();
      deschide();
    });
  }

  // Ctrl+K / ⌘K.
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.isComposing || e.altKey || e.shiftKey || !(e.ctrlKey || e.metaKey)) return;
    if ((e.key || '').toLowerCase() !== 'k') return;
    if (dialog.open) { e.preventDefault(); camp.focus(); camp.select(); return; }
    if (seScrie(e.target)) return;
    var taste = document.getElementById('ax-taste');
    if (taste && taste.open && window.axCadru) window.axCadru.inchideTaste();
    if (window.axCadru && typeof window.axCadru.cevaDeschis === 'function' && window.axCadru.cevaDeschis()) return;
    e.preventDefault();
    deschide();
  });

  window.axCautare = { deschide: deschide, inchide: inchide };
})();
