// Plimbarea căutării în tot adminul și a scurtăturilor (faza D, WP-36; partea E din tools/dev/test-admin-faza-d.php). Pentru
// fiecare rol dat (administratorul general, magazin, blog, emailuri), logat în browser:
//  - Ctrl+K deschide fereastra căutării; fiecare caz (numărul unei comenzi demo, codul unui produs, un client, o pagină, o
//    postare) e scris în ea, iar grupurile arătate (data-sursa) se notează: testul le compară cu sursele pe care rolul are voie
//    să le vadă pe licența serverului;
//  - lista de la „?”: literele „G <literă>” și adresele lor;
//  - „G”, apoi prima literă din listă, duce la adresa ei.
// Doar pentru administratorul general, pe o licență cu magazin ("comenzi": true în intrare):
//  - Enter pe primul rezultat al numărului comenzii deschide lista comenzilor cu panoul comenzii; Esc închide fereastra căutării
//    și focusul revine pe elementul de dinainte;
//  - tastarea într-un câmp („?”, „g”, „c”, Ctrl+K) nu pornește nicio scurtătură: textul ajunge în câmp, nicio fereastră, aceeași
//    pagină;
//  - pe lista comenzilor, cu o comandă bifată: „G C” deschide Comenzi fără nicio confirmare și fără nicio cerere POST; „G” urmat
//    de o literă fără pagină nu face nimic; „C” singur cere tot confirmarea de până acum (refuzată aici).
// La final: răspunsul JSON al căutării fără sesiune (401). Orice cerere spre alt server e blocată și raportată; orice POST e
// oprit înainte de server și raportat. Variabile: CMS_DEV_URL, CMS_DEV_PAROLA, CMS_DEV_PLIMBARE (JSON de intrare: {"cazuri":
// [{"nume", "q"}], "roluri": [{"rol", "email"}], "comanda": "<număr>", "comenzi": true|false, "campText": {"pagina", "selector"}}),
// CMS_DEV_REZULTAT (JSON de ieșire). Nu scrie nimic în bază.
'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const parola = process.env.CMS_DEV_PAROLA || '';
const intrare = JSON.parse(fs.readFileSync(process.env.CMS_DEV_PLIMBARE, 'utf8'));
const iesire = process.env.CMS_DEV_REZULTAT;
const gazdaLocala = new URL(baza).host;

async function cautaInFereastra(page, q) {
  await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
  await page.keyboard.press('Control+k');
  await page.waitForSelector('#ax-cautare[open]', { timeout: 3000 });
  await page.fill('#ax-cautare-camp', q);
  await page.waitForFunction((text) => {
    const s = document.getElementById('ax-cautare-stare');
    return s && /pentru „/.test(s.textContent) && s.textContent.indexOf('„' + text + '”') !== -1;
  }, q, { timeout: 5000 });
  return page.evaluate(() => Array.from(document.querySelectorAll('#ax-cautare-rezultate [data-sursa]'))
    .map((g) => ({ sursa: g.getAttribute('data-sursa'), rezultate: Array.from(g.querySelectorAll('a.ax-paleta-rezultat')).map((a) => a.getAttribute('href')) })));
}

