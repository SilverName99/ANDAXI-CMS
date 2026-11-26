// Partea H a testului tools/dev/test-automatizari.php: editorul vizual al automatizărilor în Chromium (Playwright).
//   node tools/dev/automatizari-browser.cjs <baza> <parola-admin> <id-automatizare> <dosar-capturi>
// Ultima linie de ieșire e un JSON {verificari: [[eticheta, ok, detalii], …]}.
'use strict';
const path = require('path');
const fs = require('fs');
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  ({ chromium } = require(path.join(process.env.NODE_PATH || '/opt/node22/lib/node_modules', 'playwright')));
}

(async () => {
  const [baza, parola, id, dosar] = process.argv.slice(2);
  fs.mkdirSync(dosar, { recursive: true });
  const verificari = [];
  const verifica = (eticheta, ok, detalii = '') => verificari.push([eticheta, !!ok, String(detalii || '')]);
  const browser = await chromium.launch();
  const logare = async (ctx) => {
    const p = await ctx.newPage();
    await p.goto(baza + '/admin/login');
    await p.fill('input[name=email]', 'admin@exemplu.ro');
    await p.fill('input[name=password]', parola);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    return p;
  };
  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const p = await logare(ctx);
    const erori = [];
    p.on('pageerror', (e) => erori.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') erori.push(m.text()); });
    await p.goto(baza + '/admin/automatizari/' + id);
    await p.waitForSelector('.af-card[data-cheie="__decl"]');
    const nrInainte = await p.locator('.af-card').count();
    verifica('editorul se deschide: declanșatorul și pașii pe pânză, panoul declanșatorului', nrInainte >= 3
      && (await p.locator('#af-panou-tip').textContent()) === 'Declanșator', nrInainte);
    await p.locator('.af-plus').first().click();
    const vizibil = await p.locator('#af-alege').isVisible();
    await p.fill('#af-alege-cauta', 'asteapta');
    const butoane = await p.locator('#af-alege-grupuri button').allTextContents();
    verifica('„+” deschide fereastra pașilor; căutarea fără diacritice găsește „Așteaptă”', vizibil && butoane.length === 1 && butoane[0].includes('Așteaptă'),
      JSON.stringify(butoane));
    await p.keyboard.press('Escape');
    verifica('Esc închide fereastra și focusul revine pe „+”', !(await p.locator('#af-alege').isVisible())
      && (await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('af-plus'))));
    await p.locator('.af-plus').first().click();
    await p.fill('#af-alege-cauta', '');
    await p.locator('#af-alege-grupuri button[data-tip="asteapta"]').click();
    await p.waitForTimeout(300);
    const nrDupa = await p.locator('.af-card').count();
    const titluPanou = await p.locator('#af-panou-titlu').textContent();
    verifica('pasul adăugat apare pe pânză, ales, cu panoul lui', nrDupa === nrInainte + 1 && titluPanou === 'Așteaptă'
      && (await p.locator('.af-card[aria-pressed="true"]').count()) === 1, nrDupa + ' ' + titluPanou);
    await p.locator('#af-panou-corp input[type=number]').first().fill('4');
    await p.waitForTimeout(100);
    const textCard = await p.locator('.af-card[aria-pressed="true"] .af-titlu').textContent();
    verifica('setarea din panou schimbă cardul pe loc („4 zile”)', textCard === '4 zile', textCard);
    verifica('starea salvării: „Nesalvat”', (await p.locator('#af-salvare').getAttribute('data-stare')) === 'nesalvat');
    await p.click('#af-salveaza');
    await p.waitForFunction(() => document.getElementById('af-salvare').dataset.stare !== 'salveaza');
    verifica('„Salvează”: salvat pe server', (await p.locator('#af-salvare').getAttribute('data-stare')) === 'salvat',
      await p.locator('#af-salvare').textContent());
    await p.screenshot({ path: path.join(dosar, 'editor-1440.png') });
    await p.locator('.af-card[data-cheie="__decl"]').click();
    await p.locator('#af-panou-corp select').first().selectOption('cos_abandonat');
    await p.waitForTimeout(100);
    verifica('declanșatorul „Coș abandonat”: nota regulii fără emailuri duble în panou', (await p.locator('.af-caseta--atentie').count()) === 1);
    const latimePagina = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    verifica('editorul la 1440 px: fără derulare laterală a paginii', latimePagina);
    verifica('editorul: nicio eroare JavaScript', erori.length === 0, erori.join(' | '));
    p.on('dialog', (d) => d.accept());
    await ctx.close();

    // Mișcarea redusă: nicio animație la adăugare.
    const ctxRedus = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const pr = await logare(ctxRedus);
    await pr.goto(baza + '/admin/automatizari/' + id);
    await pr.waitForSelector('.af-plus');
    await pr.locator('.af-plus').first().click();
    await pr.locator('#af-alege-grupuri button[data-tip="eticheta_adauga"]').click();
    const animatii = await pr.evaluate(() => document.getAnimations().length);
    verifica('cu „mișcare redusă”: pasul nou apare fără nicio animație', animatii === 0, animatii);
    pr.on('dialog', (d) => d.accept());
    await ctxRedus.close();

    // Lista, pe calculator și pe telefon.
    for (const [latime, inaltime] of [[1366, 900], [390, 844]]) {
      const c = await browser.newContext({ viewport: { width: latime, height: inaltime } });
      const pl = await logare(c);
      const eroriL = [];
      pl.on('pageerror', (e) => eroriL.push(String(e)));
      await pl.goto(baza + '/admin/automatizari');
      const fara = await pl.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
      verifica('lista la ' + latime + ' px: fără derulare laterală, fără erori JS', fara && eroriL.length === 0, eroriL.join(' | '));
      await pl.screenshot({ path: path.join(dosar, 'lista-' + latime + '.png'), fullPage: true });
      await c.close();
    }
  } catch (e) {
    verifica('scriptul din browser a mers până la capăt', false, String(e && e.stack || e));
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify({ verificari }));
})();
