// Captura harness-ului de paritate (P3; DECIZIE §6.1): aceeași unealtă ia referința (site-ul live) și candidatul (randarea
// CMS-ului), pe aceleași pagini, profiluri, ferestre și stări. Pentru fiecare combinație păstrează, în afara repo-ului:
//   (a) HTML-ul static (octeții primiți), (b) DOM-ul după `load` și liniștea (implicit 2 s fără mutații și fără cereri),
//   inventarul mutațiilor (b)−(a) cu momentele lor, linia de timp a observatorului, head-ul, linkurile, textul, CSSOM-ul;
//   apoi, în faza de măsurare (pozele leneșe încărcate și decodate, fonturile gata): stilurile calculate și geometria
//   fiecărui element vizibil, imaginile, captura întregii pagini (cu animațiile oprite doar pe durata ei) și zonele instabile
//   (două capturi la 0,8 s; ce diferă între ele e instabil pe referință); la sfârșit, scenariile de după (OBS-uri).
//
//   node captura.cjs <configurație.json> [--doar=<pagina>/<profil>/<fereastra>] [--reia]
//
// Configurația (JSON; câmpurile în capul lui citesteConfig) o scrie paritate-wp.sh din paritate.json, sau o dă direct cine
// cheamă. Ieșirea: <iesire>/<pagina>/<profil>/<fereastra>/<stare>/meta.json + depozitul <iesire>/_obiecte, indexul
// <iesire>/index.json și jurnalul de rețea (JSONL). Codul de ieșire: 0 gata; 1 capturi cu erori; 2 eroare de folosire.

'use strict';

const IESIRE_GARDA = require('./lib/iesire.cjs');
IESIRE_GARDA.pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo, prin nicio funcție
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { Retea, sha256, somn } = require('./lib/retea.cjs');
const { Depozit } = require('./lib/depozit.cjs');
const P = require('./lib/profiluri.cjs');
const S = require('./lib/sonde-pagina.cjs');
const PNG = require('./lib/png.cjs');

function citesteConfig(fisier) {
    let c = JSON.parse(fs.readFileSync(fisier, 'utf8'));
    // „din_paritate”: paginile, matricea și politica rețelei din secțiunea „captura” a lui paritate.json (cheile configurației
    // au întâietate). Căile relative din paritate.json sunt față de dosarul lui.
    if (c.din_paritate) {
        const fp = path.resolve(path.dirname(fisier), c.din_paritate);
        IESIRE_GARDA.verificaConfig(fp);
        const par = JSON.parse(fs.readFileSync(fp, 'utf8')).captura || {};
        if (par.scenarii) { par.scenarii = path.resolve(path.dirname(fp), par.scenarii); }
        for (const pt of Object.values(par.probe_trackere || {})) { if (pt.fisier) { pt.fisier = path.resolve(path.dirname(fp), pt.fisier); } }
        c = { ...par, ...c };
    }
    const lipsa = ['rol', 'site', 'iesire', 'pagini', 'matrice'].filter((k) => c[k] === undefined);
    if (lipsa.length) { throw new Error('configurația nu are: ' + lipsa.join(', ')); }
    if (!['referinta', 'candidat'].includes(c.rol)) { throw new Error('rol necunoscut: ' + c.rol); }
    return {
        sursa: 'retea',
        stari: ['incarcare'],
        paralel: 2,
        lucratori: 1,
        pauza_navigare_ms: 3000,
        liniste_ms: 2000,
        maxim_asteptare_ms: 15000,
        gazde_fonturi: [],
        cai_abortate: ['^/wp-admin/admin-ajax\\.php', '^/wp-json/complianz/'],
        post_permise: ['^/_andaxi/raport'],
        sonde: { ecran: true, dom: true, stiluri: true, text: true, cssom: true, mutatii: true, linie: true, instabil: true },
        scenarii: null,
        intarzieri: null,
        // documente_proaspete: true (trecerile de zgomot): documentul paginii se cere de pe site la fiecare încărcare, fără
        // cache și fără să intre în cache-ul persistent; restul resurselor vin ca de obicei
        documente_proaspete: false,
        ...c,
    };
}

function argumente() {
    const a = { config: null, doar: null, reia: false };
    for (const x of process.argv.slice(2)) {
        if (x.startsWith('--doar=')) { a.doar = x.slice(7); } else if (x === '--reia') { a.reia = true; } else if (!a.config) { a.config = x; } else { throw new Error('argument necunoscut: ' + x); }
    }
    if (!a.config) { throw new Error('folosire: captura.cjs <configurație.json> [--doar=<pagina>/<profil>/<fereastra>] [--reia]'); }
    return a;
}

