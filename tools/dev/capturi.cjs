// Capturi din admin cu Playwright. Orice cerere a paginii spre alt server decât cel local e blocată și raportată, ca să
// vedem dacă adminul încarcă ceva din afară.
//
// Două seturi (CMS_DEV_CAPTURI_SET):
//  - „fum” (implicit, pentru testul de fum, tools/dev/smoke.sh): intră în admin, fotografiază dashboard-ul, meniul cu
//    toate grupurile deschise și lista de comenzi;
//  - „faza-d” (WP-29: capturile de referință ale refacerii adminului; aceleași după fiecare WP al fazei D): la 390, 1366
//    și 1920 px, Acasă, lista comenzilor, o comandă deschisă, editorul unui produs, Setări magazin, emailurile și editorul
//    unei postări de blog, în $CMS_DEV_CAPTURI/<lățime>/<nume>.png. tools/dev/contract-admin.php --capturi le face pe
//    codul etichetei admin-inainte-b și pe codul curent, unul lângă altul. Lățimile: CMS_DEV_CAPTURI_LATIMI („390,1366”).
//
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_CAPTURI (folderul pentru PNG-uri),
// CMS_DEV_CAPTURI_SUFIX (adăugat la numele fișierelor, de exemplu „-prezentare” pentru testul unui pachet). Playwright se
// ia din NODE_PATH.

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const email = process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro';
const parola = process.env.CMS_DEV_PAROLA || '';
const dosar = process.env.CMS_DEV_CAPTURI || path.join(__dirname, '../../storage/logs/dev/capturi');
const sufix = process.env.CMS_DEV_CAPTURI_SUFIX || '';
const set = process.env.CMS_DEV_CAPTURI_SET || 'fum';
const latimi = (process.env.CMS_DEV_CAPTURI_LATIMI || '390,1366,1920').split(',').map((l) => parseInt(l, 10)).filter((l) => l > 0);

// Meniul lateral: shell-ul de dinainte (.admin-sidebar, grupurile .admin-nav-group) și cel nou (WP-30: .ax-meniu).
const MENIU = '.ax-meniu, .admin-sidebar';
const LINKURI_MENIU = '.ax-meniu a[href^="/admin"], .admin-nav a[href^="/admin"]';

async function intra(page) {
    await page.goto(baza + '/admin/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', parola);
    await Promise.all([page.waitForURL(baza + '/admin'), page.click('form[action="/admin/login"] button, form[action="/admin/login"] [type="submit"]')]);
}

async function setFum(context, blocate, eroriPagina) {
    const page = await context.newPage();
    page.on('pageerror', (e) => eroriPagina.push(String(e.message || e)));
    await intra(page);

    const fisierDashboard = path.join(dosar, 'admin-dashboard' + sufix + '.png');
    await page.screenshot({ path: fisierDashboard, fullPage: true });

    // Meniul: toate grupurile deschise, bara laterală cât e de lungă.
    await page.evaluate(() => {
        document.querySelectorAll('.admin-nav-group').forEach((g) => g.classList.add('open'));
        document.querySelectorAll('.ax-meniu details').forEach((g) => { g.open = true; });
        const bara = document.querySelector('.ax-meniu, .admin-sidebar');
        if (bara) {
            bara.style.position = 'static';
            bara.style.height = 'auto';
            bara.style.maxHeight = 'none';
            bara.style.overflow = 'visible';
        }
    });
    const fisierMeniu = path.join(dosar, 'admin-meniu' + sufix + '.png');
    await page.locator(MENIU).first().screenshot({ path: fisierMeniu });
    const intrari = await page.locator(LINKURI_MENIU).count();

    await page.goto(baza + '/admin/orders');
    const fisierComenzi = path.join(dosar, 'admin-comenzi' + sufix + '.png');
    await page.screenshot({ path: fisierComenzi, fullPage: false });

    console.log('OK    ' + fisierDashboard);
    console.log('OK    ' + fisierMeniu + ' (' + intrari + ' linkuri în meniu)');
    console.log('OK    ' + fisierComenzi);
}

async function setFazaD(browser, blocheaza, eroriPagina) {
    for (const latime of latimi) {
        const context = await browser.newContext({ viewport: { width: latime, height: latime < 600 ? 844 : 900 } });
        await blocheaza(context);
        const page = await context.newPage();
        page.on('pageerror', (e) => eroriPagina.push(latime + ' px: ' + String(e.message || e)));
        await intra(page);
        const dosarLatime = path.join(dosar, String(latime));
        fs.mkdirSync(dosarLatime, { recursive: true });
        const fa = async (nume, cale, pregateste, intreaga = true) => {
            await page.goto(baza + cale, { waitUntil: 'load' });
            await page.waitForTimeout(200);
            if (pregateste) {
                await pregateste();
                await page.waitForTimeout(300);
            }
            const fisier = path.join(dosarLatime, nume + sufix + '.png');
            await page.screenshot({ path: fisier, fullPage: intreaga });
            console.log('OK    ' + fisier);
        };
        const clic = (selector) => async () => {
            const buton = await page.$(selector);
            if (buton) {
                await buton.evaluate((el) => el.click());
            } else {
                console.log('INFO  ' + latime + ' px: nu găsesc ' + selector);
            }
        };
        await fa('acasa', '/admin');
        await fa('comenzi', '/admin/orders', null, false);
        await fa('comanda', '/admin/orders', clic('.view-order-btn'), false);
        await fa('produs', '/admin/products', clic('.edit-product-btn'), false);
        await fa('setari-magazin', '/admin/settings/store');
        await fa('emailuri', '/admin/emails/newsletters?tab=templates');
        await page.goto(baza + '/admin/blog/posts', { waitUntil: 'load' });
        const editor = await page.$$eval('a[href*="/admin/blog/posts/editor?post="]', (a) => (a[0] ? a[0].getAttribute('href') : ''));
        if (editor) {
            await fa('blog-editor', editor);
        } else {
            console.log('INFO  ' + latime + ' px: nicio postare de blog în listă (pluginul blog lipsește?)');
        }
        await context.close();
    }
}

(async () => {
    if (!parola) {
        throw new Error('Lipsește CMS_DEV_PAROLA.');
    }
    fs.mkdirSync(dosar, { recursive: true });
    const gazdaLocala = new URL(baza).host;

    const browser = await chromium.launch();
    const blocate = new Set();
    const blocheaza = async (context) => context.route('**/*', (ruta) => {
        const url = new URL(ruta.request().url());
        if (url.protocol === 'data:' || url.protocol === 'blob:' || url.host === gazdaLocala) {
            return ruta.continue();
        }
        blocate.add(url.host);
        return ruta.abort();
    });
    const eroriPagina = [];
    if (set === 'faza-d') {
        await setFazaD(browser, blocheaza, eroriPagina);
    } else {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await blocheaza(context);
        await setFum(context, blocate, eroriPagina);
    }
    await browser.close();

    console.log('INFO  cereri spre alte servere, blocate: ' + (blocate.size ? [...blocate].sort().join(', ') : 'niciuna'));
    if (eroriPagina.length) {
        console.log('INFO  erori JavaScript în pagină: ' + eroriPagina.join(' | '));
    }
})().catch((e) => {
    console.error('EROARE ' + (e && e.message ? e.message : e));
    process.exit(1);
});
