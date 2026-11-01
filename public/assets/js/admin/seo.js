/*
 * Panoul SEO (WP-37; views/admin/componente/panou-seo.php): „Cum apare în Google” se reface în timp ce scrii, numărul
 * caracterelor (60 / 155) se înroșește peste limită, iar verificările (lipsă, prea lung, același titlu sau aceeași
 * descriere pe altă pagină) se cer adresei „verifica” din configurare (doar citire, GET, JSON). Configurarea panoului e
 * în atributul data-adm-seo (JSON: tip, ref, verifica, adresa, prefix, sursaSlug, sursaTitlu, sursaDescriere, sursaRef,
 * titluImplicit, magazin); elementele dinăuntru se găsesc după clasă (adm-seo__…). Nu schimbă nimic din ce trimite
 * formularul.
 *
 * Alte scripturi (editorul produsului, care își completează singur câmpurile) cer o reîmprospătare cu
 * window.axSeo.actualizeaza().
 */
(function () {
  'use strict';

  var LIMITA_TITLU = 60;
  var LIMITA_DESCRIERE = 155;

  function config(panou) {
    if (!panou._seoConfig) {
      try { panou._seoConfig = JSON.parse(panou.getAttribute('data-adm-seo') || '{}') || {}; } catch (e) { panou._seoConfig = {}; }
    }
    return panou._seoConfig;
  }
  function camp(panou, nume) { return panou.querySelector('.adm-seo__camp-' + nume); }
  function valoareSelector(selector) {
    if (!selector) return '';
    var el = document.querySelector(selector);
    return el ? String(el.value || '').trim() : '';
  }
  function curat(text) { return String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); }
  function scurt(text, limita) { return text.length > limita ? text.slice(0, limita - 1) + '…' : text; }

  function adresa(panou) {
    var c = config(panou);
    var cale = c.adresa || '';
    var prefix = c.prefix || '';
    var slug = valoareSelector(c.sursaSlug);
    if (prefix) cale = prefix + (slug || '…');
    var parti = cale.split('/').filter(function (p) { return p !== ''; });
    return window.location.host + (parti.length ? ' › ' + parti.join(' › ') : '');
  }

  function titluImplicit(panou) {
    var c = config(panou);
    var nume = valoareSelector(c.sursaTitlu);
    var magazin = c.magazin || '';
    if (nume) return magazin && nume.toLowerCase() !== magazin.toLowerCase() ? nume + ' | ' + magazin : nume;
    return c.titluImplicit || '';
  }

  function verificariLocale(titlu, descriere) {
    var lista = [];
    if (!titlu) lista.push({ cod: 'lipsa', text: 'Fără titlu pentru Google: se folosește titlul paginii urmat de numele magazinului.' });
    else if (titlu.length > LIMITA_TITLU) lista.push({ cod: 'lung', text: 'Titlul are ' + titlu.length + ' de caractere; Google arată de obicei cel mult ' + LIMITA_TITLU + '.' });
    if (!descriere) lista.push({ cod: 'lipsa', text: 'Fără descriere pentru Google: Google alege singur un text din pagină (sau se folosește descrierea făcută automat).' });
    else if (descriere.length > LIMITA_DESCRIERE) lista.push({ cod: 'lung', text: 'Descrierea are ' + descriere.length + ' de caractere; Google arată de obicei cel mult ' + LIMITA_DESCRIERE + '.' });
    return lista;
  }

  function arataVerificari(panou, lista) {
    var ul = panou.querySelector('.adm-seo__verificari');
    if (!ul) return;
    ul.textContent = '';
    lista.forEach(function (v) {
      var li = document.createElement('li');
      li.className = 'adm-seo__verificare adm-seo__verificare--' + (v.cod || '');
      li.appendChild(document.createTextNode(v.text || ''));
      (v.alte || []).forEach(function (alta) {
        var a = document.createElement('a');
        a.href = alta.link || '#';
        a.textContent = alta.nume || '';
        li.appendChild(document.createTextNode(' '));
        li.appendChild(a);
      });
      ul.appendChild(li);
    });
  }

  function cereVerificari(panou, titlu, descriere) {
    var c = config(panou);
    var adresaVerificare = c.verifica;
    if (!adresaVerificare || !window.fetch || (!titlu && !descriere)) {
      arataVerificari(panou, verificariLocale(titlu, descriere));
      return;
    }
    var ref = (panou._seoRef !== undefined ? panou._seoRef : c.ref) || valoareSelector(c.sursaRef) || '';
    var cheie = [ref, titlu, descriere].join('\u0001');
    if (panou._seoUltima === cheie) return;
    panou._seoUltima = cheie;
    var parametri = new URLSearchParams({ tip: c.tip || '', ref: ref, titlu: titlu, descriere: descriere });
    fetch(adresaVerificare + '?' + parametri.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (date) {
        if (panou._seoUltima !== cheie) return;
        arataVerificari(panou, date && Array.isArray(date.verificari) ? date.verificari : verificariLocale(titlu, descriere));
      })
      .catch(function () { arataVerificari(panou, verificariLocale(titlu, descriere)); });
  }

  function actualizeazaPanou(panou) {
    var cTitlu = camp(panou, 'titlu');
    var cDescriere = camp(panou, 'descriere');
    var titluScris = cTitlu ? String(cTitlu.value || '').replace(/\s+/g, ' ').trim() : '';
    var descriereScrisa = cDescriere ? String(cDescriere.value || '').replace(/\s+/g, ' ').trim() : '';
    var titlu = titluScris || titluImplicit(panou);
    var descriere = descriereScrisa || curat(valoareSelector(config(panou).sursaDescriere));
    var arata = function (clasa, text) { var el = panou.querySelector('.adm-seo__' + clasa); if (el) el.textContent = text; };
    arata('adresa', adresa(panou));
    arata('titlu', scurt(titlu || 'Titlul paginii', LIMITA_TITLU));
    arata('text', scurt(descriere, LIMITA_DESCRIERE + 5));
    var nTitlu = panou.querySelector('.adm-seo__numar-titlu');
    var nDescriere = panou.querySelector('.adm-seo__numar-descriere');
    if (nTitlu) nTitlu.textContent = String(titlu.length);
    if (nDescriere) nDescriere.textContent = String(descriere.length);
    var numar = panou.querySelector('.adm-seo__numar');
    if (numar) numar.classList.toggle('este-lung', titlu.length > LIMITA_TITLU || descriere.length > LIMITA_DESCRIERE);
    clearTimeout(panou._seoTemporizator);
    panou._seoTemporizator = setTimeout(function () { cereVerificari(panou, titluScris, descriereScrisa); }, 400);
  }

  function toate() { return Array.prototype.slice.call(document.querySelectorAll('[data-adm-seo]')); }

  toate().forEach(function (panou) {
    var form = null;
    var cTitlu = camp(panou, 'titlu');
    if (cTitlu) form = cTitlu.form;
    var c = config(panou);
    var surse = [c.sursaSlug, c.sursaTitlu, c.sursaDescriere]
      .map(function (s) { return s ? document.querySelector(s) : null; })
      .filter(Boolean);
    [camp(panou, 'titlu'), camp(panou, 'descriere')].concat(surse).forEach(function (el) {
      if (el) el.addEventListener('input', function () { actualizeazaPanou(panou); });
    });
    if (form) form.addEventListener('reset', function () { setTimeout(function () { actualizeazaPanou(panou); }, 0); });
    actualizeazaPanou(panou);
  });

  window.axSeo = {
    actualizeaza: function () { toate().forEach(actualizeazaPanou); },
    // Pagina a cărei SEO e în panou (editorul produsului îl refolosește pentru fiecare produs deschis).
    puneRef: function (panou, ref) { if (panou) { panou._seoRef = String(ref || ''); panou._seoUltima = null; } }
  };
})();