// Ceasul navigărilor, comun tuturor lucrătorilor: cel puțin `pauza` între două navigări (politețea față de site).
function ceasNavigari(pauza) {
    let ultima = 0;
    let lant = Promise.resolve();
    return () => {
        lant = lant.then(async () => {
            const asteapta = ultima + pauza - Date.now();
            if (asteapta > 0) { await somn(asteapta); }
            ultima = Date.now();
        });
        return lant;
    };
}

function lista(c) {
    const iteme = [];
    for (const pg of c.pagini) {
        // o pagină își poate avea matricea ei (de exemplu scenariile unui modul doar pe paginile lui)
        for (const m of (pg.matrice || c.matrice)) {
            for (const f of m.ferestre) {
                for (const st of (m.stari || c.stari)) {
                    iteme.push({ pagina: pg.nume, cale: pg.cale, profil: m.profil, fereastra: P.fereastra(f).nume, stare: st, scenarii: m.scenarii || pg.scenarii || null });
                }
            }
        }
    }
    return iteme;
}

async function asteaptaLiniste(page, zbor, c) {
    const t0 = Date.now();
    let ultimNr = -1;
    let ultimaSchimbare = Date.now();
    for (;;) {
        let nr = ultimNr;
        try { nr = await page.evaluate(() => (window.__axParitate ? window.__axParitate.mo.length : 0)); } catch (e) { /* navigare în curs */ }
        if (nr !== ultimNr) { ultimNr = nr; ultimaSchimbare = Date.now(); }
        const acum = Date.now();
        if (zbor.n === 0 && acum - zbor.ultima >= 500 && acum - ultimaSchimbare >= c.liniste_ms) {
            return { atinsa: true, ms: acum - t0 };
        }
        if (acum - t0 > c.maxim_asteptare_ms) {
            return { atinsa: false, ms: acum - t0, inZbor: zbor.n, msDeLaUltimaMutatie: acum - ultimaSchimbare };
        }
        await somn(200);
    }
}

async function asteaptaRetea(zbor, maxim) {
    const t0 = Date.now();
    while (Date.now() - t0 < maxim) {
        if (zbor.n === 0 && Date.now() - zbor.ultima >= 500) { return true; }
        await somn(100);
    }
    return false;
}

async function fazaMasurare(page, zbor) {
    const lenese = await page.evaluate(async () => {
        const imagini = Array.from(document.images);
        const n = imagini.filter((i) => i.loading === 'lazy' && !i.complete).length;
        imagini.forEach((i) => { if (i.loading === 'lazy') { i.loading = 'eager'; } });
        const pana = (ms) => new Promise((gata) => setTimeout(gata, ms));
        await Promise.race([Promise.all(imagini.map((i) => (i.complete ? 0 : new Promise((gata) => {
            i.addEventListener('load', gata, { once: true });
            i.addEventListener('error', gata, { once: true });
        })))), pana(20000)]);
        await Promise.race([Promise.all(imagini.map((i) => (i.decode ? i.decode().catch(() => {}) : 0))), pana(10000)]);
        if (document.fonts && document.fonts.ready) { await Promise.race([document.fonts.ready, pana(5000)]); }
        return n;
    }).catch(() => -1);
    await asteaptaRetea(zbor, 20000);
    await page.waitForTimeout(300);
    return lenese;
}

async function capturaEcran(page) {
    return page.screenshot({ fullPage: true, animations: 'disabled', caret: 'hide', timeout: 120000 });
}

