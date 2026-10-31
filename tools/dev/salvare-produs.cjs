// Salvarea neatinsă a produselor din editorul refăcut (faza D, WP-34; planul: „saving an untouched product changes no
// column”, „Ctrl+S and the unsaved counter in the keyboard walk”). Ca administrator general logat, pe /admin/products, pentru
// fiecare id din CMS_DEV_PRODUSE: deschide produsul în editor, verifică „0 modificări nesalvate”, apasă Ctrl+S și așteaptă
// întoarcerea în listă (salvarea e a formularului, cu ce a pus în el scriptul paginii). Pe primul produs, înainte: o literă
// scrisă în nume arată „1 modificare nesalvată”, Esc întreabă (refuzat: editorul rămâne deschis), ștergerea literei readuce 0.
// Cu CMS_DEV_VECHI=1 (editorul de dinainte, pe codul etichetei admin-inainte-b): fără contor și fără Ctrl+S, salvarea cu butonul
// „Salvează produsul” al formularului; testul compară apoi ce scriu cele două editoare.
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_PRODUSE (id-uri cu virgulă), CMS_DEV_REZULTAT, CMS_DEV_VECHI.
'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const ids = String(process.env.CMS_DEV_PRODUSE || '').split(',').filter(Boolean).map(Number);
const vechi = process.env.CMS_DEV_VECHI === '1';

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const gazda = new URL(baza).host;
  const blocate = new Set();
  await context.route('**/*', (r) => {
    const u = new URL(r.request().url());
    if (u.protocol === 'data:' || u.protocol === 'blob:' || u.protocol === 'about:' || u.host === gazda) return r.continue();
    blocate.add(u.host);
    return r.abort();
  });
  const page = await context.newPage();
  const erori = [];
  const dialoguri = [];
  page.on('pageerror', (e) => erori.push(String(e.message).split('\n')[0]));
  page.on('dialog', (d) => { dialoguri.push(d.message()); d.dismiss(); });
  await page.goto(baza + '/admin/login');
  await page.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
  await page.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
  await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] [type="submit"], form[action="/admin/login"] button')]);
  const rez = { salvate: [], probleme: [], blocate: [], sectiuni: [] };
  for (let n = 0; n < ids.length; n++) {
    const id = ids[n];
    await page.goto(baza + '/admin/products', { waitUntil: 'load' });
    const deschis = await page.evaluate((idCautat) => {
      const b = Array.from(document.querySelectorAll('.edit-product-btn')).find((x) => { try { return Number(JSON.parse(x.dataset.product).id) === idCautat; } catch (e) { return false; } });
      if (!b) return false;
      b.click();
      return true;
    }, id);
    if (!deschis) { rez.probleme.push(id + ': produsul nu e în listă'); continue; }
    await page.waitForTimeout(500);
    if (vechi) {
      const ok = await Promise.all([page.waitForNavigation({ timeout: 20000 }), page.click('#product-form button[type="submit"]')])
        .then(() => true, (e) => { rez.probleme.push(id + ': salvarea nu a mers (' + e.message.split('\n')[0] + ')'); return false; });
      if (ok && new URL(page.url()).pathname === '/admin/products') rez.salvate.push(id);
      else if (ok) rez.probleme.push(id + ': după salvare, ' + page.url());
      continue;
    }
    if (n === 0) {
      rez.sectiuni = await page.evaluate(() => Array.from(document.querySelectorAll('#product-modal [data-produs-sectiune]')).map((a) => a.getAttribute('data-produs-sectiune')));
    }
    const zero = await page.evaluate(() => document.getElementById('produs-nesalvate').hidden);
    if (!zero) rez.probleme.push(id + ': editorul arată modificări nesalvate imediat după deschidere: ' + await page.textContent('#produs-nesalvate'));
    if (n === 0) {
      await page.focus('#p_name');
      await page.keyboard.press('End');
      await page.keyboard.type('x');
      await page.waitForTimeout(100);
      const text = (await page.textContent('#produs-nesalvate')).trim();
      if (text !== '1 modificare nesalvată') rez.probleme.push('după o literă în nume: „' + text + '”');
      const inainte = dialoguri.length;
      await page.keyboard.press('Escape');
      await page.waitForTimeout(100);
      if (!dialoguri.slice(inainte).join(' ').includes('nesalvat')) rez.probleme.push('Esc cu o modificare nu a întrebat');
      if (!(await page.$('#product-modal.open'))) rez.probleme.push('Esc refuzat a închis totuși editorul');
      await page.focus('#p_name');
      await page.keyboard.press('End');
      await page.keyboard.press('Backspace');
      await page.waitForTimeout(100);
      if (!(await page.evaluate(() => document.getElementById('produs-nesalvate').hidden))) rez.probleme.push('după ștergerea literei, contorul nu s-a întors la 0');
    }
    await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
    await Promise.all([page.waitForNavigation({ timeout: 20000 }), page.keyboard.press('Control+s')]).catch((e) => rez.probleme.push(id + ': Ctrl+S nu a salvat (' + e.message.split('\n')[0] + ')'));
    const succes = await page.$('#ax-mesaj-succes');
    if (!succes) rez.probleme.push(id + ': după Ctrl+S nu apare mesajul de reușită (' + page.url() + ')');
    else rez.salvate.push(id);
  }
  rez.probleme.push(...erori.map((e) => 'eroare JavaScript: ' + e));
  rez.blocate = [...blocate];
  await browser.close();
  fs.writeFileSync(process.env.CMS_DEV_REZULTAT, JSON.stringify(rez));
})().catch((e) => { console.error('EROARE ' + (e && e.message ? e.message : e)); process.exit(1); });
