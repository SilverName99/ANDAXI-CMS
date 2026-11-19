// G-capturat (recenzia 2, R2-2): pe un site cu cadrul capturat, adminul își rulează scripturile inline: editorul unei pagini și
// galeria, după logare, fără nicio încălcare CSP (evenimentele securitypolicyviolation și erorile de consolă despre CSP).
// Mediul: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_PAGINA (id-ul unei pagini). Ultimul rând: JSON-ul rezultatelor.
'use strict';
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL;
(async () => {
    const rez = [];
    const browser = await chromium.launch();
    try {
        const pagina = await browser.newPage();
        // Doar serverul local; orice altă gazdă e oprită în browser.
        await pagina.route('**/*', (r) => (r.request().url().startsWith(baza + '/') ? r.continue() : r.abort()));
        const consola = [];
        pagina.on('console', (m) => { if (m.type() === 'error' && /Content Security Policy|Content-Security-Policy/i.test(m.text())) { consola.push(m.text().slice(0, 200)); } });
        await pagina.addInitScript(() => {
            window.__incalcari = [];
            document.addEventListener('securitypolicyviolation', (e) => { window.__incalcari.push(e.violatedDirective + ' ' + (e.blockedURI || 'inline')); });
        });
        await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
        await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || '');
        await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
        await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.locator('form button[type="submit"], form input[type="submit"]').first().click()]);
        const logat = !/\/admin\/login/.test(pagina.url());
        for (const cale of ['/admin/pages/' + (process.env.CMS_DEV_PAGINA || '1') + '/edit', '/admin/gallery']) {
            consola.length = 0;
            const r = await pagina.goto(baza + cale, { waitUntil: 'load' });
            await pagina.waitForTimeout(300);
            const incalcari = await pagina.evaluate(() => window.__incalcari || []);
            const inline = await pagina.evaluate(() => [...document.scripts].filter((s) => !s.src && (!s.type || /javascript|module/.test(s.type))).length);
            rez.push({ cale, status: r ? r.status() : 0, logat, inline, incalcari: incalcari.length + consola.length, detalii: [...incalcari, ...consola].slice(0, 3),
                csp: r ? (r.headers()['content-security-policy'] || '') : '' });
        }
    } catch (e) {
        rez.push({ eroare: String(e && e.message || e).slice(0, 300) });
    } finally {
        await browser.close();
    }
    console.log(JSON.stringify(rez));
})();
