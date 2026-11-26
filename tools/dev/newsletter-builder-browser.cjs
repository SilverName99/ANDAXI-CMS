// Builderul newsletterului în Chromium (tools/dev/test-newsletter.php, partea G), deviceScaleFactor 1, pe un newsletter nou
// făcut ca din admin („Newsletter nou”). Previzualizarea Desktop / Mobil (26 nov 2026): fiecare clic arată emailul într-un
// cadru izolat nou (Desktop → Mobil → Editare → Desktop → Mobil → Desktop), înălțimea e a emailului (nu crește la fiecare
// clic), doar ultimul clic se pune (un răspuns întârziat nu-l acoperă), o eroare se spune în loc de „Se generează…” fără
// sfârșit, iar pe un ecran îngust panoul se derulează în lateral în loc să taie emailul. Titlul (Header) pus într-o coloană a
// unei secțiuni (50% / 50%) se vede: pe pânză și în email nu mai e alb pe alb. Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL,
// CMS_DEV_PAROLA, CMS_DEV_CAPTURI (dosarul capturilor, opțional). Scrie pe stdout un JSON cu rezultatele.
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const capturi = process.env.CMS_DEV_CAPTURI || '';
const rezultate = [];
const nota = (eticheta, ok, detalii) => rezultate.push({ eticheta, ok: !!ok, detalii: detalii || '' });

// Starea previzualizării: cadrul de acum (marcat, ca să se vadă dacă e altul), mărimea lui, textul și înălțimea emailului.
async function stare(pagina) {
  const s = await pagina.evaluate(() => {
    const cadru = document.getElementById('eb-preview-iframe');
    const incarcare = document.getElementById('eb-preview-loading');
    const r = cadru.getBoundingClientRect();
    if (!cadru.dataset.testNr) cadru.dataset.testNr = String((window.__nrCadru = (window.__nrCadru || 0) + 1));
    return {
      nr: cadru.dataset.testNr,
      afisat: getComputedStyle(cadru).display !== 'none' && r.width > 0 && r.height > 0,
      latime: Math.round(r.width),
      inaltime: cadru.style.height,
      incarcare: getComputedStyle(incarcare).display === 'none' ? '' : incarcare.textContent,
    };
  });
  const element = await pagina.$('#eb-preview-iframe');
  const frame = element ? await element.contentFrame() : null;
  s.text = '';
  s.inaltimeEmail = 0;
  if (frame) {
    try {
      const d = await frame.evaluate(() => ({ text: document.body ? document.body.innerText : '', h: document.body ? Math.ceil(document.body.getBoundingClientRect().height) : 0 }));
      s.text = d.text;
      s.inaltimeEmail = d.h;
    } catch (e) {
      s.text = '';
    }
  }
  return s;
}

async function clic(pagina, mod, asteapta) {
  await pagina.click('.eb-preview-tabs button[data-preview="' + mod + '"]');
  if (mod === 'edit') return null;
  if (asteapta === false) return null;
  // Emailul în cadru și înălțimea trimisă de el (cadrul nou pornește de la 600px).
  await pagina.waitForFunction(() => {
    const c = document.getElementById('eb-preview-iframe');
    return c && getComputedStyle(c).display !== 'none' && c.style.height !== '600px';
  }, null, { timeout: 15000 });
  await pagina.waitForTimeout(200);
  return stare(pagina);
}

