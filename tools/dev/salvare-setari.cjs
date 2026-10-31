// Formularele paginilor unite, împărțite sau puse pe tab-uri în WP-35 (IA-01 … IA-10) trimit, la salvare, exact cererea de
// dinainte. Ca administrator general logat, pentru fiecare caz: deschide pagina (codul etichetei admin-inainte-b cu
// CMS_DEV_VECHI=1, altfel codul curent), alege tab-ul (pe codul nou) și trimite formularul cu butonul lui, prin requestSubmit
// (rulează scripturile paginii la trimitere: de exemplu codurile Google în base64, tab-ul livrării). Cererea POST e oprită
// înainte de server (nimic nu se salvează); se scriu adresa și perechile câmp = valoare. Testul (test-admin-faza-d.php, D)
// compară cele două coduri: aceleași perechi (ordinea câmpurilor nu contează: un câmp cu form= vine la sfârșitul cererii).
// Plus constructorul de emailuri: deschide o campanie și o salvează de două ori (cererea trece; pe ambele coduri), ca testul să
// compare JSON-ul blocurilor.
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_REZULTAT, CMS_DEV_VECHI, CMS_DEV_CAMPANIE (id).
'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const vechi = process.env.CMS_DEV_VECHI === '1';
const campanie = Number(process.env.CMS_DEV_CAMPANIE || 0);

