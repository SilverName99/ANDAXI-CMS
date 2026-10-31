/*
 * Componentele de admin (WP-31): ajutorul din spatele lui „?” de lângă titlul paginii (antet-pagina). Un clic (sau Enter,
 * Spațiu) îl deschide sau îl închide; Esc îl închide și pune focusul înapoi pe „?”. Celelalte componente: tabel.js
 * (bifele, bara acțiunilor în masă), panou.js (sertarul din dreapta), filtre.js (filtrele în adresă).
 */
(function () {
  'use strict';
  function comuta(buton, deschis) {
    const tinta = document.getElementById(buton.getAttribute('aria-controls') || '');
    if (!tinta) return;
    tinta.hidden = !deschis;
    buton.setAttribute('aria-expanded', deschis ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    const buton = e.target.closest('.adm-ajutor__buton');
    if (!buton) return;
    comuta(buton, buton.getAttribute('aria-expanded') !== 'true');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    const deschise = document.querySelectorAll('.adm-ajutor__buton[aria-expanded="true"]');
    if (!deschise.length) return;
    deschise.forEach(function (b) { comuta(b, false); });
    if (document.activeElement && document.activeElement.closest('.adm-ajutor')) deschise[0].focus();
    e.preventDefault();
  });
})();
