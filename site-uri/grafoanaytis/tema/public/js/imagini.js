/*
 * Imagini care încă nu au fost încărcate.
 *
 * Site-ul este construit înaintea fotografiilor: hero-ul, utilajele și o parte
 * din servicii așteaptă materiale de la client. Fără tratare, browserul afișează
 * pictograma de imagine ruptă plus textul alternativ, iar pagina pare stricată,
 * nu neterminată.
 *
 * Aici imaginea lipsă devine un dreptunghi discret, cu aceleași dimensiuni.
 * Când fișierul apare pe server, nu trebuie schimbat nimic: imaginea se încarcă
 * normal și marcajul nu se mai aplică.
 */
(function () {
  /*
   * O imagine transparentă de un pixel.
   *
   * Doar ascunderea prin CSS nu ajunge: browserul desenează în continuare
   * pictograma lui de imagine ruptă. Punând o sursă validă, imaginea nu mai
   * este ruptă, deci nu mai are ce desena — iar hașura o dă foaia de stil,
   * fiindcă un <img> nu își poate repeta propria sursă.
   */
  var PIXEL = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'
  );

  function marcheaza(img) {
    if (img.dataset.lipsa === '1') return;
    img.dataset.lipsa = '1';
    img.classList.add('imagine-lipsa');
    // Textul alternativ rămâne în DOM pentru cititoarele de ecran, dar imaginea
    // nu îl mai afișează, fiindcă acum chiar se încarcă.
    img.setAttribute('role', 'presentation');
    img.removeAttribute('srcset');
    img.src = PIXEL;
  }

  /*
   * O eroare nu înseamnă neapărat că fișierul lipsește.
   *
   * Imaginile au loading="lazy", iar la o derulare rapidă browserul poate
   * anula cereri pornite — ceea ce declanșează tot evenimentul „error". Dacă
   * am marca din prima, o imagine existentă ar rămâne hașurată până la
   * reîncărcarea paginii. De aceea încercăm o dată din nou, și abia a doua
   * eroare o considerăm reală.
   */
  function laEroare(img) {
    if (img.dataset.reincercat === '1') {
      marcheaza(img);
      return;
    }
    img.dataset.reincercat = '1';
    var sursa = img.currentSrc || img.src;
    img.addEventListener('error', function () { marcheaza(img); }, { once: true });
    // Interogarea adăugată ocolește o eventuală intrare negativă din cache.
    img.src = sursa + (sursa.indexOf('?') === -1 ? '?' : '&') + 'reincercare=1';
  }

  function verifica(img) {
    /*
     * O imagine fără sursă nu este o imagine lipsă.
     *
     * Stratul care arată fotografia pe tot ecranul are un <img> gol până la
     * prima apăsare pe buton — atunci primește sursa. Marcat din start, el
     * apărea ca un dreptunghi hașurat uriaș în josul paginii de produs.
     */
    var sursa = img.getAttribute('src');
    if (sursa === null || sursa.trim() === '') {
      return;
    }

    if (img.complete) {
      if (img.naturalWidth === 0) laEroare(img);
      return;
    }
    img.addEventListener('error', function () { laEroare(img); }, { once: true });
  }

  function porneste() {
    document.querySelectorAll('img').forEach(verifica);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', porneste);
  } else {
    porneste();
  }

  /* Imaginile cu loading="lazy" se încarcă pe măsură ce sunt derulate în cadru,
     deci eroarea poate apărea mult după ce pagina s-a afișat. */
  window.addEventListener('error', function (ev) {
    if (ev.target && ev.target.tagName === 'IMG') laEroare(ev.target);
  }, true);
})();
