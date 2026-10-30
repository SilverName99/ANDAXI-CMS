/*
 * Sertarul cu cererea de ofertă (pluginul cereri_oferta; WP-26: GR-15, din grafoanaytis public/assets/js/sertar-oferta.js;
 * încărcat doar de {{product_quote_button}}, App\Support\CereriOferta::butonHtml).
 *
 * Trimite formularul fără să reîncarce pagina: vizitatorul rămâne pe produs,
 * ceea ce este tot rostul sertarului. Panoul în sine este un offcanvas
 * Bootstrap — deschiderea, închiderea cu Escape și ținerea focusului înăuntru
 * le face el.
 */
(function () {
  'use strict';

  var formulare = document.querySelectorAll('.sertar-oferta__formular');
  if (formulare.length === 0) {
    return;
  }

  formulare.forEach(function (formular) {
    var raspuns = formular.querySelector('.sertar-oferta__raspuns');
    var buton = formular.querySelector('button[type="submit"]');
    var etichetaButon = buton ? buton.innerHTML : '';

    function spune(text, reusit) {
      if (!raspuns) {
        return;
      }
      raspuns.textContent = text;
      raspuns.className = 'sertar-oferta__raspuns ' + (reusit ? 'este-bine' : 'este-eroare');
    }

    formular.addEventListener('submit', function (eveniment) {
      eveniment.preventDefault();

      /*
       * Validarea browserului rulează prima. „novalidate" pe formular oprește
       * bulele native, dar checkValidity() rămâne, deci primim gratis
       * verificarea de e-mail și de câmpuri obligatorii.
       */
      if (!formular.checkValidity()) {
        var primulGresit = formular.querySelector(':invalid');
        if (primulGresit) {
          primulGresit.focus();
        }
        spune('Completați numele, adresa de e-mail și bifați acordul.', false);
        return;
      }

      var date = {
        product_slug: formular.getAttribute('data-produs') || '',
        name: formular.elements.name.value,
        company: formular.elements.company.value,
        email: formular.elements.email.value,
        phone: formular.elements.phone.value,
        message: formular.elements.message.value,
        website: formular.elements.website.value,
        consent: formular.elements.consent.checked
      };

      if (buton) {
        buton.disabled = true;
        buton.textContent = 'Se trimite…';
      }
      spune('', true);

      fetch('/api/cerere-oferta', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(date)
      })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (r) {
          if (r && r.ok) {
            formular.reset();
            spune(r.message || 'Am primit cererea.', true);
          } else {
            spune((r && r.error) || 'Cererea nu a putut fi trimisă. Încercați din nou.', false);
          }
        })
        .catch(function () {
          spune('Cererea nu a putut fi trimisă. Verificați conexiunea și încercați din nou.', false);
        })
        .then(function () {
          if (buton) {
            buton.disabled = false;
            buton.innerHTML = etichetaButon;
          }
        });
    });
  });
})();