async function captureaza(item, unelte) {
    const { c, retea, depozit, browser, navigare, scenarii } = unelte;
    const pr = P.profil(item.profil);
    const f = P.fereastra(item.fereastra);
    const url = new URL(item.cale, c.site).href;
    const dosar = path.join(c.iesire, item.pagina, item.profil, f.nume, item.stare);
    const fisierMeta = path.join(dosar, 'meta.json');
    if (!unelte.reia && fs.existsSync(fisierMeta)) { return { sarit: true, meta: JSON.parse(fs.readFileSync(fisierMeta, 'utf8')) }; }
    fs.mkdirSync(dosar, { recursive: true });
    const idCaptura = [item.pagina, item.profil, f.nume, item.stare].join('/');
    const loc = { captura: idCaptura, pagina: item.pagina, profil: item.profil, fereastra: f.nume, stare: item.stare, acord: 'inainte' };
    const cereri = [];
    const context = await browser.newContext(P.optiuniContext(pr, f, c.optiuni_context));
    if (!c.navigator_simplu) { await context.addInitScript(P.scriptNavigator(pr)); }
    await context.addInitScript(S.init);
    const zbor = { n: 0, ultima: Date.now() };
    await context.route('**/*', async (route) => {
        if (c.intarzieri) {
            const tip = route.request().resourceType();
            const ms = c.intarzieri[tip];
            if (ms) { await somn(ms); }
        }
        return retea.ruta(route, () => loc);
    });
    const asculta = (e) => { if (e.captura === idCaptura) { cereri.push(e); } };
    retea.ascultatori.add(asculta);
    const page = await context.newPage();
    const erori = [];
    const consola = [];
    page.on('pageerror', (e) => erori.push(String((e && e.message) || e).split('\n')[0].slice(0, 300)));
    page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') { consola.push(m.type() + ': ' + m.text().slice(0, 300)); } });
    page.on('request', () => { zbor.n++; zbor.ultima = Date.now(); });
    const gata = () => { zbor.n = Math.max(0, zbor.n - 1); zbor.ultima = Date.now(); };
    page.on('requestfinished', gata);
    page.on('requestfailed', gata);
    const meta = { ...loc, rol: c.rol, cale: item.cale, url, ora: new Date().toISOString(), obiecte: {}, fereastraDim: [f.latime, f.inaltime], mobil: pr.mobil };
    const t0 = Date.now();
    try {
        await navigare();
        const r = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
        meta.status = r ? r.status() : 0;
        meta.urlFinal = page.url();
        meta.liniste = await asteaptaLiniste(page, zbor, c);
        // (a) HTML-ul static: octeții documentului primit de această încărcare (cererea ei din jurnal; altfel din cache)
        const cerereDoc = [...cereri].reverse().find((e) => e.tip === 'document' && e.url === page.url() && e.sha256);
        let corpDoc = cerereDoc ? retea.corpDupaSha(cerereDoc.sha256) : null;
        if (!corpDoc) { const d = retea.corpDin(page.url(), pr.ua); corpDoc = d ? d.corp : null; }
        const staticHtml = corpDoc ? corpDoc.toString('utf8') : '';
        if (corpDoc) { meta.obiecte.static = depozit.puneText(staticHtml, '.html'); meta.sha256Document = cerereDoc ? cerereDoc.sha256 : null; meta.documentProaspat = !!(cerereDoc && cerereDoc.actiune === 'descarcat'); }
        // (b) starea de la încărcare
        const a = await page.evaluate(S.instantaneu, { proprietati: S.PROPRIETATI, pseudo: S.PROPRIETATI_PSEUDO, dom: c.sonde.dom, text: c.sonde.text, cssom: c.sonde.cssom, stiluri: false });
        if (a.dom) { meta.obiecte.dom = depozit.puneText(a.dom, '.html'); delete a.dom; }
        if (a.cssom) { meta.obiecte.cssom = depozit.puneJson(a.cssom); meta.nrFoi = a.cssom.length; delete a.cssom; }
        meta.obiecte.incarcare = depozit.puneJson(a);
        meta.clase = { html: (a.doc.html && a.doc.html.class) || '', body: (a.doc.body && a.doc.body.class) || '' };
        meta.timp = a.timp;
        meta.csp = a.csp;
        if (c.sonde.mutatii && staticHtml) {
            const d = await page.evaluate(S.diferente, { html: staticHtml });
            meta.obiecte.mutatii = depozit.puneJson(d);
            meta.nrMutatii = d.mutatii.length;
        }
        if (c.sonde.linie) {
            const dcl = a.timp && a.timp.nav ? a.timp.nav.dclStart : 0;
            const l = await page.evaluate(S.linieTimp, { faraParsare: true, dcl });
            meta.obiecte.linie = depozit.puneJson(l);
        }
        // faza de măsurare (fără ea, când configurația cere doar starea de la încărcare și scenariile)
        if (!c.sonde.fara_masurare) { meta.leneseIncarcate = await fazaMasurare(page, zbor); }
        if (c.sonde.stiluri) {
            const b = await page.evaluate(S.instantaneu, { proprietati: S.PROPRIETATI, pseudo: S.PROPRIETATI_PSEUDO, dom: false, text: false, cssom: false, stiluri: true });
            meta.obiecte.masurare = depozit.puneJson(b);
            meta.nrElemente = b.stiluri.elemente.length;
            meta.dim = [b.doc.latime, b.doc.inaltime];
        }
        if (c.sonde.ecran) {
            const e1 = await capturaEcran(page);
            meta.obiecte.ecran = depozit.puneOcteti(e1, '.png');
            if (c.sonde.instabil) {
                await page.waitForTimeout(800);
                const e2 = await capturaEcran(page);
                if (!e1.equals(e2)) {
                    try {
                        const cmp = PNG.compara(PNG.decodeaza(e1), PNG.decodeaza(e2), { prag: 0.1 });
                        meta.instabil = { diferiti: cmp.diferiti, zone: cmp.zone, marimi: cmp.marimi };
                        if (cmp.diferiti > 0) { meta.obiecte.ecran2 = depozit.puneOcteti(e2, '.png'); }
                    } catch (err) { meta.instabil = { eroare: err.message }; }
                } else {
                    meta.instabil = null;
                }
            }
        }
        // scenariile de după (OBS-uri, stări): fiecare își scrie rezultatul în meta.scenarii
        const lista = item.scenarii || [];
        if (lista.length) {
            meta.scenarii = {};
            for (const nume of lista) {
                const fn = scenarii && scenarii[nume];
                if (!fn) { meta.scenarii[nume] = { eroare: 'scenariu necunoscut' }; continue; }
                // un context nou, cu același profil, aceleași scripturi de inițializare și aceeași politică de rețea (cookie-uri
                // proaspete: acordul dat într-un scenariu nu trece în altul); îl închide scenariul
                const contextNou = async (o) => {
                    const op = o || {};
                    const pr2 = op.profil ? P.profil(op.profil) : pr;
                    const f2 = op.fereastra ? P.fereastra(op.fereastra) : f;
                    const ctx2 = await browser.newContext(P.optiuniContext({ ...pr2, ...(op.ua ? { ua: op.ua } : {}) }, f2, { ...(c.optiuni_context || {}), ...(op.optiuni || {}) }));
                    if (!c.navigator_simplu) { await ctx2.addInitScript(P.scriptNavigator({ ...pr2, ...(op.navigator || {}) })); }
                    await ctx2.addInitScript(S.init);
                    for (const x of (op.init || [])) { await ctx2.addInitScript(x); }
                    const loc2 = { ...loc, ...(op.loc || {}) };
                    await ctx2.route('**/*', (route) => retea.ruta(route, () => loc2));
                    return { context: ctx2, page: await ctx2.newPage(), loc: loc2 };
                };
                try {
                    const rez = await fn(page, { context, profil: pr, fereastra: f, depozit, retea, loc, navigare, somn, S, item, c, contextNou, browser });
                    meta.scenarii[nume] = depozit.puneJson(rez);
                } catch (err) {
                    meta.scenarii[nume] = { eroare: String(err && err.message || err).split('\n')[0] };
                }
            }
        }
    } catch (e) {
        meta.eroare = String(e && e.message || e).split('\n')[0].slice(0, 500);
    } finally {
        retea.ascultatori.delete(asculta);
        // încălcările CSP din toate cadrele (și cele de după încărcare) și rapoartele primite de harness pe calea raportului
        try {
            const toate = [];
            for (const fr of page.frames()) {
                const l = await fr.evaluate(() => (window.__axParitate ? window.__axParitate.csp.slice() : [])).catch(() => []);
                for (const v of l) { toate.push(v); }
            }
            meta.csp = toate;
        } catch (e) { /* pagina închisă */ }
        meta.rapoarteCsp = retea.rapoarteCsp.filter((r) => r.captura === idCaptura);
        meta.erori = erori;
        meta.consola = consola.slice(0, 50);
        meta.durata_ms = Date.now() - t0;
        meta.obiecte.retea = depozit.puneJson(cereri);
        meta.retea = {
            cereri: cereri.length,
            abortate: cereri.filter((x) => x.actiune === 'abortat').length,
            descarcate: cereri.filter((x) => x.actiune === 'descarcat').length,
            terte: [...new Set(cereri.filter((x) => x.clasa === 'terta' || x.clasa === 'tracker').map((x) => new URL(x.url).host))].sort(),
        };
        await context.close().catch(() => {});
        fs.writeFileSync(fisierMeta + '.tmp', JSON.stringify(meta, null, 1));
        fs.renameSync(fisierMeta + '.tmp', fisierMeta);
    }
    return { sarit: false, meta };
}

