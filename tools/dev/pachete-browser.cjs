// Pluginul pachete_produse în Chromium (tools/dev/test-calcule-bv.php, partea C5): pe codul Biovitality și pe codul comun, pe
// aceeași bază, ca un vizitator, la 1366 px și la 390 px: produsele date în coș, pagina coșului (caseta „Face parte din …
// pachete”: stilurile calculate și locul ei față de linia coșului), coșul flotant deschis (aceeași casetă, stilurile ei) și
// „Schimbă în pachet” din coșul flotant (ce rămâne în coș). Variabile: CMS_DEV_URL_VECHI, CMS_DEV_URL_NOU, CMS_DEV_PRODUSE
// (id-urile produselor de pus în coș, „id:cantitate,…”). Scrie pe stdout un JSON: {vechi: {...}, nou: {...}} pe fiecare lățime.
'use strict';

const { chromium } = require('playwright');

const adrese = { vechi: process.env.CMS_DEV_URL_VECHI || '', nou: process.env.CMS_DEV_URL_NOU || '' };
const produse = String(process.env.CMS_DEV_PRODUSE || '').split(',').filter(Boolean).map((p) => p.split(':').map(Number));

// Proprietățile comparate ale fiecărui element al casetei (ce se vede: mărimi, culori, așezare).
const PROPRIETATI = ['display', 'grid-template-columns', 'grid-column', 'grid-row', 'width', 'height', 'max-width', 'margin-top', 'padding',
  'border-top', 'border-radius', 'background-color', 'color', 'font-family', 'font-size', 'font-weight', 'text-transform', 'gap', 'flex-wrap',
  'white-space', 'overflow', 'text-overflow', 'object-fit'];
const SELECTORI = ['.cart-bundles', '.cart-bundles__title', '.cart-bundle', '.cart-bundle__img', '.cart-bundle__info', '.cart-bundle__name',
  '.cart-bundle__price', '.cart-bundle__actions', '.cart-bundle__btn', '.cart-bundle__btn--main'];
const SELECTORI_FLOTANT = SELECTORI.map((s) => s.replace('.cart-', '.fc-'));

async function stiluri(pagina, selectori) {
  return pagina.evaluate(([sel, prop]) => {
    const rezultat = {};
    for (const s of sel) {
      const el = document.querySelector(s);
      if (!el) {
        rezultat[s] = null;
        continue;
      }
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      rezultat[s] = { stil: Object.fromEntries(prop.map((p) => [p, cs.getPropertyValue(p)])), latime: Math.round(r.width), inaltime: Math.round(r.height),
        text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 200) };
    }
    return rezultat;
  }, [selectori, PROPRIETATI]);
}

async function treceCodul(browser, baza, latime) {
  const context = await browser.newContext({ viewport: { width: latime, height: 900 }, deviceScaleFactor: 1,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' });
  await context.route('**/*', (ruta) => (ruta.request().url().startsWith(baza) || ruta.request().url().startsWith('data:') ? ruta.continue() : ruta.abort()));
  const pagina = await context.newPage();
  const eroriJs = [];
  pagina.on('pageerror', (e) => eroriJs.push(String(e.message || e).slice(0, 200)));
  await pagina.goto(baza + '/cos', { waitUntil: 'domcontentloaded' });
  for (const [id, cantitate] of produse) {
    await pagina.evaluate(async ([i, q]) => {
      const date = new URLSearchParams({ quantity: String(q) });
      await fetch('/cos/adauga/' + i, { method: 'POST', body: date, credentials: 'same-origin', redirect: 'manual' });
    }, [id, cantitate]);
  }
  await pagina.goto(baza + '/cos', { waitUntil: 'load' });
  const rezultat = { pagina: await stiluri(pagina, SELECTORI), casete: await pagina.locator('.cart-bundles').count() };
  // Caseta stă sub cantitate, pe linia ei: locul față de linia coșului și de celula cantității.
  rezultat.asezare = await pagina.evaluate(() => {
    const caseta = document.querySelector('.cart-bundles');
    const linie = caseta ? caseta.closest('.bv-cart-v2__item') : null;
    const cantitate = linie ? linie.querySelector('.bv-cart-v2__qty-cell') : null;
    if (!caseta || !linie || !cantitate) {
      return null;
    }
    const c = caseta.getBoundingClientRect();
    const l = linie.getBoundingClientRect();
    const q = cantitate.getBoundingClientRect();
    return { stanga: Math.round(c.left - l.left), sus: Math.round(c.top - l.top), subCantitate: c.top >= q.bottom - 1, latime: Math.round(c.width) };
  });
  // Coșul flotant: deschis din buton, după ce și-a cerut sumarul.
  const buton = pagina.locator('.floating-cart__trigger');
  rezultat.flotant = null;
  if (await buton.count() > 0) {
    await buton.first().click();
    await pagina.waitForSelector('.fc-bundles', { timeout: 8000 }).catch(() => null);
    await pagina.waitForTimeout(300);
    rezultat.flotant = { stiluri: await stiluri(pagina, SELECTORI_FLOTANT), casete: await pagina.locator('.fc-bundles').count() };
    // „Schimbă în pachet” pe prima casetă: ce rămâne în coș.
    const schimba = pagina.locator('.fc-bundle__btn--main').first();
    if (await schimba.count() > 0) {
      const raspuns = pagina.waitForResponse((r) => r.url().includes('/api/cart/swap-bundle'), { timeout: 8000 }).catch(() => null);
      await schimba.click();
      const r = await raspuns;
      await pagina.waitForTimeout(400);
      rezultat.flotant.schimbare = r ? r.status() : null;
      rezultat.flotant.dupa = await pagina.evaluate(() => Array.from(document.querySelectorAll('.floating-cart__item'))
        .map((li) => (li.querySelector('.floating-cart__meta a')?.textContent || '').trim() + ' × ' + (li.querySelector('.floating-cart__qty span, .floating-cart__qty input')?.textContent
          || li.querySelector('.floating-cart__qty input')?.value || '').trim()));
      rezultat.flotant.toast = await pagina.evaluate(() => (document.querySelector('.floating-cart__toast')?.textContent || '').trim());
    }
  }
  rezultat.eroriJs = eroriJs;
  rezultat.derulareLaterala = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  await context.close();
  return rezultat;
}

(async () => {
  const browser = await chromium.launch();
  const iesire = {};
  try {
    for (const latime of [1366, 390]) {
      iesire[latime] = {};
      for (const [parte, baza] of Object.entries(adrese)) {
        iesire[latime][parte] = await treceCodul(browser, baza, latime);
      }
    }
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(iesire) + '\n');
})().catch((e) => {
  process.stdout.write(JSON.stringify({ eroare: String(e && e.message ? e.message : e).slice(0, 500) }) + '\n');
  process.exit(1);
});
