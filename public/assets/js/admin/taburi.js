/*
 * Tab-urile unei pagini de setări (WP-35; componenta tab-uri-cu-numar cu „panouri”): toate părțile paginii sunt deja în HTML,
 * fiecare cu formularele ei neschimbate; un tab arată partea lui și le ascunde pe celelalte, fără reîncărcare. Adresa ține
 * tab-ul ales (?tab=…), deci un link salvat sau Înapoi duc la același tab, iar fără JavaScript linkul tab-ului încarcă pagina
 * cu tab-ul ales (serverul pune `hidden` pe celelalte).
 *
 * După o salvare, serverul trimite înapoi la adresa paginii (aceeași ca înainte de refacere, deci uneori fără ?tab=): tab-ul
 * din care s-a trimis formularul se ține un minut în sessionStorage și se redeschide la întoarcere.
 */
(function () {
  'use strict';
  const CHEIE = 'adm-tab:' + location.pathname;

  function activeaza(nav, cheie, actualizeazaAdresa) {
    const panouri = document.querySelectorAll('[data-adm-tab-panou]');
    let gasit = false;
    panouri.forEach(function (p) { if (p.getAttribute('data-adm-tab-panou') === cheie) gasit = true; });
    if (!gasit) return false;
    panouri.forEach(function (p) { p.hidden = p.getAttribute('data-adm-tab-panou') !== cheie; });
    nav.querySelectorAll('a[data-adm-tab]').forEach(function (a) {
      const activ = a.getAttribute('data-adm-tab') === cheie;
      a.classList.toggle('este-activ', activ);
      if (activ) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (actualizeazaAdresa && window.history && history.replaceState) {
      const adresa = new URL(location.href);
      adresa.searchParams.set('tab', cheie);
      adresa.hash = '';
      history.replaceState(history.state, '', adresa.pathname + adresa.search);
    }
    return true;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const nav = document.querySelector('[data-adm-taburi-panouri]');
    if (!nav) return;
    nav.addEventListener('click', function (e) {
      const a = e.target.closest('a[data-adm-tab]');
      if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
      if (activeaza(nav, a.getAttribute('data-adm-tab'), true)) e.preventDefault();
    });
    document.addEventListener('submit', function (e) {
      const sursa = e.submitter && e.submitter.closest ? e.submitter : e.target;
      const panou = sursa.closest ? sursa.closest('[data-adm-tab-panou]') : null;
      if (!panou) return;
      try { sessionStorage.setItem(CHEIE, panou.getAttribute('data-adm-tab-panou') + '|' + Date.now()); } catch (er) { /* fără stocare */ }
    }, true);
    const parametri = new URLSearchParams(location.search);
    let tinut = '';
    try {
      tinut = sessionStorage.getItem(CHEIE) || '';
      sessionStorage.removeItem(CHEIE);
    } catch (er) { tinut = ''; }
    const [cheieTinuta, cand] = tinut.split('|');
    if (!parametri.has('tab') && cheieTinuta && Date.now() - Number(cand || 0) < 60000) {
      activeaza(nav, cheieTinuta, true);
    }
    // O ancoră (#stripe-webhook, după „Verifică webhook-ul”) dintr-un tab ascuns deschide tab-ul ei.
    const ancora = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    const panouAncora = ancora ? ancora.closest('[data-adm-tab-panou]') : null;
    if (panouAncora && panouAncora.hidden) {
      activeaza(nav, panouAncora.getAttribute('data-adm-tab-panou'), false);
      ancora.scrollIntoView({ block: 'start' });
    }
    // O adresă veche (?tab=tva, pagina Google…) deschide tab-ul nou și coboară la partea ei.
    const tinta = nav.getAttribute('data-adm-tab-tinta');
    const element = tinta ? document.getElementById(tinta) : null;
    if (element && !location.hash) element.scrollIntoView({ block: 'start' });
  });
})();
