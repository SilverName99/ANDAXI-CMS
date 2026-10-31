// Plimbarea cu tastatura prin admin (faza D, WP-31; planul: „keyboard walk”). Pentru fiecare pagină dată, ca administrator
// general logat:
//  - apasă Tab de la începutul paginii, element cu element, până se întoarce la primul (sau până la o limită), și verifică
//    la fiecare că focusul se vede: un contur (outline) nenul sau o umbră (box-shadow) pe elementul ales;
//  - verifică scurtăturile declarate (implicit „?” deschide lista scurtăturilor, Esc o închide) și că scurtăturile
//    pluginurilor ascunse nu există (lista „absente”);
//  - dacă pagina are un panou lateral (data-adm-panou), îl deschide, verifică că Esc îl închide și că focusul revine pe
//    butonul care l-a deschis;
//  - pe pagina de probă a componentelor (/admin/componente): bara acțiunilor în masă apare la o bifă și dispare la Esc,
//    „/” mută focusul în căutare, iar un filtru ales rămâne după o altă pagină și Înapoi (filtrele stau în adresă).
// Un editor de cod (CodeMirror) ține Tab-ul pentru indentare: plimbarea sare peste el și îl notează (INFO), nu e o eroare.
// Un câmp de dată și oră primește mai multe Tab-uri (ziua, luna, anul, ora…): același element de cel mult 12 ori la rând.
//
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_PLIMBARE (JSON de intrare: {"pagini": ["/admin", …],
// "scurtaturi": [{"tasta": "?", "deschide": "<selector>", "inchide": "Escape"}], "absente": ["<selector>", …]}),
// CMS_DEV_REZULTAT (JSON de ieșire), CMS_DEV_PLIMBARE_MAX (câte Tab-uri cel mult pe pagină, implicit 900). Playwright din
// NODE_PATH. Orice cerere spre alt server e blocată și raportată. O rulează tools/dev/contract-admin.php --plimbare.

'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const email = process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro';
const parola = process.env.CMS_DEV_PAROLA || '';
const intrare = JSON.parse(fs.readFileSync(process.env.CMS_DEV_PLIMBARE, 'utf8'));
const iesire = process.env.CMS_DEV_REZULTAT;
const maxTab = parseInt(process.env.CMS_DEV_PLIMBARE_MAX || '900', 10);
const gazdaLocala = new URL(baza).host;

/** Elementul cu focus: o descriere scurtă și dacă focusul se vede. */
function elementulCuFocus() {
  const el = document.activeElement;
  if (!el || el === document.body || el === document.documentElement) return null;
  const s = getComputedStyle(el);
  const contur = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
  const umbra = s.boxShadow && s.boxShadow !== 'none';
  const cm = el.closest('.CodeMirror');
  let descriere = el.tagName.toLowerCase();
  if (el.id) descriere += '#' + el.id;
  else if (el.getAttribute('name')) descriere += '[name="' + el.getAttribute('name') + '"]';
  else if (el.className && typeof el.className === 'string') descriere += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
  const text = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  if (text) descriere += ' „' + text + '”';
  if (!el.dataset.plimbareId) el.dataset.plimbareId = String(Math.random()).slice(2);
  return { id: el.dataset.plimbareId, descriere, vizibil: contur || umbra, codemirror: !!cm };
}

/** Sare peste un editor CodeMirror (Tab scrie în el): focusul pe următorul element după el. */
function sariPesteCodeMirror() {
  const cm = document.activeElement && document.activeElement.closest('.CodeMirror');
  if (!cm) return false;
  const toate = Array.from(document.querySelectorAll('a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex]'))
    .filter((e) => e.tabIndex >= 0 && !e.disabled && e.offsetParent !== null && !e.closest('.CodeMirror'));
  const urm = toate.find((e) => cm.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING);
  if (urm) { urm.focus(); return true; }
  document.activeElement.blur();
  return true;
}

async function plimba(page, cale) {
  const rez = { cale, status: 0, tabulari: 0, faraFocusVizibil: [], info: [], erori: [] };
  const asculta = (e) => rez.erori.push(String((e && e.message) || e).split('\n')[0]);
  page.on('pageerror', asculta);
  try {
    const r = await page.goto(baza + cale, { waitUntil: 'load', timeout: 45000 });
    rez.status = r ? r.status() : 0;
    await page.waitForTimeout(150);
    await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); window.scrollTo(0, 0); });

    // 1. Tab prin toată pagina.
    const vazute = new Set();
    let primul = null;
    let faraSchimbare = 0;
    let ultimul = null;
    for (let i = 0; i < maxTab; i++) {
      await page.keyboard.press('Tab');
      let f = await page.evaluate(elementulCuFocus);
      if (f && f.codemirror) {
        rez.info.push('editor de cod (CodeMirror) sărit: Tab scrie în el');
        await page.evaluate(sariPesteCodeMirror);
        f = await page.evaluate(elementulCuFocus);
      }
      if (!f) { if (primul) break; continue; }
      if (primul === null) primul = f.id;
      else if (f.id === primul) break;
      if (ultimul === f.id) { if (++faraSchimbare > 12) { rez.info.push('focusul a rămas pe ' + f.descriere); break; } } else faraSchimbare = 0;
      ultimul = f.id;
      if (vazute.has(f.id)) continue;
      vazute.add(f.id);
      rez.tabulari++;
      if (!f.vizibil && rez.faraFocusVizibil.length < 25) rez.faraFocusVizibil.push(f.descriere);
      if (i === maxTab - 1) rez.info.push('limita de ' + maxTab + ' Tab-uri atinsă');
    }

    // 2. Scurtăturile declarate (din afara oricărui câmp) și cele care nu trebuie să existe.
    for (const sc of intrare.scurtaturi || []) {
      await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
      await page.keyboard.press(sc.tasta);
      await page.waitForTimeout(150);
      const deschis = await page.$(sc.deschide);
      if (!deschis) { rez.erori.push('scurtătura „' + sc.tasta + '” nu a deschis ' + sc.deschide); continue; }
      if (sc.inchide) {
        await page.keyboard.press(sc.inchide);
        await page.waitForTimeout(150);
        if (await page.$(sc.deschide)) rez.erori.push(sc.inchide + ' nu a închis ' + sc.deschide);
      }
    }
    for (const sel of intrare.absente || []) {
      if (await page.$(sel)) rez.erori.push('există, deși pluginul lui e ascuns: ' + sel);
    }

    // 3. Panoul lateral: Esc îl închide, focusul revine pe buton.
    const buton = await page.$('[data-adm-panou]');
    if (buton) {
      await buton.focus();
      await page.keyboard.press('Enter');
      await page.waitForTimeout(400);
      if (!(await page.$('.adm-sertar:not([hidden])'))) rez.erori.push('panoul lateral nu s-a deschis cu Enter');
      await page.keyboard.press('Escape');
      await page.waitForTimeout(150);
      if (await page.$('.adm-sertar:not([hidden])')) rez.erori.push('Esc nu a închis panoul lateral');
      const inapoi = await page.evaluate(() => !!(document.activeElement && document.activeElement.hasAttribute('data-adm-panou')));
      if (!inapoi) rez.erori.push('după Esc focusul nu a revenit pe butonul panoului');
    }

    // 4. Pagina de probă: bara acțiunilor în masă, „/”, filtrele la Înapoi.
    if (cale === '/admin/componente') {
      await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
      await page.keyboard.press('/');
      const cautare = await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-adm-tasta'));
      if (cautare !== '/') rez.erori.push('„/” nu a mutat focusul în căutare');
      await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
      const bifa = await page.$('input.adm-tabel__rand');
      await bifa.check();
      if (!(await page.$('.adm-masa:not([hidden])'))) rez.erori.push('bara acțiunilor în masă nu apare la o bifă');
      await bifa.focus();
      await page.keyboard.press('Escape');
      await page.waitForTimeout(100);
      if (await page.$('.adm-masa:not([hidden])') || await bifa.isChecked()) rez.erori.push('Esc nu a golit alegerea');
      await Promise.all([page.waitForURL(/plata=card/), page.selectOption('select[name="plata"]', 'card')]);
      await page.goto(baza + '/admin', { waitUntil: 'load' });
      await page.goBack({ waitUntil: 'load' });
      const ramas = await page.evaluate(() => [location.search, (document.querySelector('select[name="plata"]') || {}).value]);
      if (!/plata=card/.test(ramas[0]) || ramas[1] !== 'card') rez.erori.push('filtrul nu a rămas după Înapoi (' + ramas.join(' ') + ')');
    }
  } catch (e) {
    rez.erori.push('plimbare: ' + String((e && e.message) || e).split('\n')[0]);
  }
  page.off('pageerror', asculta);
  return rez;
}

(async () => {
  if (!parola) throw new Error('Lipsește CMS_DEV_PAROLA.');
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const blocate = new Set();
  await context.route('**/*', (ruta) => {
    const url = new URL(ruta.request().url());
    if (url.protocol === 'data:' || url.protocol === 'blob:' || url.host === gazdaLocala) return ruta.continue();
    blocate.add(url.host);
    return ruta.abort();
  });
  const page = await context.newPage();
  page.on('dialog', (d) => d.dismiss());
  await page.goto(baza + '/admin/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', parola);
  await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] button, form[action="/admin/login"] [type="submit"]')]);
  const pagini = [];
  for (const cale of intrare.pagini || []) {
    pagini.push(await plimba(page, cale));
  }
  await browser.close();
  fs.writeFileSync(iesire, JSON.stringify({ pagini, blocate: [...blocate].sort() }));
})().catch((e) => {
  console.error('EROARE ' + (e && e.message ? e.message : e));
  process.exit(1);
});
