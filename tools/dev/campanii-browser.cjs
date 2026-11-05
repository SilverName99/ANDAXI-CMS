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

  // Mai departe, clicuri din site (Referer al site-ului): fără trimitere la campanie.
  const dinSite = { waitUntil: 'load', referer: baza + '/' };
  await pagina.goto(baza + '/', dinSite);
  nota(eticheta + ': prima pagină: insigna în grila produselor noi', await pagina.locator('.campanie-insigna--acasa').first().isVisible());
  nota(eticheta + ': prima pagină fără derulare laterală', await faraDerulareLaterala(pagina));
  const cuSimilare = process.env.CMS_DEV_PRODUS_SIMILARE || '';
  if (cuSimilare) {
    await pagina.goto(baza + '/produs/' + encodeURIComponent(cuSimilare), dinSite);
    await pagina.waitForTimeout(300);
    // Banda produselor similare e refăcută din JavaScript (după lățimea ecranului): insigna trebuie să rămână.
    nota(eticheta + ': produsele similare: insigna rămâne pe carduri după ce JavaScript-ul reface banda',
      (await pagina.locator('.similar-products .campanie-insigna--similar').count()) >= 1);
  }
  await pagina.goto(baza + '/magazin', dinSite);
  nota(eticheta + ': navigarea din site nu e trimisă la campanie', new URL(pagina.url()).pathname === '/magazin', pagina.url());
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
  // O nouă intrare din afară în aceeași sesiune (fila închisă, apoi de pe Google): trimis din nou la campanie.
  await pagina.goto(baza + '/produs/' + encodeURIComponent(produs), { waitUntil: 'load', referer: 'https://www.google.com/' });
  nota(eticheta + ': o nouă intrare de pe Google, în aceeași sesiune: din nou pe pagina campaniei',
    new URL(pagina.url()).pathname.startsWith('/campanie/'), pagina.url());
  nota(eticheta + ': nicio eroare JavaScript', erori.length === 0, erori.join(' | '));
  await context.close();
}

// Pagina campaniei fără antetul și subsolul site-ului, cu bara închisă și grila {{campanie_produse}} închisă (CMS_DEV_GRILA=1;
// test-campanii.php pregătește campania, un antet și un subsol de probă, apoi le pune la loc).
async function paginaGrila(browser, latime, eticheta) {
  const context = await browser.newContext({ viewport: { width: latime, height: latime < 500 ? 844 : 900 }, isMobile: latime < 500,
    hasTouch: latime < 500, deviceScaleFactor: 1, userAgent: latime < 500
      ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36'
      : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' });
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  await pagina.goto(baza + '/campanie/saptamana-demo?inapoi=%2Fmagazin', { waitUntil: 'load' });
  nota(eticheta + ': fără antetul și subsolul site-ului', (await pagina.locator('.antet-proba-bf, .subsol-proba-bf, header.header').count()) === 0);
  const bara = pagina.locator('[data-campanie-inapoi]');
  const stilBara = await bara.evaluate((el) => ({ fundal: getComputedStyle(el).backgroundColor, y: el.getBoundingClientRect().y }));
  nota(eticheta + ': bara „Înapoi” închisă, sus', stilBara.fundal === 'rgb(0, 0, 0)' && Math.abs(stilBara.y) <= 1, JSON.stringify(stilBara));
  const carduri = pagina.locator('.campanie-grila__card');
  const n = await carduri.count();
  const cutii = [];
  for (let i = 0; i < n; i++) {
    cutii.push(await carduri.nth(i).boundingBox());
  }
  const peRand = cutii.filter((c) => c && Math.abs(c.y - cutii[0].y) < 2).length;
  nota(eticheta + ': grila produselor: ' + (latime < 500 ? 'două' : 'cel puțin trei') + ' carduri pe rând', n >= 3
    && (latime < 500 ? peRand === 2 : peRand >= 3), n + ' carduri, ' + peRand + ' pe rând');
  const stilCard = await carduri.first().evaluate((el) => ({ fundal: getComputedStyle(el).backgroundColor, text: getComputedStyle(el).color }));
  nota(eticheta + ': cardurile închise (#161616, text alb)', stilCard.fundal === 'rgb(22, 22, 22)' && stilCard.text === 'rgb(255, 255, 255)',
    JSON.stringify(stilCard));
  // Colțurile rotunjite (implicit) se văd și cu foile de stil ale site-ului (app.css pune `border-radius: 0 !important` pe orice
  // `article` din `.site-shell`): cardul 16px, butoanele rotunde, poza tăiată de card.
  const colturi = await carduri.first().evaluate((el) => ({ card: getComputedStyle(el).borderTopLeftRadius, overflow: getComputedStyle(el).overflow,
    buton: getComputedStyle(el.querySelector('.campanie-grila__buton')).borderTopLeftRadius }));
  nota(eticheta + ': colțurile rotunjite ale cardului și ale butoanelor, peste regula temei', colturi.card === '16px' && colturi.overflow === 'hidden'
    && colturi.buton === '999px', JSON.stringify(colturi));
  nota(eticheta + ': pagina campaniei cu grila, fără derulare laterală', await faraDerulareLaterala(pagina));
  const butonCos = pagina.locator('.floating-cart__trigger');
  nota(eticheta + ': butonul coșului plutitor nu se vede', (await butonCos.count()) === 0 || !(await butonCos.first().isVisible()));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'campanie-grila-' + latime + '.png'), fullPage: true });
  }
  const adauga = pagina.locator('form.campanie-grila__formular button').first();
  const raspunsAdauga = pagina.waitForResponse((r) => /\/api\/cart\/items\/\d+\/add$/.test(new URL(r.url()).pathname), { timeout: 8000 });
  await adauga.click();
  const r = await raspunsAdauga.catch(() => null);
  await pagina.waitForTimeout(400);
  nota(eticheta + ': „Adaugă în coș” pune produsul în coș pe loc (coșul plutitor, ca în magazin), fără să plece de pe pagină',
    r !== null && r.status() === 200 && new URL(pagina.url()).pathname.startsWith('/campanie/')
    && await pagina.locator('.floating-cart__panel.is-open, .is-open').count() >= 1, r ? String(r.status()) : 'fără răspuns');
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'campanie-grila-cos-' + latime + '.png'), fullPage: false });
  }
  nota(eticheta + ': nicio eroare JavaScript pe pagina campaniei', erori.length === 0, erori.join(' | '));
  await context.close();
}

