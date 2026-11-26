// Builderul newsletterului în Chromium (tools/dev/test-newsletter.php, partea G), deviceScaleFactor 1, pe un newsletter nou
// făcut ca din admin („Newsletter nou”). Varianta „C – clasic, curățat” (26 nov 2026): previzualizarea Desktop / Mobil în același
// loc ca pânza (680 / 375 px, emailul randat de server, de fiecare dată într-un cadru izolat nou), pânza identică cu emailul
// (blocurile lipite, aceeași înălțime ca emailul randat), eticheta blocului ales și într-o coloană, scrisul pe pânză (bara
// B / I / U / link / culoare) cu textul curățat, încărcarea pozelor prin tragere și lipire, Anulează / Refă, salvarea automată,
// tastatura, 1366 px. Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_CAPTURI (dosarul capturilor,
// opțional). Scrie pe stdout un JSON cu rezultatele.
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
  s.card = 0;
  s.html = '';
  if (frame) {
    try {
      const d = await frame.evaluate(() => ({ text: document.body ? document.body.innerText : '', h: document.body ? Math.ceil(document.body.getBoundingClientRect().height) : 0,
        card: document.querySelector('.nl-card') ? document.querySelector('.nl-card').getBoundingClientRect().height : 0, html: document.documentElement.outerHTML }));
      s.text = d.text;
      s.inaltimeEmail = d.h;
      s.card = d.card;
      s.html = d.html;
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

const blocuri = (pagina) => pagina.evaluate(() => window.ebBuilder.blocuri());
const indexTip = async (pagina, tip, ultimul) => {
  const b = (await blocuri(pagina)).map((x) => x.type);
  return ultimul ? b.lastIndexOf(tip) : b.indexOf(tip);
};

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
    let raspunsPrompt = null;
    // „Continui?” (un șablon peste conținut, plecarea cu modificări): da; linkul din bara de text: adresa testului.
    pagina.on('dialog', (d) => (d.type() === 'prompt' ? (raspunsPrompt !== null ? d.accept(raspunsPrompt) : d.dismiss())
      : (d.type() === 'confirm' || d.type() === 'beforeunload' ? d.accept() : d.dismiss())).catch(() => {}));

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
    const adresaBuilder = pagina.url();
    const idCampanie = Number(new URL(adresaBuilder).searchParams.get('id'));

    // G1–G2: Desktop → Mobil → Editez → Desktop → Mobil → Desktop, înainte de orice salvare.
    const pasi = [];
    for (const mod of ['desktop', 'mobile', 'edit', 'desktop', 'mobile', 'desktop']) {
      const s = await clic(pagina, mod);
      if (s) pasi.push(Object.assign({ mod }, s));
    }
    const vede = pasi.every((s) => s.afisat && s.incarcare === '' && s.text.includes('Titlul Newsletter-ului') && s.text.includes('Află mai multe'));
    const latimi = pasi.every((s) => s.latime === (s.mod === 'mobile' ? 375 : 680));
    const noi = new Set(pasi.map((s) => s.nr)).size === pasi.length;
    const locPanza = await pagina.evaluate(() => ({ panza: document.getElementById('eb-canvas-scroll').hidden, pane: document.getElementById('eb-preview-pane').hidden }));
    nota('G1 Desktop → Mobil → Editez → Desktop → Mobil → Desktop pe un newsletter nesalvat: de fiecare dată emailul (titlul, butonul) '
      + 'într-un cadru izolat nou, vizibil, de 680 / 375 px (în locul pânzei), fără „Se generează…”', vede && latimi && noi && locPanza.panza && !locPanza.pane,
      JSON.stringify(pasi.map((s) => ({ mod: s.mod, nr: s.nr, afisat: s.afisat, latime: s.latime, incarcare: s.incarcare, text: s.text.slice(0, 40) }))));
    const desktop = pasi.filter((s) => s.mod === 'desktop').map((s) => s.inaltime);
    const mobil = pasi.filter((s) => s.mod === 'mobile').map((s) => s.inaltime);
    nota('G2 înălțimea cadrului e a emailului (body), aceeași la fiecare Desktop și la fiecare Mobil', new Set(desktop).size === 1 && new Set(mobil).size === 1
      && pasi.every((s) => s.inaltime === s.inaltimeEmail + 'px') && (await pagina.$eval('#eb-preview-iframe', (c) => c.getAttribute('sandbox'))) === 'allow-scripts',
      JSON.stringify(pasi.map((s) => [s.mod, s.inaltime, s.inaltimeEmail])));

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
      dupaIntarziere.latime === 375 && dupaIntarziere.text.includes('Titlul Newsletter-ului'), JSON.stringify(Object.assign({}, dupaIntarziere, { html: '' })));
    await pagina.unroute('**/admin/emails/builder/preview');

    // G4: o eroare (sesiunea expirată: serverul trimite la /admin/login) se spune omului.
    await pagina.route('**/admin/emails/builder/preview', (ruta) => ruta.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Login</title>' }));
    await clic(pagina, 'edit');
    await clic(pagina, 'desktop', false);
    await pagina.waitForTimeout(800);
    const eroare = await stare(pagina);
    nota('G4 un răspuns care nu e previzualizarea (sesiune expirată): mesajul de eroare, nu „Se generează previzualizarea…” fără sfârșit',
      eroare.incarcare !== '' && !eroare.incarcare.startsWith('Se generează') && !eroare.afisat, JSON.stringify(Object.assign({}, eroare, { html: '' })));
    await pagina.unroute('**/admin/emails/builder/preview');
    await clic(pagina, 'edit');

    // G5: Titlu (Header) tras în coloana din stânga a unei secțiuni 2 coloane: se vede, iar blocul ales are eticheta „Titlu” vizibilă.
    await pagina.locator('.eb-block-btn[data-block-type="columns_2"]').dragTo(pagina.locator('#eb-canvas'), { targetPosition: { x: 300, y: 5 } });
    await pagina.waitForTimeout(200);
    const iCol = await indexTip(pagina, 'columns_2');
    await pagina.locator('.eb-block-btn[data-block-type="header"]').dragTo(pagina.locator('.eb-col-cell[data-col-ci="0"]').first());
    await pagina.waitForTimeout(300);
    const panza = await pagina.evaluate((i) => {
      const sub = document.querySelector('.eb-sub.is-sel');
      const titlu = sub && sub.querySelector('[data-edit]');
      const et = sub && sub.querySelector('.eb-eticheta');
      const bloc = window.ebBuilder.blocuri()[i];
      let fundal = 'rgba(0, 0, 0, 0)';
      for (let el = titlu; el && fundal === 'rgba(0, 0, 0, 0)'; el = el.parentElement) fundal = getComputedStyle(el).backgroundColor;
      const r = et ? et.getBoundingClientRect() : null;
      // Eticheta nu primește clicuri (pointer-events: none); pentru întrebarea „ce e deasupra în acel punct” le primește o clipă.
      if (et) et.style.pointerEvents = 'auto';
      const sus = r ? document.elementFromPoint(r.left + 3, r.top + 3) : null;
      if (et) et.style.pointerEvents = '';
      return { text: titlu ? titlu.innerText : '', culoare: titlu ? getComputedStyle(titlu).color : '', fundal, sub: bloc && bloc.columns && bloc.columns[0] && bloc.columns[0][0],
        eticheta: et ? et.textContent : '', etichetaVizibila: !!(r && r.width > 10 && r.height > 8 && sus && et.contains(sus)), panou: document.getElementById('eb-props-titlu').textContent };
    }, iCol);
    if (capturi) await pagina.locator('.eb-sub.is-sel').first().screenshot({ path: path.join(capturi, 'builder-coloana-titlu.png') }).catch(() => {});
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
    nota('G5 Titlu tras în coloana din stânga a unei secțiuni 2 coloane: textul se vede pe pânză și în email (text închis pe coloana albă), iar '
      + 'blocul ales din coloană are eticheta „Titlu” vizibilă (deasupra lui, nu ascunsă) și panoul lui', panza.text.includes('Titlul tău')
      && panza.culoare !== panza.fundal && panza.culoare !== 'rgb(255, 255, 255)' && panza.sub && panza.sub.type === 'header' && panza.sub.text_color === '#1f2937'
      && panza.eticheta === 'Titlu' && panza.etichetaVizibila && panza.panou.startsWith('Titlu') && s5 && email5 && email5.culoare !== email5.fundal,
      JSON.stringify({ panza, email5 }));
    await clic(pagina, 'edit');

    // G6: pânza e emailul: blocurile lipite (fără nicio bandă gri între ele) și aceeași înălțime ca emailul randat de server la 680 px.
    // Fără produse (se aleg la trimitere): un logo, un meniu, titlul, o poză cu o linie albă de 1 px sus (încărcată în galerie, ca un om),
    // textul, butonul, codul; puse ca un șablon ales (ebApplyStarter), deci prin istoric, ca orice schimbare.
    const pozaLinie = await pagina.evaluate(async (csrf) => {
      const c = document.createElement('canvas');
      c.width = 600; c.height = 120;
      const x = c.getContext('2d');
      x.fillStyle = '#ffffff'; x.fillRect(0, 0, 600, 1);
      x.fillStyle = '#2f6b4f'; x.fillRect(0, 1, 600, 119);
      const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
      const fd = new FormData();
      fd.append('raspuns', 'json');
      fd.append('image_files[]', new File([blob], 'banner-linie-alba.png', { type: 'image/png' }));
      const r = await fetch('/admin/gallery/bulk-upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
      const d = await r.json();
      return d && d.imagini && d.imagini[0] ? d.imagini[0].url : '';
    }, await pagina.evaluate(() => window.EB_DATE.csrf));
    await pagina.evaluate((poza) => {
      window.EB_DATE.sabloane.__test = [
        { type: 'logo', background: '#ffffff', width: 160, padding_top: 20, padding_bottom: 16, text_color: '#0f766e', font_size: 26 },
        { type: 'menu', background: '#2f6b4f', text_color: '#ffffff', items: [{ label: 'Ceaiuri', url: '/categorie/ceaiuri' }, { label: 'Miere', url: '/categorie/miere' }], padding_top: 8, padding_bottom: 8 },
        { type: 'header', content: 'Toamna vine cu ceaiuri noi', align: 'center', font_size: 30, font_size_mobile: 22, background: '#cfe0c3', text_color: '#1f3d2e', padding_top: 28, padding_bottom: 28 },
        { type: 'image', image_url: poza, alt: 'banner', width: 100, background: '#2f6b4f' },
        { type: 'text', content: 'Am pregătit <b>trei</b> produse pentru serile mai reci.\nPână duminică ai transport gratuit.', align: 'center', font_size: 15, background: '#ffffff', text_color: '#374151', padding_top: 20, padding_bottom: 20, padding_left: 32, padding_right: 32 },
        { type: 'button', label: 'Vezi oferta', url: '/magazin', background: '#ffffff', button_color: '#2f6b4f', padding_bottom: 20 },
        { type: 'coupon', code: 'TOAMNA20', content: 'Folosește codul:', background: '#f6f8f5', padding_top: 16, padding_bottom: 16 },
      ];
      window.ebApplyStarter('magazin:__test');
    }, pozaLinie);
    await pagina.waitForTimeout(600);
    const lipit = await pagina.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('#eb-canvas > .eb-bloc'));
      const goluri = [];
      for (let k = 1; k < cards.length; k++) goluri.push(Math.round((cards[k].getBoundingClientRect().top - cards[k - 1].getBoundingClientRect().bottom) * 100) / 100);
      const email = document.getElementById('eb-canvas');
      const re = email.getBoundingClientRect();
      // Linia albă de sus a pozei: pixelul de sub marginea de sus a blocului Imagine e alb (nu gri, nu acoperit).
      const img = cards[3].querySelector('img');
      return { goluri, inaltime: re.height, n: cards.length, primSus: Math.round(cards[0].getBoundingClientRect().top - re.top), ultimJos: Math.round(re.bottom - cards[cards.length - 1].getBoundingClientRect().bottom),
        imgSus: img ? Math.round(img.getBoundingClientRect().top - cards[3].getBoundingClientRect().top) : -1, imgLat: img ? Math.round(img.getBoundingClientRect().width) : 0 };
    });
    if (capturi) await pagina.locator('#eb-canvas').screenshot({ path: path.join(capturi, 'builder-panza.png') }).catch(() => {});
    const s6 = await clic(pagina, 'desktop');
    nota('G6 pânza arată emailul: blocurile lipite (0 px între oricare două, 0 px sus și jos), poza cu linia ei albă de 1 px lipită de blocul de '
      + 'deasupra, pe toată lățimea, iar înălțimea emailului de pe pânză = înălțimea emailului randat de server la 680 px (±1 px)',
      /^\/uploads\/gallery\//.test(pozaLinie) && lipit.n === 7 && lipit.goluri.every((g) => g === 0) && lipit.primSus === 1 && lipit.ultimJos === 1 && lipit.imgSus === 0 && lipit.imgLat === 678
      && s6 && Math.abs((lipit.inaltime) - s6.card) <= 1, JSON.stringify({ lipit, card: s6 && s6.card }));
    await clic(pagina, 'edit');

    // G7: scrisul pe pânză. Clic pe text, scris, Îngroșat, Enter, link (din bara), culoare; lipirea aduce doar text; serverul curăță.
    const iText = await indexTip(pagina, 'text');
    await pagina.click(`#eb-canvas [data-block-index="${iText}"] [data-edit]`);
    await pagina.waitForTimeout(150);
    const bara = await pagina.evaluate(() => !document.getElementById('eb-bara-text').hidden);
    await pagina.keyboard.press('Control+A');
    await pagina.keyboard.type('Bună ziua');
    await pagina.keyboard.press('Shift+Home');
    await pagina.click('#eb-bara-text [data-cmd="bold"]');
    await pagina.keyboard.press('End');
    await pagina.click('#eb-bara-text [data-cmd="bold"]');
    await pagina.keyboard.press('Enter');
    await pagina.keyboard.type('vezi oferta');
    for (let k = 0; k < 'oferta'.length; k++) await pagina.keyboard.press('Shift+ArrowLeft');
    raspunsPrompt = 'https://exemplu.ro/oferta?a=1&b=2';
    await pagina.click('#eb-bara-text [data-cmd="link"]');
    raspunsPrompt = null;
    await pagina.keyboard.press('End');
    await pagina.keyboard.type(' azi');
    for (let k = 0; k < 'azi'.length; k++) await pagina.keyboard.press('Shift+ArrowLeft');
    await pagina.waitForTimeout(100);
    await pagina.$eval('#eb-bara-culoare', (el) => { el.value = '#cc0000'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
    await pagina.keyboard.press('End');
    // Lipirea: HTML cu un script și o poză cu onerror ajunge ca text simplu.
    await pagina.evaluate(() => {
      const el = document.querySelector('#eb-canvas [contenteditable="true"]');
      const dt = new DataTransfer();
      dt.setData('text/html', '<img src=x onerror="window.__xss=1"><b onmouseover="window.__xss=2">lipit</b>');
      dt.setData('text/plain', ' <img src=x onerror=alert(1)>');
      el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    });
    await pagina.waitForTimeout(200);
    const scris = (await blocuri(pagina))[iText].content;
    const asteptat = '<b>Bună ziua</b>\nvezi <a href="https://exemplu.ro/oferta?a=1&b=2">oferta</a> <span style="color:#cc0000">azi <img src=x onerror=alert(1)></span>';
    if (capturi) await pagina.screenshot({ path: path.join(capturi, 'builder-scris.png') });
    await pagina.keyboard.press('Escape');
    const s7 = await clic(pagina, 'desktop');
    const xss = await pagina.evaluate(() => window.__xss || 0);
    nota('G7 scrisul pe pânză: bara B / I / U / link / culoare apare, textul ajunge în bloc în formatul de până acum (îngroșat, rând nou, link cu &, '
      + 'culoarea ca <span style="color:#…">); lipirea aduce doar text; emailul randat de server are etichetele, fără <img> și fără on…, nimic rulat',
      bara && scris === asteptat && xss === 0 && s7 && s7.html.includes('<b>Bună ziua</b><br>vezi <a href="https://exemplu.ro/oferta?a=1&amp;b=2">oferta</a> <span style="color:#cc0000">azi &lt;img src=x onerror=alert(1)&gt;</span>')
      && !/<img[^>]*onerror/i.test(s7.html), JSON.stringify({ bara, scris, xss, html: s7 && (s7.html.match(/<b>Bună[^\n]{0,300}/) || [''])[0] }));
    await clic(pagina, 'edit');
    // Panoul (câmpul „Textul”) și pânza arată același text; un text pus în panou cu cod nu rulează pe pânză.
    await pagina.click(`#eb-canvas [data-block-index="${iText}"]`, { position: { x: 5, y: 5 } });
    await pagina.fill('#eb-props-body textarea[data-k="content"]', 'X <b onmouseover="window.__xss=3">y</b> <img src=x onerror="window.__xss=4"> <a href="javascript:window.__xss=5">z</a>');
    await pagina.waitForTimeout(200);
    const panzaRau = await pagina.evaluate((i) => {
      const el = document.querySelector(`#eb-canvas [data-block-index="${i}"] [data-edit]`);
      el.querySelectorAll('b,a').forEach((x) => { x.dispatchEvent(new MouseEvent('mouseover', { bubbles: true })); x.click && x.click(); });
      return { img: el.querySelectorAll('img').length, on: Array.from(el.querySelectorAll('*')).some((x) => Array.from(x.attributes).some((a) => /^on/i.test(a.name))),
        href: Array.from(el.querySelectorAll('a')).map((a) => a.getAttribute('href')), xss: window.__xss || 0 };
    }, iText);
    nota('G8 un text cu cod scris în panou nu rulează pe pânză: fără <img>, fără atribute on…, linkul javascript: devine „#”',
      panzaRau.img === 0 && !panzaRau.on && panzaRau.href.every((h) => h === '#') && panzaRau.xss === 0, JSON.stringify(panzaRau));

    // G9: pozele prin tragere (în panoul blocului Imagine și în „Alege imagine”) și prin lipire: intră în galerie, ajung în bloc.
    const pozaFisier = async (unde, cum) => pagina.evaluate(async ({ unde, cum }) => {
      const c = document.createElement('canvas');
      c.width = 30; c.height = 20;
      const x = c.getContext('2d'); x.fillStyle = cum === 'lipire' ? '#c2410c' : '#1d4ed8'; x.fillRect(0, 0, 30, 20);
      const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
      const dt = new DataTransfer();
      dt.items.add(new File([blob], 'poza-' + cum + '.png', { type: 'image/png' }));
      if (cum === 'lipire') {
        document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
      } else {
        const el = document.querySelector(unde);
        el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
        el.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
      }
    }, { unde, cum });
    const iImg = await indexTip(pagina, 'image');
    await pagina.click(`#eb-canvas [data-block-index="${iImg}"]`);
    await pagina.waitForSelector('#eb-props-body [data-drop-poza="image_url"]');
    await pozaFisier('#eb-props-body [data-drop-poza="image_url"]', 'tragere');
    await pagina.waitForFunction((i) => /poza-tragere/.test(window.ebBuilder.blocuri()[i].image_url || ''), iImg, { timeout: 10000 }).catch(() => {});
    const dupaTragere = (await blocuri(pagina))[iImg].image_url;
    await pagina.click('#eb-props-body [data-alege-galerie="image_url"]');
    await pagina.waitForSelector('#eb-img-modal', { state: 'visible' });
    await pozaFisier('#eb-img-drop', 'fereastra');
    await pagina.waitForFunction((i) => /poza-fereastra/.test(window.ebBuilder.blocuri()[i].image_url || ''), iImg, { timeout: 10000 }).catch(() => {});
    const dupaFereastra = (await blocuri(pagina))[iImg].image_url;
    const fereastraInchisa = await pagina.$eval('#eb-img-modal', (m) => m.style.display === 'none');
    await pagina.click('#eb-subiect-bara');
    await pagina.click(`#eb-canvas [data-block-index="${iImg}"]`);
    await pagina.locator(`#eb-canvas [data-block-index="${iImg}"]`).focus();
    await pozaFisier(null, 'lipire');
    await pagina.waitForFunction((i) => /poza-lipire/.test(window.ebBuilder.blocuri()[i].image_url || ''), iImg, { timeout: 10000 }).catch(() => {});
    const dupaLipire = (await blocuri(pagina))[iImg].image_url;
    const inGalerie = await pagina.$$eval('#eb-gallery-grid .eb-gallery-img', (x) => x.map((b) => b.dataset.url));
    nota('G9 pozele: trasă în „Trage poza aici” (panoul blocului), trasă în fereastra „Alege imagine”, lipită cu Ctrl+V pe blocul ales; fiecare '
      + 'se încarcă în galerie (aceeași rută și verificare ca Galerie) și ajunge în bloc', /^\/uploads\/gallery\/poza-tragere/.test(dupaTragere)
      && /^\/uploads\/gallery\/poza-fereastra/.test(dupaFereastra) && fereastraInchisa && /^\/uploads\/gallery\/poza-lipire/.test(dupaLipire)
      && [dupaTragere, dupaFereastra, dupaLipire].every((u) => inGalerie.includes(u)), JSON.stringify({ dupaTragere, dupaFereastra, dupaLipire }));

    // G10: Anulează / Refă (butoanele și Ctrl+Z / Ctrl+Y).
    const inainte = (await blocuri(pagina)).length;
    await pagina.click('.eb-block-btn[data-block-type="spacer"]');
    const dupaAdaugare = (await blocuri(pagina)).length;
    await pagina.click('#btn-undo');
    const dupaUndo = (await blocuri(pagina)).length;
    await pagina.click('#btn-redo');
    const dupaRedo = (await blocuri(pagina)).length;
    await pagina.click('#eb-subiect-bara');
    await pagina.locator('#btn-undo').focus();
    await pagina.keyboard.press('Escape');
    await pagina.evaluate(() => document.activeElement && document.activeElement.blur());
    await pagina.keyboard.press('Control+z');
    const dupaCtrlZ = (await blocuri(pagina)).length;
    nota('G10 Anulează / Refă: un bloc adăugat dispare cu Anulează, revine cu Refă, dispare cu Ctrl+Z', dupaAdaugare === inainte + 1 && dupaUndo === inainte
      && dupaRedo === inainte + 1 && dupaCtrlZ === inainte, JSON.stringify([inainte, dupaAdaugare, dupaUndo, dupaRedo, dupaCtrlZ]));

    // G11: tastatura: un bloc din paletă se adaugă cu Enter, un bloc al emailului se alege cu Enter, tab-urile panoului merg cu săgețile.
    await pagina.focus('.eb-block-btn[data-block-type="divider"]');
    await pagina.keyboard.press('Enter');
    await pagina.waitForTimeout(150);
    const cuDivider = (await blocuri(pagina)).some((b) => b.type === 'divider');
    const focusDupa = await pagina.evaluate(() => document.activeElement && document.activeElement.classList.contains('eb-bloc') && document.activeElement.classList.contains('is-sel'));
    const iLogo = await indexTip(pagina, 'logo');
    await pagina.focus(`#eb-canvas [data-block-index="${iLogo}"]`);
    await pagina.keyboard.press('Enter');
    await pagina.waitForTimeout(150);
    const titluPanou = await pagina.textContent('#eb-props-titlu');
    await pagina.focus('#eb-tab-continut');
    await pagina.keyboard.press('ArrowRight');
    await pagina.waitForTimeout(100);
    const tabAles = await pagina.evaluate(() => ({ activ: document.activeElement.id, selectat: document.getElementById('eb-tab-aspect').getAttribute('aria-selected') }));
    const etichete = await pagina.evaluate(() => Array.from(document.querySelectorAll('.eb-wrap button')).filter((b) => b.offsetParent !== null)
      .filter((b) => !(b.textContent.trim() || b.getAttribute('aria-label') || b.title)).length);
    nota('G11 tastatura: Enter pe un bloc din paletă îl adaugă și mută focusul pe el; Enter pe un bloc al emailului îl alege (panoul lui); '
      + 'săgeata mută tab-ul panoului (Conținut → Aspect); fiecare buton vizibil are un nume', cuDivider && focusDupa && titluPanou.startsWith('Logo')
      && tabAles.activ === 'eb-tab-aspect' && tabAles.selectat === 'true' && etichete === 0, JSON.stringify({ cuDivider, focusDupa, titluPanou, tabAles, etichete }));

    // G12: salvarea automată a ciornei: indicatorul trece prin „nesalvat”, apoi „Salvat la …”, iar baza are blocurile de pe ecran.
    await pagina.click('.eb-block-btn[data-block-type="spacer"]');
    const nesalvat = await pagina.$eval('#eb-stare-salvare', (e) => e.dataset.stare);
    await pagina.waitForFunction(() => document.getElementById('eb-stare-salvare').dataset.stare === 'salvat', null, { timeout: 12000 }).catch(() => {});
    const salvat = await pagina.$eval('#eb-stare-salvare', (e) => ({ stare: e.dataset.stare, text: e.textContent }));
    const peEcran = (await blocuri(pagina)).length;
    const dinBaza = await pagina.evaluate(async (id) => {
      const r = await fetch('/admin/emails/builder?type=campaign&id=' + id, { credentials: 'same-origin' });
      const t = await r.text();
      const m = t.match(/window\.EB_DATE = (\{.*\});/);
      return m ? JSON.parse(m[1]).blocuri.length : -1;
    }, idCampanie);
    nota('G12 salvarea automată a ciornei: după o schimbare „nesalvat”, apoi singur „Salvat la …”; ce e în bază e ce e pe ecran',
      nesalvat === 'nesalvat' && salvat.stare === 'salvat' && /Salvat la/.test(salvat.text) && dinBaza === peEcran, JSON.stringify({ nesalvat, salvat, peEcran, dinBaza }));

    // G13: la 1366 px: paleta 260, panoul 340, emailul de 680 px încape fără derulare în lateral (meniul adminului se strânge singur).
    await pagina.setViewportSize({ width: 1366, height: 800 });
    await pagina.reload({ waitUntil: 'load' });
    await pagina.waitForTimeout(400);
    const ingust = await pagina.evaluate(() => {
      const scroll = document.getElementById('eb-canvas-scroll');
      const email = document.getElementById('eb-canvas').getBoundingClientRect();
      const s = scroll.getBoundingClientRect();
      return { paleta: Math.round(document.querySelector('.eb-palette').getBoundingClientRect().width), panou: Math.round(document.querySelector('.eb-props').getBoundingClientRect().width),
        email: Math.round(email.width), inScroll: email.left >= s.left && email.right <= s.right, derulare: scroll.scrollWidth - scroll.clientWidth,
        pagina: document.documentElement.scrollWidth - document.documentElement.clientWidth, meniu: document.getElementById('admin-shell').classList.contains('sidebar-hidden') };
    });
    await clic(pagina, 'desktop');
    const ingustDesktop = await pagina.evaluate(() => {
      const panou = document.getElementById('eb-preview-pane');
      return { derulare: panou.scrollWidth - panou.clientWidth, latime: Math.round(document.getElementById('eb-preview-frame').getBoundingClientRect().width) };
    });
    nota('G13 la 1366 px: paleta 260 px, panoul 340 px, emailul (680 px) întreg pe pânză și în Desktop, fără derulare în lateral (pânza și '
      + 'pagina); meniul adminului strâns', ingust.paleta === 260 && ingust.panou === 340 && ingust.email === 680 && ingust.inScroll && ingust.derulare === 0
      && ingust.pagina === 0 && ingust.meniu && ingustDesktop.derulare === 0 && ingustDesktop.latime === 682, JSON.stringify({ ingust, ingustDesktop }));

    nota('nicio eroare JavaScript în pagina adminului', erori.length === 0, erori.join(' | '));
  } catch (e) {
    nota('Chromium: ' + String(e && e.message ? e.message : e), false);
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify(rezultate) + '\n');
})();