(async () => {
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    await context.route('**/*', (ruta) => {
      const adresa = ruta.request().url();
      return adresa.startsWith(baza) || adresa.startsWith('data:') ? ruta.continue() : ruta.abort();
    });
    const pagina = await context.newPage();
    const erori = [];
    pagina.on('pageerror', (e) => erori.push(String(e)));
    pagina.on('dialog', (d) => d.dismiss().catch(() => {}));

    await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
    await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro');
    await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
    await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.press('input[name="password"]', 'Enter')]);

    // Un newsletter nou, ca din butonul „Newsletter nou” (header, text și buton), fără șablon ales.
    await pagina.goto(baza + '/admin/emails/newsletters?tab=campaigns', { waitUntil: 'load' });
    await pagina.evaluate(() => {
      const f = document.querySelector('#campaign-create-modal form');
      f.campaign_name.value = 'Test builder (browser)';
      f.campaign_subject.value = 'Previzualizarea builderului';
    });
    await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.evaluate(() => document.querySelector('#campaign-create-modal form').requestSubmit())]);
    await pagina.waitForSelector('#eb-starter-modal', { state: 'visible', timeout: 5000 }).catch(() => {});
    await pagina.evaluate(() => window.ebCloseStarterModal && window.ebCloseStarterModal());

    // G1–G2: Desktop → Mobil → Editare → Desktop → Mobil → Desktop, înainte de orice salvare.
    const pasi = [];
    for (const mod of ['desktop', 'mobile', 'edit', 'desktop', 'mobile', 'desktop']) {
      const s = await clic(pagina, mod);
      if (s) pasi.push(Object.assign({ mod }, s));
      if (s && capturi && pasi.length <= 2) await pagina.screenshot({ path: path.join(capturi, 'builder-' + pasi.length + '-' + mod + '.png') });
    }
    const vede = pasi.every((s) => s.afisat && s.incarcare === '' && s.text.includes('Titlul Newsletter-ului') && s.text.includes('Află mai multe'));
    const latimi = pasi.every((s) => s.latime === (s.mod === 'mobile' ? 375 : 960));
    const noi = new Set(pasi.map((s) => s.nr)).size === pasi.length;
    nota('G1 Desktop → Mobil → Editare → Desktop → Mobil → Desktop pe un newsletter nesalvat: de fiecare dată emailul (titlul, butonul) '
      + 'într-un cadru vizibil de 960 / 375 px, fără „Se generează…”, și de fiecare dată un cadru nou', vede && latimi && noi,
      JSON.stringify(pasi.map((s) => ({ mod: s.mod, nr: s.nr, afisat: s.afisat, latime: s.latime, incarcare: s.incarcare, text: s.text.slice(0, 40) }))));
    const desktop = pasi.filter((s) => s.mod === 'desktop').map((s) => s.inaltime);
    const mobil = pasi.filter((s) => s.mod === 'mobile').map((s) => s.inaltime);
    nota('G2 înălțimea cadrului e a emailului (body), aceeași la fiecare Desktop și la fiecare Mobil (înainte creștea cu 10 px la fiecare '
      + 'clic și prima previzualizare rămânea la 600 px)', new Set(desktop).size === 1 && new Set(mobil).size === 1
      && pasi.every((s) => s.inaltime === s.inaltimeEmail + 'px'), JSON.stringify(pasi.map((s) => [s.mod, s.inaltime, s.inaltimeEmail])));

    // G3: răspunsurile întârziate. Primul răspuns (Desktop) vine după al doilea (Mobil): rămâne Mobil.
    let intarziat = true;
    await pagina.route('**/admin/emails/builder/preview', async (ruta) => {
      if (intarziat) {
        intarziat = false;
        await new Promise((r) => setTimeout(r, 1500));
      }
      await ruta.continue();
    });
    await clic(pagina, 'edit');
    await clic(pagina, 'desktop', false);
    await pagina.waitForTimeout(100);
    await clic(pagina, 'mobile', false);
    await pagina.waitForTimeout(2500);
    const dupaIntarziere = await stare(pagina);
    nota('G3 Desktop și imediat Mobil, cu răspunsul pentru Desktop venit ultimul: rămâne previzualizarea Mobil (375 px), nu cea de Desktop',
      dupaIntarziere.latime === 375 && dupaIntarziere.text.includes('Titlul Newsletter-ului'), JSON.stringify(dupaIntarziere));
    await pagina.unroute('**/admin/emails/builder/preview');

    // G4: o eroare (sesiunea expirată: serverul trimite la /admin/login) se spune omului.
    await pagina.route('**/admin/emails/builder/preview', (ruta) => ruta.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Login</title>' }));
    await clic(pagina, 'edit');
    await clic(pagina, 'desktop', false);
    await pagina.waitForTimeout(800);
    const eroare = await stare(pagina);
    nota('G4 un răspuns care nu e previzualizarea (sesiune expirată): mesajul de eroare, nu „Se generează previzualizarea…” fără sfârșit',
      eroare.incarcare !== '' && !eroare.incarcare.startsWith('Se generează') && !eroare.afisat, JSON.stringify(eroare));
    await pagina.unroute('**/admin/emails/builder/preview');

    // G5: Header în coloana din stânga a unei secțiuni 50% / 50%.
    await clic(pagina, 'edit');
    await pagina.locator('.eb-block-btn[data-block-type="columns_2"]').dragTo(pagina.locator('#eb-canvas .eb-drop-zone').last());
    await pagina.locator('.eb-block-btn[data-block-type="header"]').dragTo(pagina.locator('.eb-col-cell[data-col-ci="0"]').first());
    await pagina.waitForTimeout(300);
    const panza = await pagina.evaluate(() => {
      const celula = document.querySelector('.eb-col-cell[data-col-ci="0"]');
      const titlu = celula && celula.firstElementChild;
      const bloc = window.ebBuilder.blocuri().find((b) => b.type === 'columns_2');
      // Culoarea de dedesubt: primul strămoș cu fundal.
      let fundal = 'rgba(0, 0, 0, 0)';
      for (let el = titlu; el && fundal === 'rgba(0, 0, 0, 0)'; el = el.parentElement) fundal = getComputedStyle(el).backgroundColor;
      return { text: titlu ? titlu.innerText : '', culoare: titlu ? getComputedStyle(titlu).color : '', fundal, sub: bloc && bloc.columns && bloc.columns[0] && bloc.columns[0][0] };
    });
    if (capturi) {
      const card = pagina.locator('.eb-col-cell[data-col-ci="0"]').first().locator('xpath=ancestor::*[contains(@class,"eb-block-card")]');
      await card.screenshot({ path: path.join(capturi, 'builder-sectiune-header.png') }).catch(() => {});
    }
    const s5 = await clic(pagina, 'desktop');
    const element5 = await pagina.$('#eb-preview-iframe');
    const frame5 = element5 ? await element5.contentFrame() : null;
    const email5 = frame5 ? await frame5.evaluate(() => {
      const el = Array.from(document.querySelectorAll('.nl-col-div .nl-text-desktop')).find((x) => x.textContent.includes('Titlul tău'));
      if (!el) return null;
      let fundal = 'rgba(0, 0, 0, 0)';
      for (let p = el; p && fundal === 'rgba(0, 0, 0, 0)'; p = p.parentElement) fundal = getComputedStyle(p).backgroundColor;
      return { culoare: getComputedStyle(el).color, fundal };
    }) : null;
    nota('G5 Header pus în coloana din stânga a unei secțiuni 50% / 50%: textul „Titlul tău” se vede pe pânză (text închis pe coloana albă, '
      + 'nu alb pe alb) și la fel în email', panza.text.includes('Titlul tău') && panza.culoare !== panza.fundal && panza.culoare !== 'rgb(255, 255, 255)'
      && panza.sub && panza.sub.type === 'header' && panza.sub.text_color === '#1f2937' && s5 && email5 && email5.culoare !== email5.fundal,
      JSON.stringify({ panza, email5 }));

    // G6: pe un ecran de 1366 px panoul e mai îngust decât emailul de 960 px: se derulează în lateral, marginea din stânga se vede.
    await clic(pagina, 'edit');
    await pagina.setViewportSize({ width: 1366, height: 800 });
    await pagina.evaluate(() => window.dispatchEvent(new Event('resize')));
    await clic(pagina, 'desktop');
    const ingust = await pagina.evaluate(() => {
      const panou = document.getElementById('eb-preview-pane');
      const cadru = document.getElementById('eb-preview-frame');
      return { derulare: panou.scrollWidth - panou.clientWidth, stanga: Math.round(cadru.getBoundingClientRect().left - panou.getBoundingClientRect().left),
        latime: Math.round(cadru.getBoundingClientRect().width), pagina: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    nota('G6 la 1366 px: cadrul Desktop are 962 px (nu se strânge și nu taie emailul), panoul se derulează în lateral, marginea din stânga se vede, '
      + 'pagina nu se derulează în lateral', ingust.latime === 962 && ingust.derulare > 0 && ingust.stanga >= 0 && ingust.pagina === 0, JSON.stringify(ingust));

    nota('nicio eroare JavaScript în pagina adminului', erori.length === 0, erori.join(' | '));
  } catch (e) {
    nota('Chromium: ' + String(e && e.message ? e.message : e), false);
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