// Oferta campaniei în coș (CMS_DEV_COS=1, CMS_DEV_PRODUSE="id,id": două produse ale campaniei, cu oferta „1+1 −50%” pusă de
// test-campanii.php): pagina coșului, coșul plutitor și checkout-ul arată rândul ofertei și reducerea pe linie.
async function cosOferta(browser, latime, eticheta) {
  const context = await browser.newContext({ viewport: { width: latime, height: latime < 500 ? 844 : 900 }, isMobile: latime < 500,
    hasTouch: latime < 500, deviceScaleFactor: 1, userAgent: latime < 500
      ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36'
      : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' });
  await blocheazaExterne(context);
  const pagina = await context.newPage();
  const erori = [];
  pagina.on('pageerror', (e) => erori.push(String(e)));
  for (const id of String(process.env.CMS_DEV_PRODUSE || '').split(',').filter(Boolean)) {
    await pagina.request.post(baza + '/cos/adauga/' + id, { form: { quantity: '1' }, maxRedirects: 0 });
  }
  await pagina.goto(baza + '/cos', { waitUntil: 'load' });
  const sumar = pagina.locator('[data-campanie-sumar]').first();
  nota(eticheta + ': pagina coșului: rândul ofertei și reducerea pe linie se văd', await sumar.isVisible()
    && await pagina.locator('[data-campanie-linie]').first().isVisible() && (await sumar.innerText()).includes('1+1 −50%'), await sumar.innerText().catch(() => ''));
  nota(eticheta + ': pagina coșului fără derulare laterală', await faraDerulareLaterala(pagina));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'cos-oferta-' + latime + '.png'), fullPage: true });
  }
  await pagina.goto(baza + '/magazin', { waitUntil: 'load', referer: baza + '/cos' });
  const buton = pagina.locator('.floating-cart__trigger');
  let plutitor = false;
  if (await buton.count() > 0 && await buton.first().isVisible()) {
    // Bannerul de cookie-uri poate sta peste buton: clicul direct pe element, ca al unui om care a închis bannerul.
    await buton.first().evaluate((el) => el.click());
    await pagina.waitForTimeout(500);
    plutitor = await pagina.locator('.floating-cart [data-campanie-sumar]').first().isVisible()
      && await pagina.locator('.floating-cart [data-campanie-linie]').first().isVisible();
  }
  nota(eticheta + ': coșul plutitor: rândul ofertei și reducerea pe linie', plutitor);
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'cos-plutitor-oferta-' + latime + '.png'), fullPage: false });
  }
  await pagina.goto(baza + '/checkout', { waitUntil: 'load' });
  nota(eticheta + ': checkout: rândul ofertei și reducerea pe linie', await pagina.locator('[data-campanie-sumar]').first().isVisible()
    && await pagina.locator('[data-campanie-linie]').first().isVisible());
  nota(eticheta + ': checkout fără derulare laterală', await faraDerulareLaterala(pagina));
  if (capturi) {
    await pagina.screenshot({ path: path.join(capturi, 'checkout-oferta-' + latime + '.png'), fullPage: true });
  }
  nota(eticheta + ': nicio eroare JavaScript (coș, coș plutitor, checkout)', erori.length === 0, erori.join(' | '));
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
    if (process.env.CMS_DEV_COS === '1') {
      await cosOferta(browser, 1366, 'coș 1366');
      await cosOferta(browser, 390, 'coș 390');
    } else if (process.env.CMS_DEV_GRILA === '1') {
      await paginaGrila(browser, 1366, 'grila 1366');
      await paginaGrila(browser, 390, 'grila 390');
    } else {
      await vizitator(browser, 1366, 'desktop 1366');
      await vizitator(browser, 390, 'telefon 390');
      await admin(browser, 1366, 'admin 1366');
      await admin(browser, 390, 'admin 390');
    }
  } catch (e) {
    nota('browserul a mers până la capăt', false, String(e && e.stack || e));
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
