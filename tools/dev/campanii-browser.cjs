// Pluginul campanii în Chromium (tools/dev/test-campanii.php, partea D), pe desktop (1366 px) și pe telefon (390 px), cu datele
// demo și campania inventată a testului: un vizitator nou care intră pe o pagină de produs ajunge pe pagina campaniei, cu butonul
// „Înapoi în site-ul principal” în stânga sus; butonul îl duce la produsul cerut (fără o a doua trimitere), unde vede insigna și
// bannerul; magazinul are insigna pe carduri; bannerul lipit rămâne sus la derulare; nicio pagină nu derulează în lateral. Apoi,
// ca administrator, editorul campaniei: previzualizarea bannerului urmează textul, culoarea, fontul și mărimea fără salvare.
// Variabile: CMS_DEV_URL, CMS_DEV_PRODUS (slug-ul unui produs din campanie), CMS_DEV_CAMPANIE (id), CMS_DEV_ADMIN_EMAIL,
// CMS_DEV_PAROLA, CMS_DEV_CAPTURI (dosarul capturilor). Scrie pe stdout un JSON cu rezultatele.
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const produs = process.env.CMS_DEV_PRODUS || '';
const campanie = Number(process.env.CMS_DEV_CAMPANIE || 0);
const capturi = process.env.CMS_DEV_CAPTURI || '';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });

// Doar serverul de dezvoltare: orice altă cerere (fonturi Google, CDN-uri) se oprește, ca pagina să nu plece în afară.
async function blocheazaExterne(context) {
  await context.route('**/*', (ruta) => {
    const adresa = ruta.request().url();
    return adresa.startsWith(baza) || adresa.startsWith('data:') ? ruta.continue() : ruta.abort();
  });
}

async function faraDerulareLaterala(pagina) {
  return pagina.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
}

async function vizitator(browser, latime, eticheta) {
  // Un browser obișnuit: „HeadlessChrome” din User-Agent-ul implicit al Playwright e tratat ca robot (nu e trimis la campanie).
  const context = await browser.newContext({ viewport: { width: latime, height: latime < 500 ? 844 : 900 }, isMobile: latime < 500,
    hasTouch: latime < 500, deviceScaleFactor: 1, userAgent: latime < 500
      ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36'
      : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' });
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));

  const raspuns = await pagina.goto(baza + '/produs/' + encodeURIComponent(produs) + '?utm_source=test', { waitUntil: 'load' });
  const adresa = new URL(pagina.url());
  nota(eticheta + ': un vizitator nou ajunge pe pagina campaniei', adresa.pathname.startsWith('/campanie/') && raspuns && raspuns.status() === 200,
    pagina.url());
  const buton = pagina.locator('.campanie-inapoi__buton');
  const cutie = await buton.boundingBox();
  nota(eticheta + ': butonul „Înapoi în site-ul principal” e în stânga sus', cutie !== null && cutie.x <= 40 && cutie.y <= 40
    && (await buton.innerText()).includes('Înapoi în site-ul principal'), JSON.stringify(cutie));
  nota(eticheta + ': butonul are cel puțin 44 px înălțime (atingere pe telefon)', cutie !== null && cutie.height >= 43, JSON.stringify(cutie));
  nota(eticheta + ': pagina campaniei fără derulare laterală', await faraDerulareLaterala(pagina));
  nota(eticheta + ': pagina campaniei: noindex', (await pagina.locator('meta[name="robots"]').getAttribute('content')) === 'noindex, follow');
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'campanie-' + latime + '.png'), fullPage: false });
  }

  await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), buton.click()]);
  const dupa = new URL(pagina.url());
  nota(eticheta + ': butonul duce la pagina cerută (cu parametrii ei), fără o a doua trimitere',
    dupa.pathname === '/produs/' + produs && dupa.searchParams.get('utm_source') === 'test', pagina.url());
  nota(eticheta + ': insigna pe pagina produsului', await pagina.locator('.campanie-insigna--pagina').isVisible());
  const banner = pagina.locator('[data-campanie-banner]');
  nota(eticheta + ': bannerul campaniei sus', await banner.isVisible() && ((await banner.boundingBox()) || { y: 99 }).y <= 1);
  nota(eticheta + ': pagina produsului fără derulare laterală', await faraDerulareLaterala(pagina));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'produs-' + latime + '.png'), fullPage: false });
  }

  await pagina.goto(baza + '/', { waitUntil: 'load' });
  nota(eticheta + ': prima pagină: insigna în grila produselor noi', await pagina.locator('.campanie-insigna--acasa').first().isVisible());
  nota(eticheta + ': prima pagină fără derulare laterală', await faraDerulareLaterala(pagina));
  const cuSimilare = process.env.CMS_DEV_PRODUS_SIMILARE || '';
  if (cuSimilare) {
    await pagina.goto(baza + '/produs/' + encodeURIComponent(cuSimilare), { waitUntil: 'load' });
    await pagina.waitForTimeout(300);
    // Banda produselor similare e refăcută din JavaScript (după lățimea ecranului): insigna trebuie să rămână.
    nota(eticheta + ': produsele similare: insigna rămâne pe carduri după ce JavaScript-ul reface banda',
      (await pagina.locator('.similar-products .campanie-insigna--similar').count()) >= 1);
  }
  await pagina.goto(baza + '/magazin', { waitUntil: 'load' });
  nota(eticheta + ': la a doua pagină nu mai e trimis la campanie', new URL(pagina.url()).pathname === '/magazin', pagina.url());
  const insigne = await pagina.locator('.campanie-insigna--card').count();
  nota(eticheta + ': insigna pe cardurile din magazin', insigne >= 1, String(insigne));
  nota(eticheta + ': magazinul fără derulare laterală', await faraDerulareLaterala(pagina));
  await pagina.evaluate(() => window.scrollTo(0, 1500));
  await pagina.waitForTimeout(150);
  const sus = await banner.boundingBox();
  nota(eticheta + ': bannerul lipit rămâne sus la derulare', sus !== null && Math.abs(sus.y) <= 1, JSON.stringify(sus));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'magazin-' + latime + '.png'), fullPage: false });
  }
  nota(eticheta + ': nicio eroare JavaScript', erori.length === 0, erori.join(' | '));
  await context.close();
}

