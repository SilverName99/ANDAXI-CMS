// Previzualizarea din Admin → Antet, subsol și meniu în Chromium (tools/dev/test-pluginuri.php, T5; serverul cu tema demo
// tools/dev/site-demo-tema), 1366 px, deviceScaleFactor 1: un antet de probă cu Bootstrap scris în editor (nesalvat, deci baza
// rămâne neatinsă) trebuie să arate în cadru cu stilurile site-ului. Foile site-ului între stilul de bază al previzualizării și
// CSS-ul din Design, Bootstrap aplicat, foaia temei peste stilul de bază, CSS-ul din Design peste Bootstrap și cu variabila
// temei, fontul temei încărcat (primit de la pagina adminului: cadrul are o origine opacă), JavaScript-ul Bootstrap (butonul de
// meniu pe telefon); cadrul rămâne izolat (sandbox="allow-scripts", originea „null”, fără acces la pagina adminului).
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA. Scrie pe stdout un JSON cu rezultatele.
'use strict';

const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });

const ANTET = '<header class="antet-proba"><nav class="navbar navbar-expand-lg"><div class="container">'
  + '<a class="navbar-brand" href="/">Firma Demo</a>'
  + '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#meniu-proba" aria-controls="meniu-proba" '
  + 'aria-expanded="false" aria-label="Meniu"><span class="navbar-toggler-icon"></span></button>'
  + '<div class="collapse navbar-collapse" id="meniu-proba"><ul class="navbar-nav">'
  + '<li class="nav-item"><a class="nav-link" href="/">Acasă</a></li><li class="nav-item"><a class="nav-link" href="/contact">Contact</a></li>'
  + '</ul></div></div></nav></header>';
// Aceeași specificitate ca regula Bootstrap a lui .navbar-brand: câștigă doar dacă vine după Bootstrap; culoarea e a temei demo.
const CSS = '.navbar-brand{color:var(--tema-demo-accent)}';

