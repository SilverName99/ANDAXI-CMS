// G-vizual (WP-13): stilurile calculate ale paginilor publice, pentru tools/dev/capturi-publice.sh (prin
// tools/dev/capturi-publice.php, care scrie configurația și citește rezultatul). Playwright, ca tools/dev/capturi.cjs;
// orice cerere spre alt server decât 127.0.0.1/localhost e blocată și raportată.
//
// Două moduri (configurația: fișierul JSON din primul argument):
//
//  - „compara”: aceleași pagini, ca vizitator și ca client logat, la fiecare lățime, pe două servere (codul de bază și
//    codul curent ale lui compara-cu-baza.sh). Pentru fiecare element vizibil (în ordinea DOM, cheia = drumul de
//    etichete de la <html>) se compară o listă fixă de proprietăți calculate, după rotunjire. Orice diferență pică,
//    afară de cele declarate (selector + proprietate + pagină, $porturiVizual din capturi-publice.php); o declarație
//    pe care nu o folosește nicio diferență pică și ea. Pentru ochi: capturi PNG ale paginii întregi de pe ambele
//    servere și numărul de pixeli diferiți (nu decid nimic); perechile identice se șterg după numărare, rămân doar
//    capturile care diferă.
//  - „verificari”: un singur server; pentru fiecare verificare (pagina, rolul, lățimea, eventual derularea), o expresie
//    JavaScript evaluată în pagină, comparată cu valoarea așteptată (testul temei, tools/dev/test-pluginuri.php, T).
//
// Paginile se „îngheață” înainte de citire: prefers-reduced-motion, fără animații și tranziții, setInterval oprit
// (caruselele rămân la primul slide), pozele leneșe încărcate, fonturile gata.
//
// Rezultatul: JSON în „rezultat” (din configurație); pe ecran, rândurile OK/DIFERĂ/EȘEC și rezumatul. Cod 0 = trecut.

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

// Lista proprietăților comparate vine din capturi-publice.php (un singur loc), ca și declarațiile.
const PROPRIETATI = config.proprietati || [];

const STIL_INGHETAT = '*,*::before,*::after{animation:none!important;transition:none!important;'
    + 'caret-color:transparent!important;scroll-behavior:auto!important}';

// Înainte de orice script al paginii: setInterval nu mai pornește nimic (caruselele automate rămân pe primul slide).
const SCRIPT_INGHETARE = () => {
    window.setInterval = function () { return 0; };
};

function esteLocala(url) {
    return url.protocol === 'data:' || url.protocol === 'blob:' || url.hostname === '127.0.0.1' || url.hostname === 'localhost';
}

