// G-capturat, secțiunea 12: editorul unei pagini capturate în Chromium, după logare, pe captura inventată. Previzualizarea e pagina
// de pe site (documentul randat de server cu codul din editor: head-ul capturat cu foile lui, antetul, subsolul, runtime-ul), în
// cadrul izolat (sandbox="allow-scripts"); o ciornă cu un script dă problemele, nu pagina; salvarea ei e refuzată cu motivul, iar
// codul rămâne în editor; „Antet, subsol și meniu” arată antetul și subsolul capturate.
// Mediul: CMS_DEV_URL, CMS_DEV_ADMIN_EMAIL, CMS_DEV_PAROLA, CMS_DEV_PAGINA (id-ul paginii capturate), CMS_DEV_TEXT (un text din
// conținutul ei), CMS_DEV_POZE (opțional: dosarul capturilor de ecran, la 1440 de pixeli lățime). Ultimul rând: JSON-ul rezultatelor.
'use strict';
const path = require('path');
const { chromium } = require('playwright');

const baza = process.env.CMS_DEV_URL;
const poze = process.env.CMS_DEV_POZE || '';
const textVechi = process.env.CMS_DEV_TEXT || '';
const textNou = 'Text de probă din editor, încă nesalvat';
const scriptRau = '<script>alert(1)</script>';

