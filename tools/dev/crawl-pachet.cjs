// Trecerea cu browserul prin paginile unui pachet (pentru tools/dev/test-pluginuri.php):
// deschide fiecare pagină ca vizitator, iar paginile adminului logat, după ce
// rulează JavaScript-ul lor, și întoarce linkurile și formularele din DOM plus
// erorile JavaScript. Opțional fotografiază meniul adminului cu toate grupurile
// deschise. Orice cerere spre alt server decât cel local e blocată.
//
// Variabile: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA,
// CMS_DEV_PAGINI (JSON de intrare: {"public": [...], "admin": [...]}),
// CMS_DEV_REZULTAT (JSON de ieșire), CMS_DEV_CAPTURI (folderul pentru PNG; gol =
// fără capturi), CMS_DEV_CAPTURI_SUFIX. Playwright se ia din NODE_PATH.

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL || 'http://127.0.0.1:8701';
const email = process.env.CMS_DEV_ADMIN_EMAIL || 'admin@exemplu.ro';
const parola = process.env.CMS_DEV_PAROLA || '';
const intrare = JSON.parse(fs.readFileSync(process.env.CMS_DEV_PAGINI, 'utf8'));
const iesire = process.env.CMS_DEV_REZULTAT;
const dosarCapturi = process.env.CMS_DEV_CAPTURI || '';
const sufix = process.env.CMS_DEV_CAPTURI_SUFIX || '';

const gazdaLocala = new URL(baza).host;
const blocate = new Set();

async function contextNou(browser) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.route('**/*', (ruta) => {
        const url = new URL(ruta.request().url());
        if (url.protocol === 'data:' || url.protocol === 'blob:' || url.host === gazdaLocala) {
            return ruta.continue();
        }
        blocate.add(url.host);
        return ruta.abort();
    });
    return context;
}

async function viziteaza(page, cale, rol) {
    const erori = [];
    const asculta = (e) => erori.push(String((e && e.message) || e).split('\n')[0]);
    page.on('pageerror', asculta);
    let status = 0;
    try {
        const raspuns = await page.goto(baza + cale, { waitUntil: 'load', timeout: 30000 });
        status = raspuns ? raspuns.status() : 0;
        await page.waitForTimeout(250);
    } catch (e) {
        erori.push('navigare: ' + String((e && e.message) || e).split('\n')[0]);
    }
    let linkuri = [];
    let formulare = [];
    try {
        linkuri = await page.$$eval('a[href]', (noduri) => noduri.map((n) => n.getAttribute('href') || ''));
        formulare = await page.$$eval('form[action]', (noduri) => noduri.map((n) => [
            n.getAttribute('action') || '',
            (n.getAttribute('method') || 'get').toUpperCase(),
        ]));
    } catch (e) {
        erori.push('DOM: ' + String((e && e.message) || e).split('\n')[0]);
    }
    page.off('pageerror', asculta);
    return { cale, rol, status, erori, linkuri, formulare };
}

(async () => {
    const browser = await chromium.launch();
    const rezultat = { pagini: [], capturi: [], blocate: [] };

    const vizitator = await contextNou(browser);
    const paginaViz = await vizitator.newPage();
    for (const cale of intrare.public || []) {
        rezultat.pagini.push(await viziteaza(paginaViz, cale, 'vizitator'));
    }
    await vizitator.close();

    if ((intrare.admin || []).length > 0 || dosarCapturi) {
        if (!parola) {
            throw new Error('Lipsește CMS_DEV_PAROLA.');
        }
        const admin = await contextNou(browser);
        const page = await admin.newPage();
        await page.goto(baza + '/admin/login');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', parola);
        await Promise.all([
            page.waitForURL(baza + '/admin'),
            page.click('form[action="/admin/login"] button, form[action="/admin/login"] [type="submit"]'),
        ]);
        if (dosarCapturi) {
            fs.mkdirSync(dosarCapturi, { recursive: true });
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
            const fisier = path.join(dosarCapturi, 'admin-meniu' + sufix + '.png');
            await page.locator('.admin-sidebar').screenshot({ path: fisier });
            rezultat.capturi.push(fisier);
        }
        for (const cale of intrare.admin || []) {
            rezultat.pagini.push(await viziteaza(page, cale, 'admin'));
        }
        await admin.close();
    }

    await browser.close();
    rezultat.blocate = [...blocate].sort();
    fs.writeFileSync(iesire, JSON.stringify(rezultat));
})().catch((e) => {
    console.error('EROARE ' + (e && e.message ? e.message : e));
    process.exit(1);
});