async function contextNou(browser, blocate, latime) {
    const context = await browser.newContext({
        viewport: { width: latime, height: 900 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
        locale: 'ro-RO',
        timezoneId: 'Europe/Bucharest',
    });
    await context.addInitScript(SCRIPT_INGHETARE);
    await context.route('**/*', (ruta) => {
        const url = new URL(ruta.request().url());
        if (esteLocala(url)) {
            return ruta.continue();
        }
        blocate.add(url.host);
        return ruta.abort();
    });
    return context;
}

// Sesiunea rolului: vizitatorul cu un produs în coș, clientul logat (cu un produs în coș), ca în compara-cu-baza.php.
async function pregatesteRol(context, server, rol, roluri) {
    const date = roluri[rol] || {};
    if (date.email) {
        // O logare reușită duce la /contul-meu; una refuzată (o parolă greșită, de exemplu admin-parola.txt rescris de altă
        // unealtă) duce înapoi la /login, tot cu 302. Altfel rolul ar rula ca vizitator, iar paginile clientului ar ieși la
        // fel pe ambele servere fără să fi fost văzute.
        const r = await context.request.post(server + '/login', { form: { email: date.email, password: date.parola }, maxRedirects: 0 });
        const locatie = r.headers().location || '';
        const cale = locatie !== '' ? new URL(locatie, server).pathname : '';
        if (r.status() < 300 || r.status() >= 400 || cale !== '/contul-meu') {
            throw new Error('login ' + rol + ' pe ' + server + ': ' + r.status() + ' spre „' + locatie + '”, aștept /contul-meu '
                + '(parola clientului demo nu e cea din admin-parola.txt?)');
        }
    }
    if (date.produs) {
        await context.request.post(server + '/cos/adauga/' + date.produs, { form: { quantity: String(date.cantitate || 1) }, maxRedirects: 0 });
    }
}

// Pagina gata de citit: fără animații, rețeaua liniștită (componentele care își aduc produsele din JSON, ca cele mai
// vândute), fiecare poză încărcată (și cele puse de scripturi după încărcare, deci de două ori), fonturile gata.
async function asteaptaPagina(page) {
    await page.addStyleTag({ content: STIL_INGHETAT }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
    for (let trecere = 0; trecere < 2; trecere++) {
        await page.evaluate(async () => {
            const imagini = Array.from(document.images);
            imagini.forEach((img) => { if (img.loading === 'lazy') { img.loading = 'eager'; } });
            await Promise.race([
                Promise.all(imagini.map((img) => (img.complete ? Promise.resolve() : new Promise((gata) => {
                    img.addEventListener('load', gata, { once: true });
                    img.addEventListener('error', gata, { once: true });
                })))),
                new Promise((gata) => setTimeout(gata, 5000)),
            ]);
            // Și decodate (decoding="async"): altfel captura paginii întregi le poate prinde încă goale.
            await Promise.race([
                Promise.all(imagini.map((img) => (img.decode ? img.decode().catch(() => {}) : Promise.resolve()))),
                new Promise((gata) => setTimeout(gata, 5000)),
            ]);
            if (document.fonts && document.fonts.ready) {
                await Promise.race([document.fonts.ready, new Promise((gata) => setTimeout(gata, 3000))]);
            }
        }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
    }
    await page.waitForTimeout(200);
}

// Stilurile calculate ale fiecărui element vizibil; pentru fiecare, și declarațiile (indicii) al căror selector îl prinde.
async function stiluri(page, proprietati, selectori) {
    return page.evaluate(({ proprietati, selectori }) => {
        const rotunjeste = (valoare) => String(valoare)
            .replace(/(-?\d*\.?\d+)px/g, (_, n) => Math.round(parseFloat(n)) + 'px')
            .replace(/(-?\d+\.\d{4,})/g, (_, n) => String(Math.round(parseFloat(n) * 1000) / 1000));
        const cheie = (el) => {
            const parti = [];
            for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
                const nume = e.tagName.toLowerCase();
                let indice = 1;
                for (let f = e.previousElementSibling; f; f = f.previousElementSibling) {
                    if (f.tagName === e.tagName) {
                        indice++;
                    }
                }
                parti.unshift(nume + ':' + indice);
            }
            return parti.join('>');
        };
        const rezultat = [];
        const toate = [document.documentElement].concat(Array.from(document.documentElement.querySelectorAll('*')));
        for (const el of toate) {
            if (el.closest('head')) {
                continue;
            }
            if (el !== document.documentElement && el.getClientRects().length === 0) {
                continue;
            }
            const cs = getComputedStyle(el);
            const s = {};
            for (const p of proprietati) {
                s[p] = rotunjeste(cs.getPropertyValue(p));
            }
            const d = [];
            selectori.forEach((selector, i) => {
                try {
                    if (el.matches(selector)) {
                        d.push(i);
                    }
                } catch (e) { /* un selector greșit îl raportează partea PHP */ }
            });
            rezultat.push({ k: cheie(el), s, d });
        }
        return rezultat;
    }, { proprietati, selectori });
}

function numeFisier(rol, cale, latime, server) {
    const slug = (cale.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'acasa').slice(0, 60);
    return rol + '-' + slug + '-' + latime + '-' + server + '.png';
}

// Pixelii diferiți dintre două capturi (doar pentru ochi): în browser, pe un canvas.
async function pixeliDiferiti(pagina, fisierA, fisierB) {
    const a = fs.readFileSync(fisierA).toString('base64');
    const b = fs.readFileSync(fisierB).toString('base64');
    return pagina.evaluate(async ({ a, b }) => {
        const incarca = (date) => new Promise((gata, esec) => {
            const img = new Image();
            img.onload = () => gata(img);
            img.onerror = esec;
            img.src = 'data:image/png;base64,' + date;
        });
        const [ia, ib] = await Promise.all([incarca(a), incarca(b)]);
        const w = Math.max(ia.width, ib.width);
        const h = Math.max(ia.height, ib.height);
        if (w * h > 60000000) {
            return { diferiti: -1, total: w * h, latimi: [ia.width, ib.width], inaltimi: [ia.height, ib.height] };
        }
        const pixeli = (img) => {
            const c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            const x = c.getContext('2d');
            x.drawImage(img, 0, 0);
            return x.getImageData(0, 0, w, h).data;
        };
        const pa = pixeli(ia);
        const pb = pixeli(ib);
        let diferiti = 0;
        for (let i = 0; i < pa.length; i += 4) {
            if (pa[i] !== pb[i] || pa[i + 1] !== pb[i + 1] || pa[i + 2] !== pb[i + 2] || pa[i + 3] !== pb[i + 3]) {
                diferiti++;
            }
        }
        return { diferiti, total: w * h, latimi: [ia.width, ib.width], inaltimi: [ia.height, ib.height] };
    }, { a, b });
}

async function deschide(page, url) {
    let status = 0;
    const erori = [];
    const asculta = (e) => erori.push(String((e && e.message) || e).split('\n')[0]);
    page.on('pageerror', asculta);
    try {
        const raspuns = await page.goto(url, { waitUntil: 'load', timeout: 45000 });
        status = raspuns ? raspuns.status() : 0;
        await asteaptaPagina(page);
    } catch (e) {
        erori.push('navigare: ' + String((e && e.message) || e).split('\n')[0]);
    }
    page.off('pageerror', asculta);
    return { status, erori };
}

async function compara(browser) {
    const servere = config.servere;
    const declaratii = config.declaratii || [];
    const selectori = declaratii.map((d) => d.selector);
    const dosar = config.dosar;
    fs.mkdirSync(dosar, { recursive: true });
    const blocate = new Set();
    const diferente = [];
    const folosite = new Set();
    const pixeli = [];
    let elemente = 0;
    let pagini = 0;
    const paginaDiff = await (await browser.newContext()).newPage();
    for (const latime of config.latimi) {
        for (const rol of Object.keys(config.roluri)) {
            const contexte = {};
            for (const [eticheta, server] of Object.entries(servere)) {
                contexte[eticheta] = await contextNou(browser, blocate, latime);
                await pregatesteRol(contexte[eticheta], server, rol, config.roluri);
            }
            for (const cale of config.pagini) {
                // Aceeași pagină pe ambele servere deodată (fiecare cu contextul lui): jumătate din timp.
                const instantanee = {};
                await Promise.all(Object.entries(servere).map(async ([eticheta, server]) => {
                    const page = await contexte[eticheta].newPage();
                    const stare = await deschide(page, server + cale);
                    const date = await stiluri(page, PROPRIETATI, eticheta === 'curent' ? selectori : []);
                    let captura = null;
                    if (config.capturi) {
                        captura = path.join(dosar, numeFisier(rol, cale, latime, eticheta));
                        await page.screenshot({ path: captura, fullPage: true }).catch(() => { captura = null; });
                    }
                    instantanee[eticheta] = { stare, date, captura };
                    await page.close();
                }));
                pagini++;
                const baza = instantanee.baza;
                const curent = instantanee.curent;
                if (baza.stare.status !== curent.stare.status) {
                    diferente.push({ rol, cale, latime, cheie: '(pagina)', proprietate: 'status', baza: baza.stare.status, curent: curent.stare.status, declarata: false });
                }
                const harta = new Map(baza.date.map((e) => [e.k, e]));
                const vazute = new Set();
                for (const e of curent.date) {
                    elemente++;
                    vazute.add(e.k);
                    const b = harta.get(e.k);
                    if (!b) {
                        diferente.push({ rol, cale, latime, cheie: e.k, proprietate: '(elementul lipsește în bază)', baza: '', curent: '', declarata: false });
                        continue;
                    }
                    for (const p of PROPRIETATI) {
                        if (b.s[p] === e.s[p]) {
                            continue;
                        }
                        let declarata = false;
                        for (const i of e.d) {
                            const d = declaratii[i];
                            const paginiD = Array.isArray(d.pagini) ? d.pagini : [d.pagini];
                            const proprietatiD = Array.isArray(d.proprietate) ? d.proprietate : [d.proprietate];
                            if ((paginiD.includes('*') || paginiD.includes(cale)) && proprietatiD.includes(p)
                                && (!d.latimi || d.latimi.includes(latime)) && (!d.roluri || d.roluri.includes(rol))) {
                                declarata = true;
                                folosite.add(i);
                            }
                        }
                        diferente.push({ rol, cale, latime, cheie: e.k, proprietate: p, baza: b.s[p], curent: e.s[p], declarata });
                    }
                }
                for (const b of baza.date) {
                    if (!vazute.has(b.k)) {
                        diferente.push({ rol, cale, latime, cheie: b.k, proprietate: '(elementul lipsește în codul curent)', baza: '', curent: '', declarata: false });
                    }
                }
                if (baza.captura && curent.captura) {
                    const px = await pixeliDiferiti(paginaDiff, baza.captura, curent.captura).catch(() => null);
                    if (px) {
                        // Capturile identice nu mai trebuie privite: rămân doar cele care diferă (spațiul pe disc).
                        const pastrate = px.diferiti !== 0;
                        if (!pastrate) {
                            fs.rmSync(baza.captura, { force: true });
                            fs.rmSync(curent.captura, { force: true });
                        }
                        pixeli.push({ rol, cale, latime, ...px, capturi: pastrate ? [path.basename(baza.captura), path.basename(curent.captura)] : [] });
                    }
                }
                for (const [eticheta, inst] of Object.entries(instantanee)) {
                    for (const eroare of inst.stare.erori) {
                        console.log('INFO  ' + eticheta + ' ' + rol + ' ' + cale + ' @' + latime + ': ' + eroare);
                    }
                }
            }
            for (const c of Object.values(contexte)) {
                await c.close();
            }
        }
    }
    const nedeclarate = diferente.filter((d) => !d.declarata);
    const nefolosite = declaratii.map((d, i) => ({ i, d })).filter(({ i }) => !folosite.has(i));
    return { mod: 'compara', pagini, elemente, diferente, nedeclarate: nedeclarate.length, nefolosite: nefolosite.map(({ d }) => d.nume || d.selector),
        pixeli, blocate: [...blocate].sort() };
}

async function verificari(browser) {
    const blocate = new Set();
    const rezultate = [];
    const grupuri = new Map();
    for (const v of config.verificari) {
        const cheieGrup = (v.rol || 'viz') + '|' + (v.latime || 1366);
        if (!grupuri.has(cheieGrup)) {
            grupuri.set(cheieGrup, []);
        }
        grupuri.get(cheieGrup).push(v);
    }
    for (const [cheieGrup, lista] of grupuri) {
        const [rol, latime] = cheieGrup.split('|');
        const context = await contextNou(browser, blocate, Number(latime));
        await pregatesteRol(context, config.server, rol, config.roluri || {});
        for (const v of lista) {
            const page = await context.newPage();
            const stare = await deschide(page, config.server + v.cale);
            if (v.deruleaza) {
                await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)).catch(() => {});
                await page.waitForTimeout(300);
            }
            let valoare;
            try {
                valoare = await page.evaluate(v.js);
            } catch (e) {
                valoare = 'EROARE: ' + String((e && e.message) || e).split('\n')[0];
            }
            const ok = JSON.stringify(valoare) === JSON.stringify(v.asteptat);
            rezultate.push({ eticheta: v.eticheta, cale: v.cale, latime: Number(latime), rol, valoare, asteptat: v.asteptat, ok, status: stare.status, erori: stare.erori });
            await page.close();
        }
        await context.close();
    }
    return { mod: 'verificari', rezultate, blocate: [...blocate].sort() };
}

(async () => {
    const browser = await chromium.launch();
    let rezultat;
    try {
        rezultat = config.mod === 'verificari' ? await verificari(browser) : await compara(browser);
    } finally {
        await browser.close();
    }
    fs.writeFileSync(config.rezultat, JSON.stringify(rezultat));
})().catch((e) => {
    console.error('EROARE ' + (e && e.message ? e.message : e));
    process.exit(2);
});
