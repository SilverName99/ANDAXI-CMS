// Capturi din admin pentru testul de fum (tools/dev/smoke.sh): intră în admin,
// fotografiază dashboard-ul, meniul cu toate grupurile deschise și lista de
// comenzi. Orice cerere a paginii spre alt server decât cel local e blocată și
// raportată, ca să vedem dacă adminul încarcă ceva din afară.
//
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_CAPTURI
// (folderul pentru PNG-uri), CMS_DEV_CAPTURI_SUFIX (adăugat la numele fișierelor,
// de exemplu „-prezentare” pentru testul unui pachet). Playwright se ia din NODE_PATH.

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const email = process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro';
const parola = process.env.CMS_DEV_PAROLA || '';
const dosar = process.env.CMS_DEV_CAPTURI || path.join(__dirname, '../../storage/logs/dev/capturi');
const sufix = process.env.CMS_DEV_CAPTURI_SUFIX || '';

(async () => {
    if (!parola) {
        throw new Error('Lipsește CMS_DEV_PAROLA.');
    }
    fs.mkdirSync(dosar, { recursive: true });
    const gazdaLocala = new URL(baza).host;

    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const blocate = new Set();
    await context.route('**/*', (ruta) => {
        const url = new URL(ruta.request().url());
        if (url.protocol === 'data:' || url.host === gazdaLocala) {
            return ruta.continue();
        }
        blocate.add(url.host);
        return ruta.abort();
    });
    const page = await context.newPage();
    const eroriPagina = [];
    page.on('pageerror', (e) => eroriPagina.push(String(e.message || e)));

    await page.goto(baza + '/admin/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', parola);
    await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] button, form[action="/admin/login"] [type="submit"]')]);

    const fisierDashboard = path.join(dosar, 'admin-dashboard' + sufix + '.png');
    await page.screenshot({ path: fisierDashboard, fullPage: true });

    // Meniul: toate grupurile deschise, bara laterală cât e de lungă.
    await page.evaluate(() => {
        document.querySelectorAll('.admin-nav-group').forEach((g) => g.classList.add('open'));
        const bara = document.querySelector('.admin-sidebar');
        if (bara) {
            bara.style.position = 'static';
            bara.style.height = 'auto';
            bara.style.maxHeight = 'none';
            bara.style.overflow = 'visible';
        }
    });
    const fisierMeniu = path.join(dosar, 'admin-meniu' + sufix + '.png');
    await page.locator('.admin-sidebar').screenshot({ path: fisierMeniu });
    const intrari = await page.locator('.admin-nav a[href^="/admin"]').count();

    await page.goto(baza + '/admin/orders');
    const fisierComenzi = path.join(dosar, 'admin-comenzi' + sufix + '.png');
    await page.screenshot({ path: fisierComenzi, fullPage: false });

    await browser.close();

    console.log('OK    ' + fisierDashboard);
    console.log('OK    ' + fisierMeniu + ' (' + intrari + ' linkuri în meniu)');
    console.log('OK    ' + fisierComenzi);
    console.log('INFO  cereri spre alte servere, blocate: ' + (blocate.size ? [...blocate].sort().join(', ') : 'niciuna'));
    if (eroriPagina.length) {
        console.log('INFO  erori JavaScript în pagină: ' + eroriPagina.join(' | '));
    }
})().catch((e) => {
    console.error('EROARE ' + (e && e.message ? e.message : e));
    process.exit(1);
});