(async () => {
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: 1 });
    await context.route('**/*', (ruta) => {
      const adresa = ruta.request().url();
      return adresa.startsWith(baza) || adresa.startsWith('data:') || adresa.startsWith('about:') ? ruta.continue() : ruta.abort();
    });
    const pagina = await context.newPage();
    const erori = [];
    pagina.on('pageerror', (e) => erori.push(String(e)));

    await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
    await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
    await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
    await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.press('input[name="password"]', 'Enter')]);
    await pagina.goto(baza + '/admin/design?section=header', { waitUntil: 'load' });
    // Editoarele (CodeMirror stă imediat după textarea lui): CSS-ul, apoi HTML-ul; previzualizarea citește editoarele și se
    // reface la 300 ms după ultima schimbare.
    await pagina.evaluate(([html, css]) => {
      const scrie = (id, valoare) => {
        const t = document.getElementById(id);
        const cm = t.nextElementSibling?.CodeMirror;
        if (cm) { cm.setValue(valoare); } else { t.value = valoare; t.dispatchEvent(new Event('input')); }
      };
      scrie('design-css', css);
      scrie('design-html', html);
    }, [ANTET, CSS]);
    const element = await pagina.$('#design-preview');
    let cadru = null;
    let gata = false;
    for (let i = 0; i < 100 && !gata; i++) {
      cadru = await element.contentFrame();
      gata = cadru ? await cadru.evaluate(() => !!document.getElementById('meniu-proba')
        && [...document.fonts].some((f) => f.family.replace(/["']/g, '') === 'Rubik' && f.status === 'loaded')).catch(() => false) : false;
      if (!gata) await pagina.waitForTimeout(100);
    }
    nota('antetul de probă scris în editor ajunge în previzualizare', cadru !== null
      && await cadru.evaluate(() => !!document.querySelector('header.antet-proba #meniu-proba')).catch(() => false));
    const stare = await cadru.evaluate(() => {
      const st = (sel, prop) => { const e = document.querySelector(sel); return e ? getComputedStyle(e)[prop] : null; };
      const cap = [...document.head.children];
      const stiluri = cap.filter((e) => e.tagName === 'STYLE');
      const foi = cap.filter((e) => e.tagName === 'LINK' && e.rel === 'stylesheet');
      let parinte;
      try { parinte = window.parent.document ? 'citit' : 'nimic'; } catch (e) { parinte = 'blocat'; }
      return {
        foi: foi.map((e) => e.getAttribute('href')),
        ordine: foi.length > 0 && stiluri.length >= 2 && cap.indexOf(foi[0]) > cap.indexOf(stiluri[0])
          && cap.indexOf(foi[foi.length - 1]) < cap.indexOf(stiluri[stiluri.length - 1]),
        navbar: st('.navbar', 'display'), lista: st('.navbar-nav', 'listStyleType'), subliniere: st('.nav-link', 'textDecorationLine'),
        font: (st('body', 'fontFamily') || '').split(',')[0].replace(/["']/g, '').trim(),
        brand: st('.navbar-brand', 'color'),
        rubik: [...document.fonts].some((f) => f.family.replace(/["']/g, '') === 'Rubik' && f.status === 'loaded'),
        bootstrapJs: typeof window.bootstrap === 'object' && typeof window.bootstrap.Collapse === 'function',
        origine: window.origin, parinte,
      };
    });
    nota('foile site-ului în cadru, între stilul de bază al previzualizării și CSS-ul din Design: ' + stare.foi.join(', '),
      stare.ordine && stare.foi.length === 3 && stare.foi[0].startsWith('/assets/vendor/fonturi/rubik/rubik.css?v=')
      && stare.foi[1].startsWith('/assets/vendor/bootstrap/css/bootstrap.min.css?v=') && stare.foi[2].startsWith('/tema/css/tema-demo.css?v='),
      JSON.stringify(stare.foi));
    nota('Bootstrap aplicat antetului: .navbar flex, .navbar-nav fără buline, .nav-link nesubliniat',
      stare.navbar === 'flex' && stare.lista === 'none' && stare.subliniere === 'none', JSON.stringify([stare.navbar, stare.lista, stare.subliniere]));
    nota('foaia temei peste stilul de bază al previzualizării: corpul scrie cu Rubik, nu cu Arial', stare.font === 'Rubik', stare.font);
    nota('CSS-ul din Design după Bootstrap și cu variabila temei: .navbar-brand în rgb(15, 118, 110)', stare.brand === 'rgb(15, 118, 110)', stare.brand);
    nota('fontul temei (Rubik) încărcat în cadru, primit de la pagina adminului', stare.rubik, String(stare.rubik));
    nota('cadrul rămâne izolat: sandbox="allow-scripts", originea „null”, pagina adminului inaccesibilă',
      (await element.getAttribute('sandbox')) === 'allow-scripts' && stare.origine === 'null' && stare.parinte === 'blocat',
      JSON.stringify([await element.getAttribute('sandbox'), stare.origine, stare.parinte]));

    // Pe telefon: meniul se strânge sub butonul Bootstrap, iar JavaScript-ul Bootstrap îl deschide.
    await pagina.click('.device-switch[data-device="mobile"]');
    await pagina.waitForTimeout(300);
    const inainte = await cadru.evaluate(() => getComputedStyle(document.getElementById('meniu-proba')).display);
    await cadru.click('.navbar-toggler');
    await cadru.waitForFunction(() => document.getElementById('meniu-proba').classList.contains('show'), null, { timeout: 3000 }).catch(() => {});
    const dupa = await cadru.evaluate(() => [document.getElementById('meniu-proba').classList.contains('show'),
      getComputedStyle(document.getElementById('meniu-proba')).display]);
    nota('telefon: JavaScript-ul Bootstrap încărcat; meniul strâns se deschide din butonul lui',
      stare.bootstrapJs && inainte === 'none' && dupa[0] === true && dupa[1] !== 'none', JSON.stringify({ bootstrapJs: stare.bootstrapJs, inainte, dupa }));

    // Tastarea în editor: previzualizarea o urmează, refăcută o dată după ultima tastă (nu la fiecare), iar salvarea trimite
    // ce s-a tastat (cererea primește aici 204, deci baza rămâne neatinsă și pagina pe loc).
    const TASTAT = ' TASTAT IN EDITOR';
    const areTastat = () => cadru.evaluate((t) => document.body.innerText.includes(t), TASTAT.trim()).catch(() => false);
    let reincarcari = 0;
    const numara = (f) => { if (f === cadru) reincarcari++; };
    pagina.on('framenavigated', numara);
    await pagina.click('#design-html + .CodeMirror .CodeMirror-lines');
    await pagina.keyboard.press('Control+End');
    await pagina.keyboard.type(TASTAT, { delay: 25 });
    const imediat = await areTastat();
    await cadru.waitForFunction((t) => document.body.innerText.includes(t), TASTAT.trim(), { timeout: 3000 }).catch(() => {});
    await pagina.waitForTimeout(600);
    pagina.off('framenavigated', numara);
    const apare = await areTastat();
    nota('tastarea în editor: textul ajunge în previzualizare, cadrul refăcut o dată (cel mult de două ori) pentru ' + TASTAT.length
      + ' taste, după pauză', apare && reincarcari >= 1 && reincarcari <= 2, JSON.stringify({ imediatDupaUltimaTasta: imediat, apare, reincarcari }));
    let trimis = null;
    await pagina.route('**/admin/design/save', async (ruta) => {
      trimis = ruta.request().postData() || '';
      await ruta.fulfill({ status: 204, body: '' });
    });
    await pagina.click('#design-site-form button[type="submit"]');
    for (let i = 0; i < 30 && trimis === null; i++) await pagina.waitForTimeout(100);
    const campuri = new URLSearchParams(trimis || '');
    nota('salvarea trimite ce e în editor (textarea scrisă la trimitere), cu secțiunea și CSS-ul',
      campuri.get('section') === 'header' && (campuri.get('html_content') || '').includes('antet-proba')
      && (campuri.get('html_content') || '').includes(TASTAT.trim()) && campuri.get('css_content') === CSS,
      JSON.stringify({ section: campuri.get('section'), html: (campuri.get('html_content') || '').slice(-60), css: campuri.get('css_content') }));
    nota('nicio eroare JavaScript în pagina adminului', erori.length === 0, erori.join(' | '));
  } catch (e) {
    nota('Chromium: ' + String(e && e.message ? e.message : e), false);
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
