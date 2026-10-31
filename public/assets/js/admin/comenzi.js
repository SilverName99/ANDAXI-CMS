/*
 * Lista comenzilor (faza D, WP-33; views/admin/orders.php): bara acțiunilor în masă (componenta bara-actiuni-masa), scurtăturile
 * paginii și „Tipărește”. Toate acțiunile trimit formularul barei la adresa care exista deja, POST /admin/orders/bulk, cu
 * aceleași câmpuri ca înainte (bulk_action, order_ids[], bulk_status, bulk_shipping_value, back_url, _csrf): aici doar se aleg
 * statusul pentru „Confirmă”, se verifică ce lipsește și se cere confirmarea, ca vechea bară.
 *
 * Scurtături (doar în afara câmpurilor, fără panou deschis): A = Generează AWB (doar cu livrarea FAN: butonul există doar
 * atunci), C = Confirmă, P = Tipărește; „/” mută focusul în căutare (bara de filtre), Esc golește alegerea (tabel.js) sau
 * închide panoul comenzii (panou.js). Cu nimic bifat, tasta spune doar ce trebuie făcut întâi.
 */
(function () {
  'use strict';

  var formular = document.getElementById('comenzi-masa-formular');
  var tabel = document.getElementById('comenzi-tabel');
  if (!formular || !tabel) return;
  var bara = document.getElementById('comenzi-masa');
  var selectStatus = formular.querySelector('select[name="bulk_status"]');
  var campTransport = formular.querySelector('input[name="bulk_shipping_value"]');
  var nota = document.getElementById('comenzi-tasta-nota');

  function alese() {
    return Array.prototype.slice.call(tabel.querySelectorAll('input.adm-tabel__rand:checked:not(:disabled)'));
  }

  function spune(text) {
    if (!nota) return;
    nota.textContent = text;
    nota.hidden = false;
    clearTimeout(spune.t);
    spune.t = setTimeout(function () { nota.hidden = true; }, 4000);
  }

  formular.addEventListener('submit', function (e) {
    var buton = e.submitter;
    if (!buton || buton.name !== 'bulk_action') return;
    var n = alese().length;
    if (n === 0) {
      e.preventDefault();
      window.alert('Selectează cel puțin o comandă.');
      return;
    }
    if (buton.value === 'status') {
      // „Confirmă”: aceeași acțiune ca „Schimbă statusul” → „În procesare” (bulk_action=status, bulk_status=processing). Statusul
      // se pune abia aici, după confirmare: lista câmpurilor trimise se face după evenimentul submit.
      if (buton.hasAttribute('data-bulk-status') && selectStatus) {
        selectStatus.value = buton.getAttribute('data-bulk-status');
        return;
      }
      if (!selectStatus || !selectStatus.value) {
        e.preventDefault();
        window.alert('Selectează statusul pentru actualizarea în masă.');
        if (selectStatus) selectStatus.focus();
        return;
      }
      if (!window.confirm('Schimbi statusul a ' + n + ' comenzi în „'
        + selectStatus.options[selectStatus.selectedIndex].text.trim() + '”?')) {
        e.preventDefault();
      }
      return;
    }
    if (selectStatus) selectStatus.value = '';
    if (buton.value === 'shipping') {
      var valoare = campTransport ? campTransport.value.trim() : '';
      if (valoare === '' || !Number.isFinite(Number(valoare)) || Number(valoare) < 0) {
        e.preventDefault();
        window.alert('Scrie valoarea transportului (ex. 30).');
        if (campTransport) campTransport.focus();
        return;
      }
      // Scrie peste transportul tuturor comenzilor bifate, deci merită o oprire: o selecție greșită s-ar vedea abia pe facturi.
      if (!window.confirm('Pui transportul pe ' + Number(valoare).toFixed(2) + ' lei la ' + n + ' comenzi? Totalul fiecărei comenzi se reface.')) {
        e.preventDefault();
      }
      return;
    }
    if (campTransport) campTransport.value = '';
  });

  // „Tipărește”: o foaie cu comenzile bifate, din datele pe care lista le are deja (nimic cerut serverului).
  function esc(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function lei(v) { var n = Number(v); return (Number.isFinite(n) ? n : 0).toFixed(2) + ' RON'; }
  function tipareste() {
    var comenzi = alese().map(function (bifa) {
      var rand = bifa.closest('tr');
      var buton = rand && rand.querySelector('.view-order-btn');
      try { return JSON.parse((buton && buton.getAttribute('data-order')) || '{}'); } catch (err) { return {}; }
    }).filter(function (o) { return o && o.id; });
    if (!comenzi.length) { spune('Bifează întâi comenzile de tipărit.'); return; }
    var html = comenzi.map(function (o) {
      var adresa = [o.billing_address_line1, o.billing_address_line2, o.billing_city, o.billing_county, o.billing_postcode]
        .filter(function (x) { return String(x || '').trim() !== ''; }).map(esc).join(', ');
      var produse = (Array.isArray(o.items) ? o.items : []).map(function (p) {
        return '<tr><td>' + esc(p.product_name) + '</td><td class="d">' + esc(p.quantity) + '</td><td class="d">' + lei(p.line_total) + '</td></tr>';
      }).join('');
      return '<section><h2>Comanda ' + esc(o.order_number) + '</h2><p>' + esc(o.created_at) + '</p>'
        + '<p><strong>' + esc(o.display_name || ((o.billing_first_name || '') + ' ' + (o.billing_last_name || ''))) + '</strong><br>'
        + esc(o.billing_phone) + ' · ' + esc(o.billing_email) + '<br>' + adresa + '</p>'
        + '<table><thead><tr><th>Produs</th><th class="d">Cant.</th><th class="d">Total</th></tr></thead><tbody>' + produse + '</tbody></table>'
        + '<p class="d">Transport: ' + lei(o.shipping_cost) + '<br><strong>Total: ' + lei(o.total) + '</strong></p>'
        + (String(o.notes || '').trim() ? '<p>Observații client: ' + esc(o.notes) + '</p>' : '') + '</section>';
    }).join('');
    var cadru = document.createElement('iframe');
    cadru.setAttribute('aria-hidden', 'true');
    cadru.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
    document.body.appendChild(cadru);
    var doc = cadru.contentDocument;
    doc.open();
    doc.write('<!doctype html><html lang="ro"><head><meta charset="utf-8"><title>Comenzi</title><style>'
      + 'body{font:12pt/1.4 system-ui,sans-serif;color:#111;margin:12mm}section{page-break-after:always}section:last-child{page-break-after:auto}'
      + 'h2{font-size:15pt;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin:8px 0}th,td{border-bottom:1px solid #ccc;padding:4px 6px;text-align:left}'
      + '.d{text-align:right}</style></head><body>' + html + '</body></html>');
    doc.close();
    setTimeout(function () {
      cadru.contentWindow.focus();
      cadru.contentWindow.print();
      setTimeout(function () { cadru.remove(); }, 1000);
    }, 50);
  }
  var butonTipar = document.getElementById('comenzi-tipareste');
  if (butonTipar) butonTipar.addEventListener('click', tipareste);

  // Scurtăturile paginii.
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName))) return;
    if (document.querySelector('.adm-sertar:not([hidden])') || document.querySelector('dialog[open]') || document.querySelector('.modal-overlay.open')) return;
    var tasta = (e.key || '').toLowerCase();
    var buton = tasta === 'a' || tasta === 'c' || tasta === 'p'
      ? document.querySelector('#comenzi-masa [data-tasta-comenzi="' + tasta + '"]') : null;
    if (!buton) return;
    e.preventDefault();
    if (!bara || bara.hidden || alese().length === 0) {
      spune('Bifează întâi comenzile (căsuța din stânga rândului; Shift+clic alege un șir).');
      return;
    }
    buton.click();
  });

  // O singură comandă găsită după numărul ei (de exemplu din Acasă → „Comenzi recente”): panoul ei se deschide singur.
  // După încărcarea tuturor scripturilor (panou.js deschide sertarul la clic).
  window.addEventListener('load', function () {
    var cautat = new URLSearchParams(window.location.search).get('q');
    var butoane = document.querySelectorAll('.view-order-btn');
    if (!cautat || butoane.length !== 1) return;
    try {
      var unica = JSON.parse(butoane[0].getAttribute('data-order') || '{}');
      if (String(unica.order_number || '') === cautat.trim()) butoane[0].click();
    } catch (err) { /* lista rămâne cum e */ }
  });
})();