(async () => {
  if (!parola) throw new Error('Lipsește CMS_DEV_PAROLA.');
  const browser = await chromium.launch();
  const rez = { roluri: {}, general: {}, probleme: [], blocate: [], postari: [] };
  const blocate = new Set();
  for (const r of intrare.roluri) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    await context.route('**/*', (ruta) => {
      const url = new URL(ruta.request().url());
      if (url.protocol !== 'data:' && url.protocol !== 'blob:' && url.protocol !== 'about:' && url.host !== gazdaLocala) {
        blocate.add(url.host);
        return ruta.abort();
      }
      // După logare nimic din plimbare nu are voie să trimită ceva (o scurtătură care ar porni o acțiune s-ar vedea aici).
      if (ruta.request().method() === 'POST' && !url.pathname.startsWith('/admin/login')) {
        rez.postari.push(r.rol + ' ' + url.pathname);
        return ruta.abort();
      }
      return ruta.continue();
    });
    const page = await context.newPage();
    const erori = [];
    const confirmari = [];
    page.on('pageerror', (e) => erori.push(String(e.message).split('\n')[0]));
    page.on('dialog', (d) => { confirmari.push(d.message()); d.dismiss(); });
    await page.goto(baza + '/admin/login');
    await page.fill('input[name="email"]', r.email);
    await page.fill('input[name="password"]', parola);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/admin/login')),
      page.click('form[action="/admin/login"] [type="submit"], form[action="/admin/login"] button')]);
    const start = new URL(page.url()).pathname;
    const dateRol = { start, cazuri: {}, salturi: [], salt: null, erori };

    for (const caz of intrare.cazuri) {
      try {
        dateRol.cazuri[caz.nume] = await cautaInFereastra(page, caz.q);
      } catch (e) {
        rez.probleme.push(r.rol + ': căutarea „' + caz.q + '” nu a primit rezultate (' + String(e.message).split('\n')[0] + ')');
      }
      await page.keyboard.press('Escape');
      if (await page.$('#ax-cautare[open]')) rez.probleme.push(r.rol + ': Esc nu a închis fereastra căutării');
    }

    // Lista de la „?”.
    await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
    await page.keyboard.press('Shift+?');
    await page.waitForSelector('#ax-taste[open]', { timeout: 3000 }).catch(() => rez.probleme.push(r.rol + ': „?” nu a deschis lista'));
    dateRol.salturi = await page.evaluate(() => Array.from(document.querySelectorAll('#ax-taste [data-ax-salt]'))
      .map((d) => ({ litera: d.getAttribute('data-ax-salt'), url: d.getAttribute('data-ax-salt-adresa'), text: d.textContent.replace(/\s+/g, ' ').trim() })));
    dateRol.textTaste = await page.evaluate(() => document.getElementById('ax-taste').innerText);
    await page.keyboard.press('Escape');

    // „G” + prima literă.
    if (dateRol.salturi.length) {
      const tinta = dateRol.salturi[dateRol.salturi.length - 1];
      await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
      await page.keyboard.press('g');
      await Promise.all([page.waitForURL((u) => u.pathname + u.search === tinta.url, { timeout: 5000 }).catch(() => null), page.keyboard.press(tinta.litera)]);
      const u = new URL(page.url());
      dateRol.salt = { litera: tinta.litera, asteptat: tinta.url, ajuns: u.pathname + u.search };
    }

    if (r.rol === 'adm' && intrare.comenzi) {
      const g = rez.general;
      // Enter pe primul rezultat al comenzii: lista comenzilor cu panoul ei.
      await page.goto(baza + '/admin', { waitUntil: 'load' });
      const butonAntet = await page.$('[data-ax-cautare]');
      await butonAntet.focus();
      await page.keyboard.press('Enter');
      await page.waitForSelector('#ax-cautare[open]', { timeout: 3000 }).catch(() => rez.probleme.push('clicul (Enter) pe căutarea din antet nu a deschis fereastra'));
      await page.keyboard.press('Escape');
      g.focusInapoi = await page.evaluate(() => !!(document.activeElement && document.activeElement.hasAttribute('data-ax-cautare')));
      await cautaInFereastra(page, intrare.comanda);
      g.primul = await page.evaluate(() => {
        const a = document.querySelector('#ax-cautare-rezultate a.ax-paleta-rezultat[aria-selected="true"]');
        return a ? a.getAttribute('href') : null;
      });
      await Promise.all([page.waitForURL(/\/admin\/orders\?q=/, { timeout: 5000 }).catch(() => null), page.keyboard.press('Enter')]);
      await page.waitForSelector('.adm-sertar:not([hidden])', { timeout: 5000 }).catch(() => null);
      g.dupaEnter = { url: new URL(page.url()).pathname + new URL(page.url()).search, panou: !!(await page.$('.adm-sertar:not([hidden])')) };
      await page.keyboard.press('Escape');

      // Tastarea într-un câmp: nicio scurtătură.
      await page.goto(baza + intrare.campText.pagina, { waitUntil: 'load' });
      const camp = await page.$(intrare.campText.selector);
      if (!camp) {
        rez.probleme.push('câmpul de text ' + intrare.campText.selector + ' lipsește pe ' + intrare.campText.pagina);
      } else {
        await camp.fill('');
        await camp.focus();
        const inainte = page.url();
        await page.keyboard.type('?gc');
        await page.keyboard.press('Control+k');
        await page.keyboard.type('gp');
        await page.waitForTimeout(1700);
        g.inCamp = { valoare: await camp.inputValue(), deschise: await page.$$eval('dialog[open]', (d) => d.map((x) => x.id)), aceeasiPagina: page.url() === inainte,
          nota: await page.evaluate(() => { const n = document.querySelector('.ax-nota-salt'); return !!(n && !n.hidden); }) };
      }

      // Lista comenzilor, o comandă bifată: „G C”, „G” + o literă fără pagină, apoi „C” singur.
      await page.goto(baza + '/admin/orders', { waitUntil: 'load' });
      const bifa = await page.$('input.adm-tabel__rand:not(:disabled)');
      if (!bifa) {
        rez.probleme.push('lista comenzilor nu are nicio comandă de bifat');
      } else {
        await bifa.check();
        await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
        const confirmariInainte = confirmari.length;
        await page.keyboard.press('g');
        await page.keyboard.press('x');
        await page.waitForTimeout(300);
        g.gNecunoscut = { confirmari: confirmari.length - confirmariInainte, url: new URL(page.url()).pathname, bifata: await bifa.isChecked() };
        await page.keyboard.press('g');
        await Promise.all([page.waitForEvent('load', { timeout: 5000 }).catch(() => null), page.keyboard.press('c')]);
        g.gc = { confirmari: confirmari.length - confirmariInainte, url: new URL(page.url()).pathname };
        const bifa2 = await page.$('input.adm-tabel__rand:not(:disabled)');
        await bifa2.check();
        await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
        const inainteC = confirmari.length;
        await page.keyboard.press('c');
        await page.waitForTimeout(300);
        g.cSingur = { confirmari: confirmari.slice(inainteC), url: new URL(page.url()).pathname };
      }
    }
    rez.roluri[r.rol] = dateRol;
    await context.close();
  }

  // Fără sesiune: JSON-ul căutării nu dă nimic.
  const anonim = await browser.newContext();
  const raspuns = await anonim.request.get(baza + '/admin/cauta?q=' + encodeURIComponent(intrare.comanda), { headers: { Accept: 'application/json' }, maxRedirects: 0 });
  rez.anonim = { cod: raspuns.status(), corp: (await raspuns.text()).slice(0, 300) };
  await anonim.close();
  await browser.close();
  rez.blocate = [...blocate].sort();
  fs.writeFileSync(iesire, JSON.stringify(rez));
})().catch((e) => {
  console.error('EROARE ' + (e && e.message ? e.message : e));
  process.exit(1);
});
