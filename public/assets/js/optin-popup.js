/* Popup de abonare la newsletter.
   Stă în fișier, nu în antetul editabil din admin: firewall-ul gazdei
   (mod_security) respinge cu "Forbidden" formularele care conțin cod. */
(function () {
  var ZILE_PANA_LA_URMATOAREA_AFISARE = 1;     // „o dată pe zi"
  var ZILE_DUPA_ABONARE = 365;                 // dacă s-a abonat, nu-l mai deranjăm
  var SECUNDE_PANA_LA_AFISARE = 5;

  var CHEIE = 'bv_optin_pana_la';
  var popup = document.getElementById('bv-optin');
  var form = document.getElementById('bv-optin-form');
  var mesaj = document.getElementById('bv-optin-msg');
  if (!popup || !form) { return; }

  function acum() { return new Date().getTime(); }

  function amaneaza(zile) {
    try { localStorage.setItem(CHEIE, String(acum() + zile * 86400000)); } catch (e) {}
  }

  function eDeAfisat() {
    try {
      var pana = parseInt(localStorage.getItem(CHEIE) || '0', 10);
      return !pana || acum() > pana;
    } catch (e) {
      return true;   // fără localStorage (mod incognito), popup-ul apare oricum
    }
  }

  function deschide() {
    popup.hidden = false;
    document.body.classList.add('bv-optin-open');
    var camp = document.getElementById('bv-optin-email');
    if (camp) { setTimeout(function () { camp.focus(); }, 120); }
  }

  function inchide(zile) {
    popup.hidden = true;
    document.body.classList.remove('bv-optin-open');
    amaneaza(typeof zile === 'number' ? zile : ZILE_PANA_LA_URMATOAREA_AFISARE);
  }

  // Se închide DOAR de pe „×" sau „Nu acum".
  Array.prototype.forEach.call(
    popup.querySelectorAll('[data-bv-optin-close]'),
    function (btn) { btn.addEventListener('click', function () { inchide(); }); }
  );

  // Clic în afara cadranului: nu închide nimic, doar clatină scurt fereastra,
  // ca să se vadă că butoanele sunt singura ieșire.
  var fundal = popup.querySelector('.bv-optin__backdrop');
  if (fundal) {
    fundal.addEventListener('click', function () {
      popup.classList.add('bv-optin--nudge');
      setTimeout(function () { popup.classList.remove('bv-optin--nudge'); }, 320);
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var buton = form.querySelector('.bv-optin__submit');
    if (buton) { buton.disabled = true; buton.textContent = 'Se trimite...'; }

    fetch(form.getAttribute('action'), {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: new FormData(form)
    })
      .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
      .then(function (data) {
        if (mesaj) {
          mesaj.hidden = false;
          mesaj.className = 'bv-optin__msg ' + (data && data.ok ? 'is-ok' : 'is-err');
          mesaj.textContent = (data && data.message) || (data && data.ok
            ? 'Te-ai abonat cu succes.'
            : 'Nu am putut înregistra abonarea. Încearcă din nou.');
        }
        if (data && data.ok) {
          form.hidden = true;
          amaneaza(ZILE_DUPA_ABONARE);
          setTimeout(function () {
            popup.hidden = true;
            document.body.classList.remove('bv-optin-open');
          }, 2200);
        } else if (buton) {
          buton.disabled = false;
          buton.textContent = 'Mă abonez';
        }
      })
      .catch(function () {
        if (mesaj) {
          mesaj.hidden = false;
          mesaj.className = 'bv-optin__msg is-err';
          mesaj.textContent = 'Conexiune întreruptă. Încearcă din nou.';
        }
        if (buton) { buton.disabled = false; buton.textContent = 'Mă abonez'; }
      });
  });

  if (eDeAfisat()) {
    setTimeout(deschide, SECUNDE_PANA_LA_AFISARE * 1000);
  }
})();
