// Bannerul de cookie-uri în Chromium (tools/dev/test-banner-cookie.php, partea C), cu datele demo și trackerii inventați puși de
// test (GA, GTM, Ads, Clarity, tawk.to). Orice cerere spre altă gazdă decât serverul de dezvoltare se notează și se oprește (nimic
// nu pleacă din calculator). Variabile: CMS_DEV_URL, CMS_DEV_COOKIE (numele cookie-ului acordului), CMS_DEV_POLITICA (adresa paginii
// generate), CMS_DEV_CAPTURI (dosarul capturilor). Scrie pe stdout un JSON cu rezultatele.
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const numeCookie = process.env.CMS_DEV_COOKIE || 'cookie_consent';
const politica = process.env.CMS_DEV_POLITICA || '/politica-de-cookie-uri';
const capturi = process.env.CMS_DEV_CAPTURI || '';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });
const gazdaLocala = new URL(baza).host;

async function context(browser, latime, inaltime) {
  const ctx = await browser.newContext({ viewport: { width: latime, height: inaltime }, deviceScaleFactor: 1 });
  const terti = [];
  await ctx.route('**/*', (ruta) => {
    const adresa = new URL(ruta.request().url());
    if (adresa.host !== gazdaLocala) {
      terti.push(adresa.host + adresa.pathname);
      return ruta.abort();
    }
    return ruta.continue();
  });
  const pagina = await ctx.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  return { ctx, pagina, terti, erori };
}

async function valoareCookie(ctx) {
  const toate = await ctx.cookies(baza);
  const c = toate.find((x) => x.name === numeCookie);
  return c ? c.value : '';
}

async function asteaptaIncarcarea(pagina, actiune) {
  await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), actiune()]);
}