async function main() {
    const a = argumente();
    IESIRE_GARDA.verificaConfig(a.config);
    const c = citesteConfig(a.config);
    // Recenzia 2, R3-6: ieșirea, cache-ul și jurnalul în afara repo-ului; din_paritate niciodată din pachetul public.
    c.iesire = IESIRE_GARDA.verificaIesirea(c.iesire, 'ieșirea');
    if (c.cache) { c.cache = IESIRE_GARDA.verificaIesirea(c.cache, 'cache-ul'); }
    if (c.jurnal) { c.jurnal = IESIRE_GARDA.verificaIesirea(c.jurnal, 'jurnalul'); }
    fs.mkdirSync(c.iesire, { recursive: true });
    const retea = new Retea({
        mod: c.rol, site: c.site, gazdeFonturi: c.gazde_fonturi, caiAbortate: c.cai_abortate, postPermise: c.post_permise, documenteProaspete: !!c.documente_proaspete,
        cache: c.cache || null, sursa: c.sursa, paralel: c.paralel, buget: c.buget, probeTrackere: c.probe_trackere,
        jurnal: c.jurnal || path.join(c.iesire, 'jurnal-retea.jsonl'), lacat: c.lacat || null,
        autentificare: process.env.PARITATE_POARTA_UTILIZATOR ? { utilizator: process.env.PARITATE_POARTA_UTILIZATOR, parola: process.env.PARITATE_POARTA_PAROLA || '' } : null,
    });
    retea.ascultatori = new Set();
    retea.ascultator = (e) => { for (const f of retea.ascultatori) { f(e); } };
    const depozit = new Depozit(c.iesire);
    const scenarii = c.scenarii ? require(path.resolve(c.scenarii)) : {};
    let iteme = lista(c);
    if (a.doar) {
        const re = new RegExp('^' + a.doar.split('/').map((x) => (x === '*' ? '[^/]+' : x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))).join('/'));
        iteme = iteme.filter((i) => re.test([i.pagina, i.profil, P.fereastra(i.fereastra).nume, i.stare].join('/')));
    }
    const browser = await chromium.launch({ proxy: { server: 'http://127.0.0.1:9' } });
    const navigare = ceasNavigari(c.sursa === 'retea' ? c.pauza_navigare_ms : 0);
    const unelte = { c, retea, depozit, browser, navigare, scenarii, reia: a.reia };
    const index = [];
    let cuErori = 0;
    let urmator = 0;
    const t0 = Date.now();
    const lucrator = async () => {
        for (;;) {
            const i = urmator++;
            if (i >= iteme.length) { return; }
            if (retea.oprita) { return; }
            const item = iteme[i];
            const r = await captureaza(item, unelte);
            const m = r.meta;
            index.push({ captura: m.captura, status: m.status, eroare: m.eroare || null });
            if (m.eroare) { cuErori++; }
            if (!r.sarit) {
                console.log(`[${i + 1}/${iteme.length}] ${m.captura} ${m.status || '-'} ${m.durata_ms} ms`
                    + ` elemente=${m.nrElemente || 0} mutatii=${m.nrMutatii || 0} cereri=${m.retea.cereri}`
                    + ` (desc ${m.retea.descarcate}, abort ${m.retea.abortate})${m.instabil ? ' INSTABIL ' + (m.instabil.diferiti || '') : ''}`
                    + `${m.liniste && !m.liniste.atinsa ? ' FARA-LINISTE' : ''}${m.eroare ? ' EROARE ' + m.eroare : ''}`
                    + ` buget=${JSON.stringify(retea.buget.stare.propriu)}`);
            }
        }
    };
    try {
        await Promise.all(Array.from({ length: Math.max(1, c.lucratori) }, () => lucrator()));
    } finally {
        await browser.close().catch(() => {});
        const rez = retea.inchide();
        const fis = path.join(c.iesire, 'index.json');
        let vechi = [];
        if (fs.existsSync(fis)) { try { vechi = JSON.parse(fs.readFileSync(fis, 'utf8')).capturi || []; } catch (e) { vechi = []; } }
        const toate = new Map(vechi.map((x) => [x.captura, x]));
        for (const x of index) { toate.set(x.captura, x); }
        fs.writeFileSync(fis, JSON.stringify({ rol: c.rol, site: c.site, actualizat: new Date().toISOString(), capturi: [...toate.values()].sort((p, q) => p.captura.localeCompare(q.captura)) }, null, 1));
        const sumar = { ora: new Date().toISOString(), durata_s: Math.round((Date.now() - t0) / 1000), capturi: index.length, cuErori, ...rez, oprita: retea.oprita };
        fs.appendFileSync(path.join(c.iesire, 'rulari.jsonl'), JSON.stringify(sumar) + '\n');
        console.log('GATA ' + JSON.stringify(sumar));
    }
    process.exitCode = cuErori || retea.oprita ? 1 : 0;
}

if (require.main === module) {
    main().catch((e) => { console.error('EROARE ' + (e && e.stack || e)); process.exit(2); });
}

module.exports = { citesteConfig, lista, captureaza, asteaptaLiniste, fazaMasurare, sha256 };
