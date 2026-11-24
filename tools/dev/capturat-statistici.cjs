// G-capturat, secțiunea 13: pluginul „Statistici site” în Chromium, pe aceeași adresă cu aceiași parametri, o dată pe o pagină
// capturată (runtime-ul compat, comun/v1/statistici.js, sub politica CSP a site-ului) și o dată pe pagina nucleului (scriptul inline
// al layout-ului, Statistici::scriptPagina): corpurile trimise la POST /api/statistici, răspunsurile, încălcările CSP. Un vizitator
// obișnuit (navigator.webdriver = false, un User-Agent de browser), fără niciun cookie.
// Mediul: CMS_DEV_URL (cadrul capturat), CMS_DEV_URL_NUCLEU (fără cadru), CMS_DEV_CALE. Ultimul rând: JSON-ul rezultatelor.
'use strict';
const { chromium } = require('playwright');

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const cale = process.env.CMS_DEV_CALE || '/camere/';
const parametri = '?utm_source=TikTok&utm_campaign=video-viral&gclid=proba';

async function viziteaza(browser, baza) {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, userAgent: UA });
    await context.addInitScript(() => {
        Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false });
        window.__incalcari = [];
        document.addEventListener('securitypolicyviolation', (e) => { window.__incalcari.push(e.violatedDirective + ' ' + (e.blockedURI || 'inline')); });
    });
    await context.route('**/*', (r) => (r.request().url().startsWith(baza + '/') ? r.continue() : r.abort()));
    const pagina = await context.newPage();
    const trimise = [];
    const statusuri = [];
    pagina.on('request', (c) => { if (c.url() === baza + '/api/statistici') { trimise.push({ corp: c.postData() || '', cookie: c.headers().cookie || null }); } });
    pagina.on('response', (r) => { if (r.url() === baza + '/api/statistici') { statusuri.push(r.status()); } });
    const r = await pagina.goto(baza + cale + parametri, { waitUntil: 'load' });
    for (let t = 0; t < 5000 && statusuri.length < 1; t += 100) {
        await pagina.waitForTimeout(100);
    }
    await pagina.waitForTimeout(1200);
    await pagina.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    for (let t = 0; t < 5000 && statusuri.length < 2; t += 100) {
        await pagina.waitForTimeout(100);
    }
    const rez = { status: r ? r.status() : 0, trimise, statusuri, incalcari: await pagina.evaluate(() => window.__incalcari.slice(0, 5)),
        scriptInline: await pagina.evaluate(() => !!document.querySelector('script[data-ax-st]')),
        scriptRuntime: await pagina.evaluate(() => [...document.scripts].some((s) => /\/assets\/compat\/comun\/v1\/statistici\.js\?v=/.test(s.src))) };
    await context.close();
    return rez;
}

(async () => {
    const rez = {};
    const browser = await chromium.launch();
    try {
        rez.capturat = await viziteaza(browser, process.env.CMS_DEV_URL);
        rez.nucleu = await viziteaza(browser, process.env.CMS_DEV_URL_NUCLEU);
    } catch (e) {
        rez.eroare = String(e && e.message || e).slice(0, 300);
    } finally {
        await browser.close();
    }
    console.log(JSON.stringify(rez));
})();