// [nume, adresa pe codul vechi, adresa pe codul nou, tab-ul de ales pe codul nou (sau ''), formularul, textul butonului]
const cazuri = [
  ['livrare: setările de livrare', '/admin/settings/shipping?tab=delivery-settings', '/admin/settings/shipping?tab=livrare', 'livrare',
    'form[action="/admin/settings/shipping"]', 'Salvează setările de livrare'],
  ['livrare: contul FAN (tab-ul Curier FAN)', '/admin/settings/shipping?tab=delivery-settings', '/admin/settings/shipping?tab=fan', 'fan',
    'form[action="/admin/settings/shipping"]', 'Salvează contul FAN și setările de livrare', 'Salvează setările de livrare'],
  ['plăți: formularul comun (tab-ul ordinului de plată)', '/admin/settings/payments', '/admin/settings/payments?tab=op', 'op',
    'form[action="/admin/settings/payments"]', 'Salvează setările de plată'],
  ['plăți: formularul comun, salvat din tab-ul Stripe', '/admin/settings/payments', '/admin/settings/payments?tab=stripe', 'stripe',
    'form[action="/admin/settings/payments"]', 'Salvează setările de plată'],
  ['plăți: BT', '/admin/settings/payments?tab=bt', '/admin/settings/payments?tab=bt', '', 'form[action="/admin/settings/payments/bt"]', ''],
  ['Google (pagina veche → tab-ul SEO și Google)', '/admin/settings/google', '/admin/settings/store?tab=seo', 'seo',
    'form[action="/admin/settings/google"]', 'Salvează setările Google'],
  ['setări: SEO global', '/admin/settings/store', '/admin/settings/store?tab=seo', 'seo', 'form[action="/admin/settings/store"]', 'Salvează SEO global'],
  ['setări: numerotarea comenzilor', '/admin/settings/store', '/admin/settings/store?tab=magazin', 'magazin', 'form[action="/admin/settings/store"]', 'Salvează numerotarea'],
  ['setări: TVA pe produse', '/admin/settings/store', '/admin/settings/store?tab=magazin', 'magazin', 'form[action="/admin/settings/store"]', 'Schimbă TVA pe produse'],
  ['setări: chat live', '/admin/settings/store', '/admin/settings/store?tab=chat', 'chat', 'form[action="/admin/settings/store"]', 'Salvează setările de chat'],
  ['setări: cache', '/admin/settings/store', '/admin/settings/store?tab=viteza', 'viteza', 'form[action="/admin/settings/store"]', 'Salvează setările de caching'],
  ['setări: mentenanța', '/admin/settings/store', '/admin/settings/store?tab=general', 'general', 'form[action="/admin/settings/store"]', 'Salvează setările de mentenanță'],
  ['fidelitate: programul', '/admin/users/points', '/admin/users/points?tab=program', 'program', '#loyalty-settings-form', ''],
  ['fidelitate: o regulă promo', '/admin/users/points', '/admin/users/points?tab=promo', 'promo', 'form[action="/admin/users/points"]', 'Adaugă regulă promo'],
  ['emailuri: abandonul coșului (Automatizări)', '/admin/emails/automation', '/admin/emails/automation', '', 'form[action="/admin/emails"]', ''],
  ['emailuri: setările de trimitere', '/admin/emails/sender', '/admin/emails/sender', '', 'form[action="/admin/emails"]', ''],
];

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const gazda = new URL(baza).host;
  const blocate = new Set();
  let prinsa = null;
  await context.route('**/*', (r) => {
    const req = r.request();
    const u = new URL(req.url());
    if (!(u.protocol === 'data:' || u.protocol === 'blob:' || u.protocol === 'about:' || u.host === gazda)) {
      blocate.add(u.host);
      return r.abort();
    }
    if (req.method() === 'POST' && u.pathname !== '/admin/login' && u.pathname !== '/admin/emails/builder') {
      prinsa = { adresa: u.pathname + u.search, corp: req.postData() || '', tip: req.headers()['content-type'] || '' };
      return r.abort();
    }
    return r.continue();
  });
  const page = await context.newPage();
  const erori = [];
  page.on('pageerror', (e) => erori.push(String(e.message).split('\n')[0]));
  page.on('dialog', (d) => d.accept());
  await page.goto(baza + '/admin/login');
  await page.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
  await page.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
  await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] [type="submit"], form[action="/admin/login"] button')]);
  const rez = { cazuri: {}, probleme: [], blocate: [], builder: [] };
  for (const [nume, adresaVeche, adresaNoua, tab, formular, butonNou, butonVechi] of cazuri) {
    const buton = vechi && butonVechi !== undefined ? butonVechi : butonNou;
    prinsa = null;
    await page.goto(baza + (vechi ? adresaVeche : adresaNoua), { waitUntil: 'load' });
    if (!vechi && tab) {
      const activ = await page.evaluate((t) => {
        const a = document.querySelector('[data-adm-taburi-panouri] a[data-adm-tab="' + t + '"]');
        if (a) a.click();
        const p = document.querySelector('[data-adm-tab-panou="' + t + '"]');
        return !!p && !p.hidden;
      }, tab);
      if (!activ) { rez.probleme.push(nume + ': tab-ul „' + tab + '” nu s-a deschis'); continue; }
    }
    const trimis = await page.evaluate(([sel, text]) => {
      const forms = Array.from(document.querySelectorAll(sel));
      for (const f of forms) {
        const butoane = Array.from(document.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'))
          .filter((b) => b.form === f && (text === '' || b.textContent.trim() === text));
        if (butoane.length) {
          // Câmpurile obligatorii goale (o regulă promo nouă) primesc aceeași valoare pe ambele coduri, ca validarea să lase cererea.
          Array.from(f.elements).forEach((c) => {
            if (c.required && !c.value && c.type !== 'file') c.value = c.type === 'date' ? '2026-12-01' : (c.type === 'number' ? '2' : (c.tagName === 'SELECT' ? (c.options[1] || c.options[0] || {}).value || '' : 'proba'));
          });
          f.requestSubmit(butoane[0]);
          return true;
        }
      }
      return false;
    }, [formular, buton]);
    if (!trimis) { rez.probleme.push(nume + ': formularul sau butonul lui lipsește'); continue; }
    for (let i = 0; i < 40 && prinsa === null; i++) await page.waitForTimeout(100);
    if (prinsa === null) { rez.probleme.push(nume + ': nicio cerere trimisă (validarea formularului?)'); continue; }
    rez.cazuri[nume] = prinsa;
  }
  // Constructorul de emailuri: salvarea unei campanii neatinse, de două ori (cererea ajunge la server: testul compară JSON-ul).
  if (campanie > 0) {
    for (let n = 0; n < 2; n++) {
      await page.goto(baza + '/admin/emails/builder?type=campaign&id=' + campanie, { waitUntil: 'load' });
      await page.waitForTimeout(400);
      const raspuns = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/emails/builder' && r.request().method() === 'POST', { timeout: 15000 });
      await page.evaluate(() => document.getElementById('btn-save').click());
      const r = await raspuns.then((x) => x.status(), () => 0);
      rez.builder.push(r);
      await page.waitForTimeout(600);
    }
  }
  // „Oferte BBD” (IA-05): „Deschide produsul” duce la editorul produsului, deschis (/admin/products#editeaza-<id>).
  if (!vechi) {
    await page.goto(baza + '/admin/products/oferte-bbd', { waitUntil: 'load' });
    const link = await page.evaluate(() => (document.querySelector('#oferte-bbd a[href^="/admin/products#editeaza-"]') || {}).getAttribute?.call(document.querySelector('#oferte-bbd a[href^="/admin/products#editeaza-"]'), 'href') || '');
    if (link) {
      await page.goto(baza + link, { waitUntil: 'load' });
      await page.waitForTimeout(800);
      rez.bbd = await page.evaluate(() => {
        const m = document.getElementById('product-modal');
        return { deschis: !!m && m.classList.contains('open'),
          bbd: !!document.querySelector('#product-modal [data-produs-sectiune="produs-bbd"]') };
      });
    } else {
      rez.bbd = { deschis: false, bbd: false, link: '' };
    }
  }
  rez.probleme.push(...erori.map((e) => 'eroare JavaScript: ' + e));
  rez.blocate = Array.from(blocate);
  fs.writeFileSync(process.env.CMS_DEV_REZULTAT || '/dev/stdout', JSON.stringify(rez, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
