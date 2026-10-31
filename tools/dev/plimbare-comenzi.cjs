// Plimbarea prin lista comenzilor refăcută (faza D, WP-33; planul: „scripted walk through each order-modal panel (FAN, BT,
// ERP, links, promo, preorder) on demo orders”). Ca administrator general logat, pe /admin/orders:
//  - deschide fiecare comandă în panoul din dreapta (ochiul rândului), verifică că panoul are conținutul comenzii (numărul ei în
//    titlu, clientul, produsele), notează ce bucăți are (FANbox, plata BT, linkul de plată, promoționalele) și că Esc îl
//    închide cu focusul înapoi pe ochi; nicio eroare JavaScript;
//  - pe rânduri: formularele care existau (AWB FAN, retrimiterea în ERP, eliberarea precomenzii, confirmarea OP, coșul);
//  - bara acțiunilor în masă: o tastă fără bife doar spune ce trebuie făcut; cu o bifă, bara apare, A (cu FAN) și C cer
//    confirmarea (refuzată aici: nimic nu se trimite), P pregătește foaia de tipărit, Esc golește bifele;
//  - „?” arată scurtăturile paginii;
//  - Enter în câmpul transportului trimite „Corectează transportul” cu valoarea scrisă (cererea e oprită înainte de server), iar
//    butonul implicit al formularului (Enter fără JavaScript) e tot al transportului.
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_REZULTAT (JSON de ieșire). Nu scrie nimic în bază.
'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const email = process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro';
const parola = process.env.CMS_DEV_PAROLA || '';
const iesire = process.env.CMS_DEV_REZULTAT;
const gazdaLocala = new URL(baza).host;

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const blocate = new Set();
  await context.route('**/*', (ruta) => {
    const url = new URL(ruta.request().url());
    if (url.protocol === 'data:' || url.protocol === 'blob:' || url.protocol === 'about:' || url.host === gazdaLocala) return ruta.continue();
    blocate.add(url.host);
    return ruta.abort();
  });
  const page = await context.newPage();
  const erori = [];
  const dialoguri = [];
  page.on('pageerror', (e) => erori.push(String(e.message).split('\n')[0]));
  let acceptaDialog = false;
  page.on('dialog', (d) => { dialoguri.push(d.message()); if (acceptaDialog) d.accept(); else d.dismiss(); });
  await page.goto(baza + '/admin/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', parola);
  await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] [type="submit"], form[action="/admin/login"] button')]);
  await page.goto(baza + '/admin/orders', { waitUntil: 'load' });
  await page.waitForTimeout(200);

  const rez = { comenzi: 0, deschise: 0, bucati: { fanbox: 0, bt: 0, link_plata: 0, promo: 0, produse: 0 }, randuri: {}, probleme: [], blocate: [] };
  rez.randuri = await page.evaluate(() => ({
    awb: document.querySelectorAll('form[action$="/fan-awb"]').length,
    erp: document.querySelectorAll('form[action$="/erp-retry"]').length,
    precomanda: document.querySelectorAll('form[action$="/precomanda-elibereaza"]').length,
    op: document.querySelectorAll('form[action$="/confirma-plata-op"]').length,
    cos: document.querySelectorAll('form[action$="/delete"]').length,
    actiuni: document.querySelectorAll('[data-action="open-order-actions"]').length,
  }));
  rez.filtre = await page.evaluate(() => ({ livrare: !!document.querySelector('select[name="livrare"]'), erp: !!document.querySelector('select[name="erp"]'),
    trimiteErp: !!document.querySelector('#comenzi-masa button[value="erp_retry"]'), awb: !!document.querySelector('#comenzi-masa button[value="awb"]') }));
  const ochi = await page.$$('.view-order-btn');
  rez.comenzi = ochi.length;
  for (let i = 0; i < ochi.length; i++) {
    const buton = ochi[i];
    const numar = await buton.evaluate((b) => { try { return String(JSON.parse(b.dataset.order || '{}').order_number || ''); } catch (e) { return ''; } });
    await buton.scrollIntoViewIfNeeded();
    await buton.focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(120);
    const stare = await page.evaluate(() => {
      const s = document.getElementById('comanda-panou');
      const c = document.getElementById('order-modal-content');
      return {
        deschis: !!s && !s.hidden,
        titlu: (document.getElementById('comanda-panou-titlu') || {}).textContent || '',
        text: c ? c.innerText.length : 0,
        fanbox: !!(c && c.querySelector('[id^="order-fanbox-"]')),
        bt: !!(c && c.querySelector('[data-bt-panou]')),
        link: !!(c && c.querySelector('button[onclick^="sendPaymentLink"]')),
        promo: !!(c && c.querySelector('.order-promo-box')),
        produse: !!(c && c.querySelector('[id^="order-items-"]')),
      };
    });
    if (!stare.deschis) { rez.probleme.push(numar + ': panoul nu s-a deschis'); continue; }
    rez.deschise++;
    if (!stare.titlu.includes(numar) || stare.text < 50) rez.probleme.push(numar + ': panoul n-are comanda (' + stare.titlu + ', ' + stare.text + ' caractere)');
    if (stare.fanbox) rez.bucati.fanbox++;
    if (stare.bt) rez.bucati.bt++;
    if (stare.link) rez.bucati.link_plata++;
    if (stare.promo) rez.bucati.promo++;
    if (stare.produse) rez.bucati.produse++;
    await page.keyboard.press('Escape');
    await page.waitForTimeout(80);
    const inchis = await page.evaluate(() => ({ ascuns: document.getElementById('comanda-panou').hidden,
      focus: !!(document.activeElement && document.activeElement.classList.contains('view-order-btn')) }));
    if (!inchis.ascuns) rez.probleme.push(numar + ': Esc nu a închis panoul');
    if (!inchis.focus) rez.probleme.push(numar + ': după Esc focusul nu a revenit pe ochi');
  }

  // Bara acțiunilor în masă și scurtăturile.
  await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
  await page.keyboard.press('c');
  await page.waitForTimeout(100);
  if (!(await page.$('#comenzi-tasta-nota:not([hidden])'))) rez.probleme.push('C fără bife: nicio notă');
  const bifa = await page.$('input.adm-tabel__rand:not(:disabled)');
  await bifa.check();
  if (!(await page.$('#comenzi-masa:not([hidden])'))) rez.probleme.push('bara acțiunilor în masă nu apare la o bifă');
  await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
  const areFan = !!(await page.$('#comenzi-masa [data-tasta-comenzi="a"]'));
  rez.areFan = areFan;
  for (const [tasta, asteptat] of [...(areFan ? [['a', 'AWB']] : []), ['c', 'Confirmi']]) {
    const inainte = dialoguri.length;
    await page.keyboard.press(tasta);
    await page.waitForTimeout(150);
    const mesaj = dialoguri.slice(inainte).join(' | ');
    if (!mesaj.includes(asteptat)) rez.probleme.push('tasta ' + tasta.toUpperCase() + ': aștepta confirmarea „' + asteptat + '”, a primit „' + mesaj + '”');
  }
  if (!areFan && (await page.$('[data-tasta-comenzi="a"]'))) rez.probleme.push('A există fără livrarea FAN');
  await page.keyboard.press('p');
  await page.waitForTimeout(150);
  if (!(await page.$('iframe[aria-hidden="true"]'))) rez.probleme.push('P nu a pregătit foaia de tipărit');
  await page.waitForTimeout(1100);
  await bifa.focus();
  await page.keyboard.press('Escape');
  await page.waitForTimeout(100);
  if (await page.$('#comenzi-masa:not([hidden])') || await bifa.isChecked()) rez.probleme.push('Esc nu a golit bifele');
  // „Schimbă statusul” fără status ales: nimic trimis, doar o atenționare.
  await bifa.check();
  const inainteStatus = dialoguri.length;
  await page.click('#comenzi-masa button[name="bulk_action"][value="status"]:not([data-bulk-status])');
  await page.waitForTimeout(200);
  if (!dialoguri.slice(inainteStatus).join(' ').includes('Selectează statusul')) rez.probleme.push('„Schimbă statusul” fără status nu a cerut statusul');
  if (!page.url().endsWith('/admin/orders')) rez.probleme.push('„Schimbă statusul” fără status a plecat de pe pagină: ' + page.url());

  // „?”: scurtăturile paginii.
  await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
  await page.keyboard.press('?');
  await page.waitForTimeout(150);
  rez.scurtaturi = await page.evaluate(() => Array.from(document.querySelectorAll('#ax-taste-pagina kbd')).map((k) => k.textContent.trim()));
  await page.keyboard.press('Escape');

  // Enter în câmpul transportului trimite „Corectează transportul” cu valoarea scrisă (cererea e oprită înainte de server,
  // deci nimic nu se schimbă); fără JavaScript, butonul implicit al formularului (primul) e tot cel al transportului.
  rez.implicit = await page.evaluate(() => {
    const f = document.getElementById('comenzi-masa-formular');
    const primul = f && Array.from(document.querySelectorAll('button[type="submit"], input[type="submit"]')).find((b) => b.form === f);
    return primul ? primul.name + '=' + primul.value : '';
  });
  if (rez.implicit !== 'bulk_action=shipping') rez.probleme.push('butonul implicit al barei (Enter fără JavaScript) e „' + rez.implicit + '”, nu transportul');
  if (!(await bifa.isChecked())) await bifa.check();
  let trimis = null;
  await page.route('**/admin/orders/bulk', (r) => { trimis = r.request().postData() || ''; r.abort(); });
  acceptaDialog = true;
  await page.fill('#comenzi-masa input[name="bulk_shipping_value"]', '30');
  await page.focus('#comenzi-masa input[name="bulk_shipping_value"]');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(800);
  acceptaDialog = false;
  await page.unroute('**/admin/orders/bulk');
  const date = new URLSearchParams(trimis || '');
  rez.enterTransport = trimis === null ? null : { actiune: date.get('bulk_action'), valoare: date.get('bulk_shipping_value'), comenzi: date.getAll('order_ids[]').length };
  if (!rez.enterTransport || rez.enterTransport.actiune !== 'shipping' || rez.enterTransport.valoare !== '30' || rez.enterTransport.comenzi < 1) {
    rez.probleme.push('Enter în „Transport (lei)” a trimis ' + JSON.stringify(rez.enterTransport) + ', nu bulk_action=shipping cu 30');
  }

  rez.probleme.push(...erori.map((e) => 'eroare JavaScript: ' + e));
  rez.blocate = [...blocate].sort();
  await browser.close();
  fs.writeFileSync(iesire, JSON.stringify(rez));
})().catch((e) => {
  console.error('EROARE ' + (e && e.message ? e.message : e));
  process.exit(1);
});
