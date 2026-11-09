// Pluginul statistici în Chromium (tools/dev/test-statistici.php, partea D): o pagină servită ca dintr-un CDN (fișierul HTML static
// CMS_DEV_PAGINA, fără PHP) trimite cu sendBeacon pagina văzută, apoi, la ascunderea filei, timpul activ; fără niciun cookie și fără
// nimic în localStorage / sessionStorage. Un browser automatizat (navigator.webdriver) nu trimite nimic. Apoi, ca administrator,
// Rapoarte → Statistici la 1366 și 390 px: fără derulare laterală, capturile (datele demo și evenimentele inventate ale testului).
// Variabile: CMS_DEV_URL, CMS_DEV_PAGINA, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_CAPTURI. Scrie pe stdout un JSON cu rezultatele.
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const paginaStatica = process.env.CMS_DEV_PAGINA || '/';
const capturi = process.env.CMS_DEV_CAPTURI || '';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';

async function blocheazaExterne(context) {
  await context.route('**/*', (ruta) => {
    const adresa = ruta.request().url();
    return adresa.startsWith(baza) || adresa.startsWith('data:') ? ruta.continue() : ruta.abort();
  });
}

async function vizitator(browser) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA });
  // Un vizitator obișnuit: Playwright pune navigator.webdriver = true, pe care scriptul îl tratează ca robot.
  await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  const trimise = [];
  pagina.on('request', (c) => { if (c.url().endsWith('/api/statistici')) { trimise.push(c); } });
  const raspunsuri = [];
  pagina.on('response', (r) => { if (r.url().endsWith('/api/statistici')) { raspunsuri.push(r.status()); } });
  const asteptare = pagina.waitForRequest((c) => c.url().endsWith('/api/statistici'), { timeout: 10000 }).catch(() => null);
  const raspuns = await pagina.goto(baza + paginaStatica + '?utm_source=tiktok&utm_campaign=video-viral', { waitUntil: 'load' });
  const cerere = await asteptare;
  let corp = null;
  try { corp = cerere ? JSON.parse(cerere.postData() || 'null') : null; } catch (e) { corp = null; }
  nota('pagina statică („din CDN”, fără PHP) se încarcă', raspuns && raspuns.status() === 200 && !(raspuns.headers()['x-powered-by'] || '').includes('PHP'),
    raspuns ? raspuns.status() + ' ' + JSON.stringify(raspuns.headers()) : 'fără răspuns');
  nota('scriptul trimite pagina văzută (POST /api/statistici, sendBeacon), cu calea fără parametri și utm-urile permise',
    cerere !== null && cerere.method() === 'POST' && corp && corp.e === 'v' && corp.p === paginaStatica && corp.u && corp.u.source === 'tiktok'
      && corp.u.campaign === 'video-viral', cerere ? cerere.postData() : 'nicio cerere');
  await pagina.waitForTimeout(1500);
  const asteptarePlecare = pagina.waitForRequest((c) => c.url().endsWith('/api/statistici') && (c.postData() || '').includes('"e":"l"'), { timeout: 10000 }).catch(() => null);
  await pagina.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const plecare = await asteptarePlecare;
  nota('la ascunderea filei: timpul activ (e = „l”, secunde > 0)', plecare !== null && JSON.parse(plecare.postData() || '{}').d >= 1,
    plecare ? plecare.postData() : 'nicio cerere');
  await pagina.waitForTimeout(500);
  nota('răspunsurile punctului de primire: 204', raspunsuri.length >= 2 && raspunsuri.every((s) => s === 204), JSON.stringify(raspunsuri));
  const cookies = await context.cookies();
  const chei = () => ({ local: Object.keys(localStorage).sort(), sesiune: Object.keys(sessionStorage).sort() });
  const stocare = await pagina.evaluate(chei);
  global.stocareCuScript = stocare;
  nota('fără cookie', cookies.length === 0, JSON.stringify(cookies.map((c) => c.name)));
  nota('fără erori JavaScript pe pagină', erori.length === 0, erori.join(' | '));
  await context.close();

  // Un browser automatizat (navigator.webdriver = true): nimic trimis.
  const robot = await browser.newContext({ userAgent: UA });
  await blocheazaExterne(robot);
  const paginaRobot = await robot.newPage();
  let deLaRobot = 0;
  paginaRobot.on('request', (c) => { if (c.url().endsWith('/api/statistici')) { deLaRobot++; } });
  await paginaRobot.goto(baza + paginaStatica, { waitUntil: 'load' });
  await paginaRobot.waitForTimeout(800);
  nota('un browser automatizat (navigator.webdriver) nu trimite nimic', deLaRobot === 0, String(deLaRobot));
  // Aceeași pagină fără ca scriptul statisticilor să ruleze: localStorage / sessionStorage au exact aceleași chei (cele ale temei sau
  // ale coșului), deci statisticile nu scriu nimic în browser.
  const faraScript = await paginaRobot.evaluate(() => ({ local: Object.keys(localStorage).sort(), sesiune: Object.keys(sessionStorage).sort() }));
  nota('nimic scris de statistici în localStorage / sessionStorage (aceleași chei ca fără script)',
    JSON.stringify(faraScript) === JSON.stringify(global.stocareCuScript), JSON.stringify({ cuScript: global.stocareCuScript, faraScript }));
  await robot.close();
}

async function tablou(browser) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
  await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
  await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
  await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.press('input[name="password"]', 'Enter')]);
  for (const latime of [1366, 390]) {
    await pagina.setViewportSize({ width: latime, height: latime < 500 ? 844 : 900 });
    await pagina.goto(baza + '/admin/statistici?perioada=azi&compara=1', { waitUntil: 'load' });
    const lat = await pagina.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    nota('tabloul la ' + latime + ' px: fără derulare laterală', lat);
    const grafic = await pagina.locator('#st-grafic-vizite').boundingBox();
    // Pe desktop, graficele vizitelor și veniturilor stau unul lângă altul; pe telefon, unul sub altul, pe toată lățimea.
    nota('tabloul la ' + latime + ' px: graficul pe lățimea cardului lui', grafic !== null && grafic.width > latime * (latime < 500 ? 0.6 : 0.3), JSON.stringify(grafic));
    if (latime === 1366) {
      // Sortarea la clic pe antet și desfacerea unei surse.
      const antet = pagina.locator('#st-surse thead th').nth(2).locator('button');
      if (await antet.count()) {
        await antet.click();
        nota('tabelul se sortează la clic pe antet', (await pagina.locator('#st-surse thead th').nth(2).getAttribute('aria-sort')) === 'descending');
      }
    }
    if (capturi) {
      await pagina.screenshot({ path: path.join(capturi, 'statistici-' + latime + '.png'), fullPage: true });
    }
  }
  nota('fără erori JavaScript în tablou', erori.length === 0, erori.join(' | '));
  await context.close();
}

(async () => {
  const browser = await chromium.launch();
  try {
    await vizitator(browser);
    await tablou(browser);
  } catch (e) {
    nota('Chromium a rulat până la capăt', false, String(e && e.stack ? e.stack : e));
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
