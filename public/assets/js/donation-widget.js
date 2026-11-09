(function () {
  'use strict';
  var cfg = window.donationConfig;
  var overlay = document.querySelector('[data-dw-overlay]');
  if (!cfg || !overlay) { return; }

  var CUR = String(cfg.currency || 'ron').toUpperCase();
  var UNIT = cfg.currency === 'ron' ? 'lei' : CUR;

  var state = {
    freq: 'once',
    amount: parseInt(cfg.defaultAmount, 10) || 100,
    designation: (cfg.designations && cfg.designations[0]) || '',
    cover: false,
    first: '', last: '', email: '', newsletter: false, consent: false
  };

  var stripe = null, elements = null, paymentEl = null, stepStack = [];

  var $ = function (sel) { return overlay.querySelector(sel); };
  var $$ = function (sel) { return Array.prototype.slice.call(overlay.querySelectorAll(sel)); };

  function money(cents) {
    return (cents / 100).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + CUR;
  }
  function feeCents(baseCents) {
    if (!state.cover || !cfg.coverFeesEnabled) { return 0; }
    return Math.round(baseCents * (parseFloat(cfg.coverFeesPercent) || 0) / 100 + (parseFloat(cfg.coverFeesFixed) || 0) * 100);
  }
  function totalCents() {
    var base = state.amount * 100;
    return base + feeCents(base);
  }

  /* --- Construiește sumele + destinațiile --- */
  var amountsWrap = $('[data-dw-amounts]');
  (cfg.amounts || []).forEach(function (a, i) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'dw-amt' + (parseInt(a, 10) === state.amount ? ' is-active' : '');
    b.setAttribute('data-amt', a);
    b.textContent = Number(a).toLocaleString('ro-RO') + ' ' + UNIT;
    b.addEventListener('click', function () {
      $$('.dw-amt').forEach(function (x) { x.classList.remove('is-active'); });
      b.classList.add('is-active');
      var other = $('[data-dw-other]'); if (other) { other.value = ''; }
      state.amount = parseInt(a, 10) || 0;
    });
    amountsWrap.appendChild(b);
  });
  var curEl = $('[data-dw-cur]'); if (curEl) { curEl.textContent = CUR; }
  var otherIn = $('[data-dw-other]');
  if (otherIn) {
    otherIn.addEventListener('input', function () {
      $$('.dw-amt').forEach(function (x) { x.classList.remove('is-active'); });
      state.amount = parseInt(otherIn.value, 10) || 0;
    });
  }
  var desigSel = $('[data-dw-designation]');
  (cfg.designations || []).forEach(function (d) {
    var o = document.createElement('option'); o.textContent = d; desigSel.appendChild(o);
  });
  if (desigSel) { desigSel.addEventListener('change', function () { state.designation = desigSel.value; }); }

  /* Ascunde toggle-ul lunar dacă e dezactivat */
  if (!cfg.monthlyEnabled) {
    var monthlyBtn = overlay.querySelector('.dw-freq[data-freq="monthly"]');
    if (monthlyBtn) { monthlyBtn.style.display = 'none'; }
  }
  $$('.dw-freq').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('.dw-freq').forEach(function (x) { x.classList.remove('is-active'); });
      b.classList.add('is-active');
      state.freq = b.getAttribute('data-freq');
    });
  });

  /* --- Navigare pași --- */
  var amountView = overlay.querySelector('[data-dw-view="amount"]');
  var wizardView = overlay.querySelector('[data-dw-view="wizard"]');
  var titleEl = $('[data-dw-step-title]');
  var TITLES = { upsell: 'Devino donator lunar', details: 'Datele tale', payment: 'Alege metoda de plată', thanks: '' };

  function showStep(step, push) {
    if (push !== false) { stepStack.push(step); }
    if (step === 'amount') {
      amountView.hidden = false; wizardView.hidden = true;
      return;
    }
    amountView.hidden = true; wizardView.hidden = false;
    $$('.dw-step').forEach(function (s) { s.hidden = s.getAttribute('data-step') !== step; });
    if (titleEl) { titleEl.textContent = TITLES[step] || ''; }
    if (step === 'payment') { mountPayment(); }
  }
  function back() {
    stepStack.pop();
    var prev = stepStack[stepStack.length - 1] || 'amount';
    showStep(prev, false);
  }

  $('[data-dw-to-details]').addEventListener('click', function () {
    if (state.amount < (parseInt(cfg.minAmount, 10) || 1)) {
      alert('Suma minimă este ' + cfg.minAmount + ' ' + CUR + '.');
      return;
    }
    showStep(state.freq === 'once' ? 'upsell' : 'details');
    syncUpsell();
  });
  function syncUpsell() {
    var lbl = money(totalCents());
    var a1 = $('[data-dw-upsell-amount]'); if (a1) { a1.textContent = lbl; }
    var a2 = $('[data-dw-upsell-amount2]'); if (a2) { a2.textContent = money(totalCents()); }
  }
  $('[data-dw-upsell-yes]').addEventListener('click', function () {
    state.freq = 'monthly';
    var mb = overlay.querySelector('.dw-freq[data-freq="monthly"]');
    if (mb) { $$('.dw-freq').forEach(function (x) { x.classList.remove('is-active'); }); mb.classList.add('is-active'); }
    showStep('details');
  });
  $('[data-dw-upsell-no]').addEventListener('click', function () { showStep('details'); });

  $('[data-dw-to-payment]').addEventListener('click', function () {
    state.first = ($('[data-dw-first]').value || '').trim();
    state.last = ($('[data-dw-last]').value || '').trim();
    state.email = ($('[data-dw-email]').value || '').trim();
    state.newsletter = $('[data-dw-newsletter]').checked;
    state.consent = $('[data-dw-consent]').checked;
    var err = $('[data-dw-details-err]');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email)) { return showErr(err, 'Introdu o adresă de email validă.'); }
    if (!state.consent) { return showErr(err, 'Trebuie să accepți politica de confidențialitate.'); }
    if (err) { err.hidden = true; }
    showStep('payment');
  });

  $$('[data-dw-back]').forEach(function (b) { b.addEventListener('click', back); });

  function showErr(el, msg) { if (el) { el.textContent = msg; el.hidden = false; } }

  var isDemo = !!cfg.demo || typeof Stripe === 'undefined' || !cfg.publishableKey;

  /* --- Stripe Payment Element (flux deferred) --- */
  function mountPayment() {
    var totalEl = $('[data-dw-total]'); if (totalEl) { totalEl.textContent = money(totalCents()); }
    var host = $('[data-dw-payment-element]');

    if (isDemo) {
      host.innerHTML =
        '<div class="dw-demo-note">Exemplu — plata nu se procesează.</div>' +
        '<div class="dw-demo-tabs">' +
          '<button type="button" class="dw-demo-tab is-active">💳 Card</button>' +
          '<button type="button" class="dw-demo-tab">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px"><path fill="#4285F4" d="M22.5 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.9a5 5 0 0 1-2.2 3.3v2.7h3.5c2-1.9 3.3-4.7 3.3-7.8z"/><path fill="#34A853" d="M12 23c3 0 5.5-1 7.3-2.7l-3.5-2.7c-1 .7-2.3 1.1-3.8 1.1-2.9 0-5.4-2-6.3-4.6H2v2.8A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.7 14.1a6.6 6.6 0 0 1 0-4.2V7.1H2a11 11 0 0 0 0 9.8l3.7-2.8z"/><path fill="#EA4335" d="M12 5.4c1.6 0 3 .6 4.2 1.6l3.1-3.1A11 11 0 0 0 2 7.1l3.7 2.8C6.6 7.3 9.1 5.4 12 5.4z"/></svg> Google Pay</button>' +
        '</div>' +
        '<div class="dw-demo-fields">' +
          '<input type="text" value="4242 4242 4242 4242" readonly>' +
          '<div class="dw-demo-row"><input type="text" value="12 / 34" readonly><input type="text" value="123" readonly></div>' +
        '</div>';
      host.querySelectorAll('.dw-demo-tab').forEach(function (t) {
        t.addEventListener('click', function () {
          host.querySelectorAll('.dw-demo-tab').forEach(function (x) { x.classList.remove('is-active'); });
          t.classList.add('is-active');
        });
      });
      return;
    }

    if (!stripe) { stripe = Stripe(cfg.publishableKey); }
    if (paymentEl) { try { paymentEl.unmount(); } catch (e) {} paymentEl = null; }
    // Donatia lunara creeaza un abonament, iar Stripe salveaza cardul pentru
    // lunile urmatoare. Elements trebuie sa stie asta din start, altfel
    // confirmarea pica pe nepotrivire de setup_future_usage.
    elements = stripe.elements({
      mode: state.freq === 'monthly' ? 'subscription' : 'payment',
      amount: totalCents(),
      currency: cfg.currency,
      appearance: { theme: 'stripe', variables: { colorPrimary: '#ea4968', borderRadius: '10px' } }
    });
    paymentEl = elements.create('payment');
    paymentEl.mount(host);
  }

  var coverCb = $('[data-dw-cover]');
  if (coverCb) {
    if (!cfg.coverFeesEnabled) { var wrap = coverCb.closest('.dw-cover'); if (wrap) { wrap.style.display = 'none'; } }
    coverCb.addEventListener('change', function () {
      state.cover = coverCb.checked;
      var totalEl = $('[data-dw-total]'); if (totalEl) { totalEl.textContent = money(totalCents()); }
      if (!isDemo && elements) { try { elements.update({ amount: totalCents() }); } catch (e) {} }
    });
  }

  function showThanks() {
    var tt = $('[data-dw-thanks-text]');
    if (tt) {
      tt.textContent = (isDemo ? '(Exemplu) ' : '') + (cfg.thanksText || 'Îți mulțumim din suflet pentru sprijin!');
    }
    showStep('thanks');
  }

  var donateBtn = $('[data-dw-donate]');
  donateBtn.addEventListener('click', function () {
    var payErr = $('[data-dw-pay-err]');
    if (payErr) { payErr.hidden = true; }

    // Mod Exemplu: sari peste Stripe, mergi direct la mulțumire
    if (isDemo) { showThanks(); return; }

    if (!elements || !stripe) { return; }
    donateBtn.disabled = true; donateBtn.textContent = 'Se procesează...';
    elements.submit().then(function (res) {
      if (res.error) { throw res.error; }
      return fetch('/api/donations/intent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: state.amount,
          frequency: state.freq,
          designation: state.designation,
          cover_fees: state.cover,
          name: (state.first + ' ' + state.last).trim(),
          email: state.email,
          newsletter: state.newsletter,
          consent: state.consent
        })
      });
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) { throw new Error((data && data.message) || 'Eroare la inițierea plății.'); }
      return stripe.confirmPayment({
        elements: elements,
        clientSecret: data.clientSecret,
        confirmParams: { return_url: window.location.href },
        redirect: 'if_required'
      });
    }).then(function (result) {
      if (result && result.error) { throw result.error; }
      var tt = $('[data-dw-thanks-text]');
      if (tt) { tt.textContent = cfg.thanksText || 'Îți mulțumim din suflet pentru sprijin!'; }
      showStep('thanks');
    }).catch(function (err) {
      showErr(payErr, (err && err.message) || 'A apărut o eroare. Încearcă din nou.');
    }).then(function () {
      donateBtn.disabled = false; donateBtn.textContent = 'Donează';
    });
  });

  /* --- Deschidere / închidere --- */
  function open() {
    stepStack = [];
    showStep('amount');
    overlay.hidden = false;
    document.body.classList.add('dw-open');
  }
  function close() { overlay.hidden = true; document.body.classList.remove('dw-open'); closePops(); }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-dw-open], [data-sustine]')) { e.preventDefault(); open(); }
    if (e.target.closest('[data-dw-close]')) { close(); }
  });
  overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) { closePops(); close(); } });

  /* --- Popovere linkuri --- */
  function closePops() { $$('.dw-pop').forEach(function (p) { p.hidden = true; }); }
  $$('[data-dw-pop]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var pop = btn.parentNode.querySelector('.dw-pop');
      var willOpen = pop.hidden; closePops(); pop.hidden = !willOpen;
    });
  });
  overlay.addEventListener('click', function (e) { if (!e.target.closest('.dw-link-wrap')) { closePops(); } });
  $$('[data-dw-pop-close]').forEach(function (b) { b.addEventListener('click', closePops); });
  var report = $('[data-dw-report]'), reportOk = $('[data-dw-report-ok]'), reportSend = $('[data-dw-report-send]');
  function syncReport() { if (reportSend) { reportSend.disabled = !(report && report.value.trim() && reportOk && reportOk.checked); } }
  if (report) { report.addEventListener('input', syncReport); }
  if (reportOk) { reportOk.addEventListener('change', syncReport); }
  if (reportSend) { reportSend.addEventListener('click', function () { if (report) { report.value = ''; } if (reportOk) { reportOk.checked = false; } syncReport(); closePops(); alert('Mulțumim! Am primit raportul.'); }); }

  /* Badge „MOD EXEMPLU" când e demo */
  var dlg = overlay.querySelector('.dw-dialog');
  if (isDemo && dlg) {
    var badge = document.createElement('div');
    badge.className = 'dw-demo-badge';
    badge.textContent = 'MOD EXEMPLU';
    dlg.appendChild(badge);
  }
})();