(async () => {
  const browser = await chromium.launch();
  try {
    // 1. 1366 px: prima vizită.
    let { ctx, pagina, terti, erori } = await context(browser, 1366, 900);
    await pagina.goto(baza + '/', { waitUntil: 'load' });
    await pagina.waitForTimeout(300);
    const banner = pagina.locator('#cookie-banner');
    nota('prima vizită: bannerul se vede, butonul din colț nu, nicio cerere spre terți', await banner.isVisible()
      && !(await pagina.locator('.ax-cc-tab').isVisible()) && terti.length === 0, terti.join(', '));
    const marimi = async () => pagina.$$eval('#cookie-banner .ax-cc__buton:not([hidden])', (b) => b.map((x) => {
      const r = x.getBoundingClientRect();
      const s = getComputedStyle(x);
      return { t: x.textContent.trim(), w: Math.round(r.width), h: Math.round(r.height), raza: s.borderRadius, fundal: s.backgroundColor };
    }));
    let m = await marimi();
    nota('1366 px: „Accept toate”, „Refuz”, „Setări” la fel de mari și de rotunjite; primele două cu aceeași culoare', m.length === 3
      && m[0].t === 'Accept toate' && m[1].t === 'Refuz' && m[2].t === 'Setări' && new Set(m.map((x) => x.w + 'x' + x.h + ' ' + x.raza)).size === 1
      && m[0].fundal === m[1].fundal && m[0].h >= 44, JSON.stringify(m));
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'banner-1366.png') });
    // Tastatura: primul Tab ajunge în banner (e primul în pagină).
    await pagina.keyboard.press('Tab');
    const inBanner = await pagina.evaluate(() => !!document.activeElement && !!document.activeElement.closest('#cookie-banner'));
    nota('tastatura: primul Tab ajunge în banner', inBanner, await pagina.evaluate(() => document.activeElement && document.activeElement.outerHTML.slice(0, 120)));
    // Escape fără alegere nu închide bannerul (nu e un acord).
    await pagina.keyboard.press('Escape');
    nota('Escape fără alegere: bannerul rămâne', await banner.isVisible());
    // Setări: comutatoarele, nebifate; Statistici cu Space; Salvează.
    await pagina.click('[data-ax-cc="setari"]');
    const stare = await pagina.evaluate(() => ({
      deschis: !document.getElementById('ax-cc-setari').hidden,
      expanded: document.querySelector('[data-ax-cc="setari"]').getAttribute('aria-expanded'),
      focus: document.activeElement && document.activeElement.getAttribute('data-ax-cc-categorie'),
      bifate: Array.from(document.querySelectorAll('[data-ax-cc-categorie]')).filter((c) => c.checked).length,
      comutatoare: Array.from(document.querySelectorAll('[data-ax-cc-categorie]')).map((c) => c.getAttribute('role') + ':' + !!c.labels.length),
    }));
    m = await marimi();
    nota('„Setări”: categoriile (Statistici, Marketing, Conținut extern) nebifate, focusul pe primul comutator, „Salvează alegerea” la fel de mare',
      stare.deschis && stare.focus === 'statistici' && stare.bifate === 0 && stare.comutatoare.join() === 'switch:true,switch:true,switch:true'
      && m.length === 3 && m[2].t === 'Salvează alegerea' && m[2].w === m[0].w && m[2].h === m[0].h, JSON.stringify([stare, m]));
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'banner-setari-1366.png') });
    await pagina.keyboard.press('Space');
    terti.length = 0;
    await asteaptaIncarcarea(pagina, () => pagina.click('[data-ax-cc="salveaza"]'));
    await pagina.waitForTimeout(500);
    const dupaStatistici = terti.slice();
    nota('doar Statistici salvat: pagina se reîncarcă, cookie-ul 2.sme.s, pleacă GTM, gtag și Clarity, nu tawk.to și nici Ads',
      (await valoareCookie(ctx)) === '2.sme.s' && dupaStatistici.some((t) => t.includes('googletagmanager.com/gtm.js'))
      && dupaStatistici.some((t) => t.includes('clarity.ms')) && !dupaStatistici.some((t) => t.includes('tawk.to'))
      && !(await pagina.content()).includes("gtag('config', 'AW-"), dupaStatistici.join(', '));
    const tab = pagina.locator('.ax-cc-tab');
    nota('după alegere: bannerul ascuns, butonul din colț vizibil (44 px), cu numele „Setări cookie-uri”', !(await banner.isVisible())
      && (await tab.isVisible()) && (await tab.getAttribute('aria-label')) === 'Setări cookie-uri'
      && (await tab.boundingBox()).width >= 44);
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'buton-colt-1366.png') });
    // Redeschiderea: focusul pe banner, Escape îl închide și focusul revine pe buton.
    await tab.click();
    const focusDeschis = await pagina.evaluate(() => document.activeElement && document.activeElement.classList.contains('ax-cc__cutie'));
    await pagina.keyboard.press('Escape');
    const focusInapoi = await pagina.evaluate(() => document.activeElement && document.activeElement.classList.contains('ax-cc-tab'));
    nota('butonul din colț redeschide bannerul (focusul pe el); Escape îl închide fără schimbări, focusul pe butonul din colț',
      focusDeschis && focusInapoi && !(await banner.isVisible()) && (await valoareCookie(ctx)) === '2.sme.s');
    // Retragerea: un cookie _ga pus de „Google” se șterge, pagina se reîncarcă fără nimic de la terți.
    await ctx.addCookies([{ name: '_ga', value: 'GA1.1.123.456', url: baza }, { name: '_clck', value: 'x', url: baza }]);
    await tab.click();
    terti.length = 0;
    await asteaptaIncarcarea(pagina, () => pagina.click('[data-ax-cc="refuz"]'));
    await pagina.waitForTimeout(500);
    const numeRamase = (await ctx.cookies(baza)).map((c) => c.name);
    nota('retragerea („Refuz” din butonul din colț): reîncărcare, cookie-ul 2.sme.-, _ga și _clck șterse, nicio cerere spre terți',
      (await valoareCookie(ctx)) === '2.sme.-' && !numeRamase.includes('_ga') && !numeRamase.includes('_clck') && terti.length === 0
      && !(await pagina.content()).includes('googletagmanager.com'), JSON.stringify([numeRamase, terti]));
    // Înapoi în istoric la o pagină făcută cu acordul de dinainte: paza o reîncarcă, deci fără etichete.
    await ctx.addCookies([{ name: numeCookie, value: '2.sme.sme', url: baza }]);
    await pagina.goto(baza + '/contact', { waitUntil: 'load' });
    await ctx.addCookies([{ name: numeCookie, value: '2.sme.-', url: baza }]);
    terti.length = 0;
    await pagina.goBack({ waitUntil: 'load' });
    await pagina.goForward({ waitUntil: 'load' });
    await pagina.waitForTimeout(800);
    nota('o pagină cu etichete adusă din istoric după retragere: reîncărcată, fără etichete', !(await pagina.content()).includes('googletagmanager.com/gtm.js'),
      terti.join(', '));
    // Linkul din politică deschide setările; „Accept toate” pornește și tawk.to.
    await pagina.goto(baza + politica, { waitUntil: 'load' });
    await pagina.click('.politica-cookie a[href="#setari-cookie"]');
    nota('politica: linkul „schimbă setările cookie-urilor” deschide bannerul cu setările', await pagina.locator('#ax-cc-setari').isVisible());
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'politica-1366.png'), fullPage: false });
    terti.length = 0;
    await asteaptaIncarcarea(pagina, () => pagina.click('[data-ax-cc="toate"]'));
    await pagina.waitForTimeout(500);
    nota('„Accept toate”: cookie-ul 2.sme.sme; pleacă și tawk.to și gtag cu Ads', (await valoareCookie(ctx)) === '2.sme.sme'
      && terti.some((t) => t.includes('tawk.to')) && (await pagina.content()).includes("gtag('config', 'AW-"), terti.join(', '));
    nota('nicio eroare JavaScript', erori.length === 0, erori.join(' | '));
    await ctx.close();

    // 2. 390 px (telefon): butoanele unul sub altul, la fel de late; fără derulare orizontală.
    ({ ctx, pagina, terti, erori } = await context(browser, 390, 844));
    await ctx.addCookies([{ name: numeCookie, value: 'all', url: baza }]);
    await pagina.goto(baza + '/', { waitUntil: 'load' });
    await pagina.waitForTimeout(300);
    m = await marimi();
    // Derularea orizontală adusă de banner (pagina demo poate avea alta a ei): lățimea fără banner față de cea cu el.
    const latimePagina = await pagina.evaluate(() => document.documentElement.scrollWidth);
    const latimeFara = await pagina.evaluate(() => {
      const b = document.getElementById('cookie-banner');
      b.style.display = 'none';
      const w = document.documentElement.scrollWidth;
      b.style.display = '';
      return w;
    });
    const cutie = await pagina.locator('#cookie-banner .ax-cc__cutie').boundingBox();
    nota('390 px, cu alegerea veche „all”: bannerul nou, butoanele la fel de mari (unul sub altul), fără derulare orizontală, nicio cerere spre terți',
      (await pagina.locator('#cookie-banner').isVisible()) && m.length === 3 && new Set(m.map((x) => x.w + 'x' + x.h)).size === 1
      && m[0].w > 300 && latimePagina === latimeFara && cutie.x >= 0 && cutie.width <= 390 && cutie.y + cutie.height <= 844 + 1 && terti.length === 0, JSON.stringify([m, latimePagina, latimeFara, cutie, terti]));
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'banner-390.png') });
    await pagina.click('[data-ax-cc="setari"]');
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'banner-setari-390.png') });
    const panouIncape = await pagina.evaluate(() => {
      const c = document.querySelector('#cookie-banner .ax-cc__cutie');
      return c.getBoundingClientRect().height <= window.innerHeight + 1;
    });
    nota('390 px: cu setările deschise bannerul încape pe ecran (se derulează în el)', panouIncape);
    nota('390 px: nicio eroare JavaScript', erori.length === 0, erori.join(' | '));
    await ctx.close();
  } catch (e) {
    nota('scriptul Chromium', false, String(e && e.stack || e));
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
