// „Vezi” din Emailuri trimise în Chromium (tools/dev/test-emailuri-trimise.php, partea F), 1366 px, deviceScaleFactor 1, cu datele
// demo: butonul „Vezi” pe fiecare rând, sertarul cu emailul păstrat într-un iframe `sandbox=""` (fără scripturi: scriptul unui email
// de probă nu rulează, originea cadrului e opacă, titlul și cookie-urile adminului neatinse), mesajul unui email de dinainte și
// previzualizarea unui newsletter din campanie. Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_ID_COMANDA (rândul
// emailului de comandă), CMS_DEV_ID_SCRIPT (rândul cu scriptul), CMS_DEV_ID_VECHI (rândul de dinainte), CMS_DEV_ID_NEWSLETTER,
// CMS_DEV_TEXT_COMANDA (un text din emailul de comandă), CMS_DEV_TEXT_NEWSLETTER, CMS_DEV_CAPTURI (dosarul capturilor). Scrie pe
// stdout un JSON cu rezultatele.
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const capturi = process.env.CMS_DEV_CAPTURI || '';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });

async function deschideRandul(pagina, id) {
  const buton = pagina.locator('a[data-adm-panou-sursa="/admin/emails/history/vezi?id=' + id + '"]');
  await buton.click();
  const panou = pagina.locator('#email-vezi-panou');
  await panou.locator('.email-vezi').waitFor({ state: 'attached', timeout: 10000 });
  return panou;
}

async function cadrul(pagina, id) {
  const element = pagina.locator('#email-vezi-panou iframe.email-vezi__cadru');
  await element.waitFor({ state: 'attached', timeout: 10000 });
  const handle = await element.elementHandle();
  let frame = await handle.contentFrame();
  for (let i = 0; i < 50 && (!frame || !frame.url().includes('continut?id=' + id)); i++) {
    await pagina.waitForTimeout(100);
    frame = await handle.contentFrame();
  }
  if (frame) await frame.waitForLoadState('load');
  return { element, frame };
}

(async () => {
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: 1 });
    // Doar serverul de dezvoltare: imaginile de pe alte site-uri din emailuri nu pleacă nicăieri.
    await context.route('**/*', (ruta) => {
      const adresa = ruta.request().url();
      return adresa.startsWith(baza) || adresa.startsWith('data:') ? ruta.continue() : ruta.abort();
    });
    const pagina = await context.newPage();
    const erori = [];
    pagina.on('pageerror', (e) => erori.push(String(e)));
    let dialoguri = 0;
    pagina.on('dialog', (d) => { dialoguri++; d.dismiss().catch(() => {}); });

    await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
    await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
    await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
    await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.press('input[name="password"]', 'Enter')]);
    await pagina.goto(baza + '/admin/emails/newsletters?tab=history', { waitUntil: 'load' });
    const titlu = await pagina.title();
    const randuri = await pagina.locator('table.users-table tbody > tr').count();
    const butoane = await pagina.locator('table.users-table tbody > tr a[data-adm-panou="email-vezi-panou"]').count();
    nota('istoricul: butonul „Vezi” pe fiecare rând (' + butoane + ' din ' + randuri + ')', randuri > 0 && butoane === randuri);

    // Emailul de comandă păstrat.
    const idComanda = process.env.CMS_DEV_ID_COMANDA;
    await deschideRandul(pagina, idComanda);
    const { element, frame } = await cadrul(pagina, idComanda);
    const sandbox = await element.getAttribute('sandbox');
    const textCadru = frame ? await frame.evaluate(() => document.body.innerText) : '';
    nota('„Vezi” pe emailul de comandă: sertarul cu iframe-ul sandbox="" (fără allow-scripts), emailul păstrat în el',
      sandbox === '' && textCadru.includes(process.env.CMS_DEV_TEXT_COMANDA || '?'), JSON.stringify({ sandbox, text: textCadru.slice(0, 200) }));
    nota('… originea cadrului e opacă („null”), nu a adminului', frame && (await frame.evaluate(() => window.origin)) === 'null');
    if (capturi) {
      await pagina.evaluate(() => window.scrollTo(0, 0));
      await pagina.waitForTimeout(400); // după animația sertarului
      await pagina.screenshot({ path: path.join(capturi, 'email-previzualizare.png'), fullPage: false });
    }
    await pagina.keyboard.press('Escape');

    // Emailul cu script: nimic nu rulează, nici în cadru, nici în pagina adminului.
    const idScript = process.env.CMS_DEV_ID_SCRIPT;
    await deschideRandul(pagina, idScript);
    const cuScript = await cadrul(pagina, idScript);
    const stareScript = cuScript.frame ? await cuScript.frame.evaluate(() => document.getElementById('stare-script').textContent) : '';
    await pagina.waitForTimeout(300);
    nota('emailul cu <script> și onerror: în cadru nu rulează nimic (textul rămâne „NERULAT”), niciun dialog, titlul adminului neatins',
      stareScript === 'NERULAT' && dialoguri === 0 && (await pagina.title()) === titlu, JSON.stringify({ stareScript, dialoguri, titlu: await pagina.title() }));
    await pagina.keyboard.press('Escape');

    // Emailul de dinainte: mesajul, fără cadru.
    const idVechi = process.env.CMS_DEV_ID_VECHI;
    const panouVechi = await deschideRandul(pagina, idVechi);
    const textVechi = await panouVechi.innerText();
    nota('emailul de dinainte: „Conținutul nu a fost păstrat (email trimis înainte de …)”, fără iframe',
      textVechi.includes('Conținutul nu a fost păstrat (email trimis înainte de ') && (await panouVechi.locator('iframe').count()) === 0, textVechi.slice(0, 300));
    await pagina.keyboard.press('Escape');

    // Newsletterul: din campanie.
    const idNl = process.env.CMS_DEV_ID_NEWSLETTER;
    const panouNl = await deschideRandul(pagina, idNl);
    const nl = await cadrul(pagina, idNl);
    const textNl = nl.frame ? await nl.frame.evaluate(() => document.body.innerText) : '';
    nota('newsletterul: nota „conținutul campaniei” și conținutul campaniei în cadru',
      (await panouNl.innerText()).includes('conținutul campaniei') && textNl.includes(process.env.CMS_DEV_TEXT_NEWSLETTER || '?'), textNl.slice(0, 200));
    if (capturi) {
      await pagina.waitForTimeout(400);
      await pagina.screenshot({ path: path.join(capturi, 'email-previzualizare-newsletter.png'), fullPage: false });
    }
    await pagina.keyboard.press('Escape');
    nota('nicio eroare JavaScript în pagina adminului', erori.length === 0, erori.join(' | '));
  } catch (e) {
    nota('Chromium: ' + String(e && e.message ? e.message : e), false);
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
