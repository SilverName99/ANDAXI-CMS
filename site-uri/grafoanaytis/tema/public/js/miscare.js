/*
 * Mișcarea la derulare: apariția blocurilor și numerele care cresc.
 *
 * Amândouă pornesc din același loc — un „IntersectionObserver" — fiindcă
 * amândouă răspund la aceeași întrebare: a ajuns elementul pe ecran?
 *
 * Trei reguli le țin pe amândouă:
 *
 *   1. Fără JavaScript, pagina rămâne întreagă. Starea de pornire (nevăzut,
 *      coborât cu câțiva pixeli) o pune scriptul, prin clasa de pe „html", nu
 *      foaia de stil. Altfel, la un script blocat, jumătate de site ar fi
 *      rămas invizibil.
 *   2. Cine a cerut mai puțină mișcare din sistemul de operare nu primește
 *      niciuna: blocurile sunt de la început la locul lor, iar numerele scriu
 *      direct valoarea finală.
 *   3. Fiecare element se animă o singură dată. O pagină care „respiră" la
 *      fiecare derulare în sus și în jos obosește după al treilea drum.
 */
(function () {
  'use strict';

  var miscareRedusa = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;

  var areObservator = 'IntersectionObserver' in window;

  /* ── Blocurile care apar ─────────────────────────────────────────────── */

  /*
   * Lista este scrisă, nu ghicită.
   *
   * „Animează tot ce e copil de secțiune" ar fi prins și barele de filtre, și
   * rândurile de subsol, și ferestrele modale — lucruri care trebuie să fie
   * acolo din prima clipă. Aici stau doar blocurile de conținut care se citesc
   * pe rând, de sus în jos.
   */
  var TINTE = [
    'section > .container > h2',
    'section > .container > .eticheta-sectiune',
    'section > .container > .lead',
    'section > .container > .companie__deschidere',
    '.banda-alternanta__text',
    '.banda-alternanta__cadru',
    '.companie__masura',
    '.avantaj',
    '.cifra',
    '.capacitate__cifra',
    '.certificat',
    '.operatie',
    '.shop-catalog-v2__card',
    '[data-apare]'
    /*
     * Opririle cronologiei stau dinadins în afara listei. Ele se mișcă pe
     * orizontală, în cutia lor; cele din dreapta nu ating niciodată ecranul
     * până nu tragi de bandă, deci ar fi rămas nevăzute până atunci, iar
     * apoi ar fi apărut sub degetul care trage. O bandă pe care o miști tu
     * nu trebuie să se mai și aprindă.
     */
  ];

  function pregatesteAparitiile() {
    var elemente = [];
    for (var i = 0; i < TINTE.length; i++) {
      var gasite = document.querySelectorAll(TINTE[i]);
      for (var j = 0; j < gasite.length; j++) {
        if (elemente.indexOf(gasite[j]) === -1) {
          elemente.push(gasite[j]);
        }
      }
    }

    if (elemente.length === 0) {
      return;
    }

    /*
     * Întârzierea în trepte, dar numai între frați.
     *
     * Patru cifre alăturate care apar una după alta se citesc ca un rând care
     * se scrie. Aceleași patru cifre apărute deodată cu titlul de deasupra lor
     * par o pagină care sare. Contorul se ține pe părinte, deci un card dintr-o
     * grilă nu moștenește întârzierea titlului de secțiune.
     *
     * Plafonul este scurt dinadins. La o grilă de șaptesprezece produse toate
     * cardurile sunt frați, deci de la al patrulea încolo ar fi primit toate
     * aceeași întârziere — iar 280ms adunați la pragul de declanșare se simțeau
     * ca o pagină care răspunde greu la derulare. 45ms pe treaptă, cel mult
     * trei trepte: destul cât să se vadă un val, prea puțin cât să se aștepte.
     */
    var contoare = new WeakMap();

    document.documentElement.classList.add('are-aparitii');

    var observator = new IntersectionObserver(function (intrari) {
      for (var k = 0; k < intrari.length; k++) {
        var intrare = intrari[k];
        if (!intrare.isIntersecting) {
          continue;
        }
        intrare.target.classList.add('apare--vizibil');
        observator.unobserve(intrare.target);
      }
    }, {
      /*
       * Marginea de jos este pozitivă: zona de declanșare coboară sub ecran cu
       * 12% din înălțimea lui, deci blocul pornește cu puțin înainte de a intra
       * în pagină și ajunge la locul lui chiar când îl vezi.
       *
       * Era negativă — blocul aștepta să intre bine în ecran înainte să
       * pornească — și se simțea ca o întârziere la derulare, mai ales pe
       * grila de produse.
       */
      rootMargin: '0px 0px 12% 0px',
      threshold: 0
    });

    for (var m = 0; m < elemente.length; m++) {
      var el = elemente[m];
      var parinte = el.parentElement || document.body;
      var pozitie = contoare.get(parinte) || 0;
      contoare.set(parinte, pozitie + 1);

      el.classList.add('apare');
      if (pozitie > 0) {
        el.style.transitionDelay = Math.min(pozitie, 3) * 45 + 'ms';
      }
      observator.observe(el);
    }
  }

  /* ── Numerele care cresc ─────────────────────────────────────────────── */

  /*
   * Valoarea se ia din „data-creste", nu din text: textul este deja formatat
   * românește — „10.000" — iar un punct de mii citit ca separator zecimal ar
   * fi dat zece.
   *
   * Separatorul se pune doar dacă îl avea și textul scris în pagină. Altfel
   * anul înființării creștea până la „2.004", care nu este un an, ci două mii
   * patru. Regula este scrisă de om, în pagină, nu ghicită de script.
   */
  function formateaza(numar, cuSeparator) {
    if (!cuSeparator) {
      return String(numar);
    }
    try {
      return numar.toLocaleString('ro-RO');
    } catch (e) {
      return String(numar);
    }
  }

  function pregatesteNumerele() {
    var numere = document.querySelectorAll('[data-creste]');
    if (numere.length === 0) {
      return;
    }

    var DURATA = 1100;

    function creste(el) {
      var tinta = parseInt(el.getAttribute('data-creste'), 10);
      if (isNaN(tinta)) {
        return;
      }

      var cuSeparator = el.textContent.indexOf('.') !== -1;

      /*
       * Lățimea se blochează înainte de a porni.
       *
       * Cifra crește de la o unitate la cinci, iar cu cifre de lățimi diferite
       * blocul de sub ea ar sări la fiecare cadru. „ch" nu ajunge — fontul are
       * cifre proporționale — deci se măsoară lățimea finală, cea reală.
       */
      el.textContent = formateaza(tinta, cuSeparator);
      el.style.minWidth = el.getBoundingClientRect().width + 'px';

      var pornire = null;

      function cadru(acum) {
        if (pornire === null) {
          pornire = acum;
        }
        var trecut = Math.min(1, (acum - pornire) / DURATA);
        /* Încetinire spre final: ultima sutime de drum durează cât primele zece. */
        var usurat = 1 - Math.pow(1 - trecut, 3);
        el.textContent = formateaza(Math.round(tinta * usurat), cuSeparator);

        if (trecut < 1) {
          window.requestAnimationFrame(cadru);
        } else {
          el.textContent = formateaza(tinta, cuSeparator);
        }
      }

      el.textContent = formateaza(0, false);
      window.requestAnimationFrame(cadru);
    }

    if (miscareRedusa || !areObservator) {
      /* Fără animație, textul din pagină este deja valoarea finală: nu-l atingem. */
      return;
    }

    var observator = new IntersectionObserver(function (intrari) {
      for (var k = 0; k < intrari.length; k++) {
        if (!intrari[k].isIntersecting) {
          continue;
        }
        creste(intrari[k].target);
        observator.unobserve(intrari[k].target);
      }
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0 });

    for (var m = 0; m < numere.length; m++) {
      observator.observe(numere[m]);
    }
  }

  /* ── Antetul care se strânge ─────────────────────────────────────────── */

  /*
   * Sus, siglele stau mari; la prima mișcare a paginii se micșorează.
   *
   * Clasa se pune pe antet, nu pe „html": antetul este lipicios, deci el este
   * cel care mănâncă din ecran, și tot el trebuie să se strângă. Pragul de
   * 16px nu este ales la întâmplare — sub el intră tremuratul de derulare al
   * unui touchpad, care altfel ar fi umflat și strâns siglele întruna.
   *
   * Mărimea o schimbă foaia de stil; aici se schimbă doar starea.
   */
  function pregatesteAntetul() {
    var antet = document.querySelector('.navbar.sticky-top');
    if (!antet) {
      return;
    }

    function potriveste() {
      antet.classList.toggle('antet--micsorat', window.scrollY > 16);
    }

    potriveste();
    window.addEventListener('scroll', potriveste, { passive: true });
  }

  function porneste() {
    pregatesteAntetul();
    if (areObservator && !miscareRedusa) {
      pregatesteAparitiile();
    }
    pregatesteNumerele();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', porneste);
  } else {
    porneste();
  }
})();
