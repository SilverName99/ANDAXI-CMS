/*
 * Componenta panou-lateral, felul „sertar” (WP-31): se deschide din dreapta cu un buton data-adm-panou="<id>"
 * (data-adm-panou-sursa="<adresă>" încarcă acolo detaliile, cu GET, din adresele de detalii care există deja); se
 * închide cu ×, cu un clic pe fundal sau cu Esc; focusul trece în panou și revine pe buton la închidere.
 */
(function () {
  'use strict';
  var deschis = null;
  var deUndeAVenit = null;

  function inchide() {
    if (!deschis) return false;
    deschis.hidden = true;
    document.body.classList.remove('adm-are-sertar');
    deschis = null;
    if (deUndeAVenit && document.contains(deUndeAVenit)) deUndeAVenit.focus();
    deUndeAVenit = null;
    return true;
  }

  function incarca(sertar, sursa) {
    var corp = sertar.querySelector('.adm-sertar__corp');
    if (!corp || !sursa) return;
    corp.setAttribute('aria-busy', 'true');
    corp.innerHTML = '<p class="adm-sertar__incarcare">Se încarcă…</p>';
    fetch(sursa, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('cod ' + r.status);
        return r.text();
      })
      .then(function (html) { corp.innerHTML = html; })
      .catch(function (e) {
        corp.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'adm-sertar__eroare';
        p.textContent = 'Nu am putut încărca detaliile (' + e.message + '). Închide panoul și încearcă din nou.';
        corp.appendChild(p);
      })
      .then(function () { corp.removeAttribute('aria-busy'); });
  }

  function deschide(sertar, buton) {
    if (deschis && deschis !== sertar) inchide();
    if (window.axCadru && window.axCadru.inchideBara) window.axCadru.inchideBara();
    deUndeAVenit = buton || document.activeElement;
    sertar.hidden = false;
    document.body.classList.add('adm-are-sertar');
    deschis = sertar;
    var sursa = (buton && buton.getAttribute('data-adm-panou-sursa')) || sertar.getAttribute('data-adm-panou-sursa');
    if (sursa) incarca(sertar, sursa);
    var panou = sertar.querySelector('.adm-panou--sertar');
    if (panou) panou.focus();
  }

  document.addEventListener('click', function (e) {
    var buton = e.target.closest('[data-adm-panou]');
    if (buton) {
      var sertar = document.getElementById(buton.getAttribute('data-adm-panou'));
      if (sertar && sertar.classList.contains('adm-sertar')) {
        e.preventDefault();
        deschide(sertar, buton);
      }
      return;
    }
    if (deschis && (e.target.closest('.adm-sertar__inchide') || e.target.classList.contains('adm-sertar__fundal'))) inchide();
  });

  document.addEventListener('keydown', function (e) {
    if (!deschis) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      inchide();
      return;
    }
    // Tab rămâne în panou cât e deschis.
    if (e.key === 'Tab') {
      var panou = deschis.querySelector('.adm-panou--sertar');
      var focusabile = Array.prototype.slice.call(panou.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter(function (el) { return el.offsetParent !== null; });
      if (!focusabile.length) { e.preventDefault(); return; }
      var primul = focusabile[0];
      var ultimul = focusabile[focusabile.length - 1];
      if (e.shiftKey && (document.activeElement === primul || document.activeElement === panou)) { e.preventDefault(); ultimul.focus(); }
      else if (!e.shiftKey && document.activeElement === ultimul) { e.preventDefault(); primul.focus(); }
    }
  });

  window.admPanou = { deschide: deschide, inchide: inchide };
})();