(async () => {
    const rez = {};
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const pagina = await context.newPage();
        // Doar serverul local; orice altă gazdă e oprită în browser.
        await pagina.route('**/*', (r) => (r.request().url().startsWith(baza + '/') ? r.continue() : r.abort()));
        let dialoguri = 0;
        pagina.on('dialog', (d) => { dialoguri++; d.dismiss().catch(() => {}); });
        const consola = [];
        pagina.on('console', (m) => { if (m.type() === 'error') { consola.push(m.text().slice(0, 200)); } });
        const raspunsuri = [];
        pagina.on('response', (r) => {
            if (/\/admin\/(pages\/\d+|design)\/previzualizare$/.test(r.url())) {
                const a = r.headers();
                raspunsuri.push({ url: r.url().replace(baza, ''), status: r.status(), csp: a['content-security-policy'] || '', cache: a['cache-control'] || '',
                    robots: a['x-robots-tag'] || '' });
            }
        });
        await context.addInitScript(() => {
            window.__incalcari = [];
            document.addEventListener('securitypolicyviolation', (e) => { window.__incalcari.push(e.violatedDirective + ' ' + (e.blockedURI || 'inline')); });
        });
        await pagina.goto(baza + '/admin/login', { waitUntil: 'load' });
        await pagina.fill('input[name="email"]', process.env.CMS_DEV_ADMIN_EMAIL || '');
        await pagina.fill('input[name="password"]', process.env.CMS_DEV_PAROLA || '');
        await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }), pagina.locator('form button[type="submit"], form input[type="submit"]').first().click()]);
        rez.logat = !/\/admin\/login/.test(pagina.url());

        const asteaptaPrevizualizare = () => pagina.waitForResponse((r) => /\/previzualizare$/.test(r.url()), { timeout: 8000 }).then(() => pagina.waitForTimeout(900)).catch(() => null);
        const cadru = () => pagina.frame({ name: 'page-preview-capturata' });
        // Ce e în cadrul previzualizării: antetul, subsolul, stilurile capturate aplicate, scripturile, izolarea.
        const inCadru = async (f) => (f ? f.evaluate(() => {
            const stil = (sel, prop) => { const el = document.querySelector(sel); return el ? getComputedStyle(el)[prop] : null; };
            let parinte = 'citit';
            try { void window.parent.document.title; } catch (_) { parinte = 'blocat'; }
            let cookie = 'citit';
            try { cookie = document.cookie === '' ? 'gol' : 'citit'; } catch (_) { cookie = 'blocat'; }
            return {
                url: location.href, origine: String(window.origin), antet: !!document.querySelector('.et-l--header'), subsol: !!document.querySelector('.et-l--footer'),
                fundalAntet: stil('.et_pb_section_0_tb_header', 'backgroundColor'), chenarSubsol: stil('.et_pb_section_0_tb_footer', 'borderTopColor'),
                text: (document.body ? document.body.innerText : '').slice(0, 4000), loc: !!document.querySelector('.ax-previzualizare-continut'),
                htmlJs: document.documentElement.classList.contains('js'), tarzii: document.querySelectorAll('[data-ax-tarziu]').length,
                foi: [...document.querySelectorAll('link[rel="stylesheet"]')].map((l) => l.getAttribute('href')),
                scripturi: [...document.scripts].map((s) => (s.src ? 'src ' + new URL(s.src).pathname : 'inline ' + (s.type || '') + ' ' + (s.id || ''))),
                incalcari: (window.__incalcari || []).slice(0, 5), parinte, cookie,
            };
        }).catch((e) => ({ eroare: String(e.message || e).slice(0, 200) })) : null);
        const valoare = () => pagina.evaluate(() => { const cm = document.querySelector('#code-column .CodeMirror'); return cm && cm.CodeMirror ? cm.CodeMirror.getValue() : (document.getElementById('html-code') || {}).value; });
        const scrie = (v) => pagina.evaluate((x) => { const cm = document.querySelector('#code-column .CodeMirror'); if (cm && cm.CodeMirror) { cm.CodeMirror.setValue(x); } else { const t = document.getElementById('html-code'); t.value = x; t.dispatchEvent(new Event('input')); } }, v);
        const fapteEditor = () => pagina.evaluate(() => {
            const f = document.getElementById('page-preview');
            return { nota: !!document.getElementById('nota-pagina-capturata'), taburi: [...document.querySelectorAll('.code-type-tab')].map((b) => b.dataset.codeType),
                sandbox: f ? f.getAttribute('sandbox') : null, nume: f ? f.getAttribute('name') : null, srcdoc: f ? f.hasAttribute('srcdoc') : null,
                formular: !!document.getElementById('previzualizare-capturata-form'), js: !!document.getElementById('js-code'), css: !!document.getElementById('css-code'),
                eroare: (document.getElementById('ax-mesaj-eroare') || {}).innerText || '', succes: (document.getElementById('ax-mesaj-succes') || {}).innerText || '',
                codemirror: !!document.querySelector('#code-column .CodeMirror') };
        });

        // 1. Editorul: previzualizarea paginii de pe site, cu codul salvat.
        consola.length = 0;
        const rEditor = await pagina.goto(baza + '/admin/pages/' + process.env.CMS_DEV_PAGINA + '/edit', { waitUntil: 'load' });
        await asteaptaPrevizualizare();
        await pagina.waitForTimeout(600);
        if (poze) {
            await pagina.locator('#page-editor-grid').scrollIntoViewIfNeeded().catch(() => {});
            await pagina.screenshot({ path: path.join(poze, 'editor-previzualizare.png') });
            await pagina.screenshot({ path: path.join(poze, 'editor-pagina-intreaga.png'), fullPage: true });
        }
        rez.editor = { status: rEditor ? rEditor.status() : 0, ...(await fapteEditor()), cadru: await inCadru(cadru()), consola: consola.slice(0, 5),
            incalcariSus: await pagina.evaluate(() => (window.__incalcari || []).slice(0, 5)) };
        const salvat = await valoare();
        rez.editor.areText = typeof salvat === 'string' && textVechi !== '' && salvat.includes(textVechi);

        // 2. O ciornă: aceeași pagină, cu un paragraf nou (codul nesalvat).
        const ciorna = String(salvat || '') + '\n<p class="ax-proba-ciorna">' + textNou + '</p>';
        await scrie(ciorna);
        await asteaptaPrevizualizare();
        rez.ciorna = await inCadru(cadru());
        if (poze) {
            await pagina.screenshot({ path: path.join(poze, 'editor-ciorna.png') });
        }

        // 3. O ciornă cu un script: problemele, nu pagina; niciun dialog.
        await scrie(ciorna + '\n' + scriptRau);
        await asteaptaPrevizualizare();
        rez.ciornaScript = await inCadru(cadru());
        rez.dialoguriCiorna = dialoguri;

        // 4. Salvarea ciornei cu scriptul: refuzată (422), motivul în mesaj, codul rămas în editor.
        consola.length = 0;
        const [rSalvare] = await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }).catch(() => null), pagina.locator('#page-editor-form button[type="submit"]').first().click()]);
        await pagina.waitForTimeout(400);
        await asteaptaPrevizualizare();
        rez.refuz = { status: rSalvare ? rSalvare.status() : 0, ...(await fapteEditor()), consola: consola.slice(0, 5), cadru: await inCadru(cadru()) };
        const ramas = await valoare();
        rez.refuz.codRamas = typeof ramas === 'string' && ramas.includes(scriptRau) && ramas.includes(textNou);
        if (poze) {
            await pagina.screenshot({ path: path.join(poze, 'editor-refuz.png') });
        }

        // 5. Fără script: se salvează.
        await scrie(ciorna);
        await asteaptaPrevizualizare();
        const [rBun] = await Promise.all([pagina.waitForNavigation({ waitUntil: 'load' }).catch(() => null), pagina.locator('#page-editor-form button[type="submit"]').first().click()]);
        await pagina.waitForTimeout(300);
        rez.salvare = { status: rBun ? rBun.status() : 0, url: pagina.url().replace(baza, ''), ...(await fapteEditor()) };

        // 6. „Antet, subsol și meniu”: antetul și subsolul paginilor capturate, cu foile lor.
        consola.length = 0;
        const rDesign = await pagina.goto(baza + '/admin/design?section=header', { waitUntil: 'load' });
        await pagina.waitForTimeout(1200);
        const fDesign = pagina.frames().find((f) => /\/admin\/design\/previzualizare$/.test(f.url())) || null;
        rez.design = { status: rDesign ? rDesign.status() : 0, nota: await pagina.evaluate(() => !!document.getElementById('nota-site-capturat')),
            sandbox: await pagina.evaluate(() => (document.getElementById('design-preview-capturat') || { getAttribute: () => null }).getAttribute('sandbox')),
            cadru: await inCadru(fDesign), consola: consola.slice(0, 5) };
        if (poze) {
            await pagina.locator('#previzualizare-capturata-design').scrollIntoViewIfNeeded().catch(() => {});
            await pagina.screenshot({ path: path.join(poze, 'antet-subsol.png') });
        }
        rez.raspunsuri = raspunsuri.slice(0, 12);
        rez.dialoguri = dialoguri;
    } catch (e) {
        rez.eroare = String(e && e.message || e).slice(0, 300);
    } finally {
        await browser.close();
    }
    console.log(JSON.stringify(rez));
})();