async function admin(browser, latime, eticheta) {
  const context = await browser.newContext({ viewport: { width: latime, height: 900 }, isMobile: latime < 500, hasTouch: latime < 500 });
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
  await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
  await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
  await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.press('input[name="password"]', 'Enter')]);
  await pagina.goto(baza + '/admin/campanii/' + campanie, { waitUntil: 'load' });
  const banner = pagina.locator('[data-campanie-previzualizare] [data-campanie-banner]');
  await pagina.fill('#campanie-banner-text', 'Text de probă al previzualizării');
  await pagina.fill('#campanie-banner-fundal', '#0f766e');
  await pagina.fill('#campanie-banner-marime', '22');
  await pagina.selectOption('#campanie-banner-font', 'georgia');
  const stil = await banner.evaluate((el) => ({ text: el.innerText, fundal: getComputedStyle(el).backgroundColor, marime: getComputedStyle(el).fontSize,
    font: getComputedStyle(el).fontFamily }));
  nota(eticheta + ': previzualizarea bannerului urmează textul, culoarea, mărimea și fontul', stil.text.includes('Text de probă')
    && stil.fundal === 'rgb(15, 118, 110)' && stil.marime === '22px' && stil.font.includes('Georgia'), JSON.stringify(stil));
  await pagina.fill('#campanie-insigna-text', 'Probă');
  nota(eticheta + ': previzualizarea insignei urmează textul', (await pagina.locator('[data-campanie-insigna]').innerText()).toUpperCase() === 'PROBĂ');
  nota(eticheta + ': editorul campaniei fără derulare laterală', await faraDerulareLaterala(pagina));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'admin-editor-' + latime + '.png'), fullPage: true });
  }
  await pagina.goto(baza + '/admin/campanii', { waitUntil: 'load' });
  nota(eticheta + ': lista campaniilor fără derulare laterală', await faraDerulareLaterala(pagina));
  nota(eticheta + ': nicio eroare JavaScript în admin', erori.length === 0, erori.join(' | '));
  await context.close();
}

(async () => {
  if (capturi) {
    fs.mkdirSync(capturi, { recursive: true });
  }
  const browser = await chromium.launch();
  try {
    await vizitator(browser, 1366, 'desktop 1366');
    await vizitator(browser, 390, 'telefon 390');
    await admin(browser, 1366, 'admin 1366');
    await admin(browser, 390, 'admin 390');
  } catch (e) {
    nota('browserul a mers până la capăt', false, String(e && e.stack || e));
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
