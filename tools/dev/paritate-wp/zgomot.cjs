// Propunătorul măștilor de zgomot (DECIZIE §6.7; pasul 10.2): N încărcări proaspete ale referinței (aceleași capturi, documentul
// paginii neluat din cache) se compară două câte două cu compara.cjs; ce diferă între încărcări e instabil pe referință și
// devine o mască DECLARATĂ în paritate.json:
//   - motivul „instabil pe referință (k/N încărcări)”, propus_automat: true și dovada (capturile, grupurile de încărcări
//     identice, exemple de valori);
//   - ținta: selectorul unic al elementului (lib/selector.cjs; la sonda mutatii doar atributul sau clasele care diferă, iar
//     un nod în plus mascază părintele), dreptunghiurile zonei de pixeli acolo unde niciun element mic nu o cuprinde (doar pe
//     captura ei), iar la baleiajul antetului cheile elementelor din zonă;
//   - aria: lista exactă a capturilor în care s-a văzut (capturi), plus paginile, profilurile, ferestrele și stările lor, ca
//     rezumat; sondele afectate;
//   - la sonda stil, stările observate (valorile proprietăților care diferă, pe fiecare încărcare): masca nu acceptă orice,
//     ci doar una dintre ele, iar raportul spune pe care a căzut candidatul (de exemplu o cursă a site-ului viu, pe care
//     runtime-ul nostru o rezolvă într-o singură stare).
// k = câte încărcări se abat de la starea dominantă (cel mai mare grup de încărcări identice pe acea unitate); fără grup dominant
// (toate diferite) k = N; o încărcare în care și cele două capturi de ecran diferă în zonă se numără și ea. O zonă care diferă
// doar între cele două capturi de ecran ale aceleiași încărcări, dar nu între încărcări (comparația folosește prima captură),
// nu devine mască: e listată la „doar_in_captura”, ca indiciu.
// Nicio mască nu se aplică tăcut: fiecare intră în paritate.json și apare în lista măștilor din raport. Măștile propuse
// anterior (propus_automat) se scot înainte de comparație și se înlocuiesc (câmpurile scrise de mână pe ele, „nota” și
// „revizuit”, trec pe masca nouă cu aceeași țintă); celelalte măști rămân neatinse. Ce nu are element (head, cssom, înălțimea
// documentului, un element prea mare pentru o mască, o cheie fără selector unic) nu devine mască: e listat la „de_decis”.
//
//   node zgomot.cjs --incarcari=<d1>,<d2>[,…] --config=<paritate.json> --iesire=<dosar> [--felie=1] [--doar=<tipar>]
//       [--scrie=1 (scrie măștile în --config, cu o copie a celui vechi în <iesire>; altfel doar <iesire>/propuneri.json)]
//       [--refoloseste=1 (comparațiile perechilor deja scrise în <iesire>, dacă au fost făcute cu aceeași configurație de bază)]
// Cod 0 = gata (propunerile scrise); 2 = eroare de folosire.

'use strict';

const IESIRE_GARDA = require('./lib/iesire.cjs');
IESIRE_GARDA.pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo, prin nicio funcție
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('playwright');
const { Comparatie } = require('./compara.cjs');
const { Depozit } = require('./lib/depozit.cjs');
const { selectoriPentruChei } = require('./lib/selector.cjs');

const SONDE_ZGOMOT = ['pixeli', 'stil', 'geometrie', 'cssom', 'text', 'linkuri', 'imagini', 'head', 'mutatii'];
const FARA_ELEMENT = new Set(['(documentul)', '(innerText al paginii)']);
const PREA_MARE = /^html:1(>body:1)?$/;
const MARGINE = 2;

function argumente() {
    const a = { felie: '1' };
    for (const x of process.argv.slice(2)) {
        const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
        if (!m) { throw new Error('argument necunoscut: ' + x); }
        a[m[1]] = m[2];
    }
    for (const k of ['incarcari', 'config', 'iesire']) {
        if (!a[k]) { throw new Error('lipsește --' + k + '= (vezi capul fișierului)'); }
    }
    a.incarcari = a.incarcari.split(',').filter(Boolean).map((d) => path.resolve(d));
    if (a.incarcari.length < 2) { throw new Error('trebuie cel puțin două încărcări'); }
    // Recenzia 2, R3-6: ieșirea în afara repo-ului, configurația niciodată din pachetul public.
    a.iesire = IESIRE_GARDA.verificaIesirea(a.iesire, 'ieșirea');
    a.config = IESIRE_GARDA.verificaConfig(a.config);
    return a;
}

const intersectie = (z, w) => z[0] < w[0] + w[2] && z[0] + z[2] > w[0] && z[1] < w[1] + w[3] && z[1] + z[3] > w[1];
const uneste = (z, w) => {
    const x0 = Math.min(z[0], w[0]); const y0 = Math.min(z[1], w[1]);
    return [x0, y0, Math.max(z[0] + z[2], w[0] + w[2]) - x0, Math.max(z[1] + z[3], w[1] + w[3]) - y0];
};
const largit = (z, d) => [z[0] - d, z[1] - d, z[2] + 2 * d, z[3] + 2 * d];
const inPagina = (z) => { const x = Math.max(0, z[0]); const y = Math.max(0, z[1]); return [x, y, z[2] - (x - z[0]), z[3] - (y - z[1])]; };
const cuprinde = (e, z) => e[0] <= z[0] + MARGINE && e[1] <= z[1] + MARGINE && e[0] + e[2] >= z[0] + z[2] - MARGINE && e[1] + e[3] >= z[1] + z[3] - MARGINE;

// Grupurile de încărcări identice pe o unitate (perechile fără diferență le unesc) și k.
function grupuri(incarcari, perechiDiferite, perechiVazute, inCaptura) {
    const tata = new Map(incarcari.map((i) => [i, i]));
    const rad = (i) => { while (tata.get(i) !== i) { i = tata.get(i); } return i; };
    for (const [i, j] of perechiVazute) {
        if (!perechiDiferite.some(([p, q]) => (p === i && q === j) || (p === j && q === i))) { tata.set(rad(i), rad(j)); }
    }
    const g = new Map();
    for (const i of incarcari) { const r = rad(i); if (!g.has(r)) { g.set(r, []); } g.get(r).push(i); }
    const lista = [...g.values()].sort((p, q) => q.length - p.length || p[0] - q[0]);
    const dominant = lista[0].length >= 2 ? new Set(lista[0]) : new Set();
    const abatute = new Set(incarcari.filter((i) => !dominant.has(i)));
    for (const i of inCaptura) { abatute.add(i); }
    let k = abatute.size;
    if (k === 0 && (perechiDiferite.length || inCaptura.length)) { k = 1; } // diferențe netranzitive (în toleranță)
    return { k, grupuri: lista };
}

// Zonele de pixeli grupate pe suprapunere (cu 8 px de apropiere), din perechi și din capturile de ecran ale aceleiași încărcări.
function roiuri(zone, inCaptura) {
    const l = [];
    const adauga = (z, sursa) => {
        let r = l.find((q) => intersectie(largit(z, 8), q.z));
        if (!r) { r = { z: z.slice(0, 4), perechi: [], inCaptura: new Set(), pixeli: 0 }; l.push(r); } else { r.z = uneste(r.z, z); }
        if (sursa.inc !== undefined) { r.inCaptura.add(sursa.inc); } else { r.perechi.push([sursa.i, sursa.j]); r.pixeli += sursa.pixeli || 0; }
    };
    for (const zz of zone) { adauga(zz.z, zz); }
    for (const [i, zl] of inCaptura) { for (const z of zl) { adauga(z, { inc: i }); } }
    for (let schimbat = true; schimbat;) {
        schimbat = false;
        for (let p = 0; p < l.length && !schimbat; p++) {
            for (let q = p + 1; q < l.length; q++) {
                if (intersectie(largit(l[p].z, 8), l[q].z)) {
                    const r = l[p]; const s = l[q];
                    r.z = uneste(r.z, s.z); r.perechi.push(...s.perechi); s.inCaptura.forEach((v) => r.inCaptura.add(v)); r.pixeli += s.pixeli;
                    l.splice(q, 1); schimbat = true; break;
                }
            }
        }
    }
    return l;
}

async function main() {
    const a = argumente();
    const configText = fs.readFileSync(a.config, 'utf8');
    const config = JSON.parse(configText);
    fs.mkdirSync(a.iesire, { recursive: true });
    const vechi = (config.masti || []).filter((m) => m.propus_automat);
    const baza = { ...config, masti: (config.masti || []).filter((m) => !m.propus_automat) };
    const fBaza = path.join(a.iesire, 'config-baza.json');
    const bazaText = JSON.stringify(baza, null, 1);
    fs.writeFileSync(fBaza, bazaText);
    const bazaSha = crypto.createHash('sha256').update(bazaText).digest('hex');
    const N = a.incarcari.length;
    const nume = a.incarcari.map((d) => path.basename(d));
    // perechile: ciclul (fiecare încărcare e o dată referință, pentru capturile ei de ecran) și restul perechilor
    const perechi = [];
    for (let i = 0; i < N; i++) { if (N > 2 || i === 0) { perechi.push([i, (i + 1) % N, true]); } }
    for (let i = 0; i < N; i++) {
        for (let j = i + 1; j < N; j++) { if (!perechi.some(([p, q]) => (p === i && q === j) || (p === j && q === i))) { perechi.push([i, j, false]); } }
    }
    const rezPerechi = [];
    for (const [i, j, cuInstabil] of perechi) {
        const out = path.join(a.iesire, 'perechi', nume[i] + '--' + nume[j]);
        const f = path.join(out, 'comparatii.json');
        if (a.refoloseste && fs.existsSync(f)) {
            const c0 = JSON.parse(fs.readFileSync(f, 'utf8'));
            if (c0.config_sha256 === bazaSha && !!c0.instabil === !!cuInstabil) { console.log('== perechea ' + nume[i] + ' / ' + nume[j] + ' (refolosită)'); rezPerechi.push({ i, j, c: c0 }); continue; }
        }
        console.log('== perechea ' + nume[i] + ' / ' + nume[j]);
        const c = new Comparatie({
            referinta: a.incarcari[i], candidat: a.incarcari[j], config: fBaza, iesire: out, felie: a.felie, 'doar-comune': '1',
            sonde: SONDE_ZGOMOT.join(','), ...(a.doar ? { doar: a.doar } : {}), ...(cuInstabil ? { instabil: N === 2 ? 'ambele' : 'ref' } : {}),
        });
        await c.ruleaza();
        rezPerechi.push({ i, j, c: JSON.parse(fs.readFileSync(path.join(out, 'comparatii.json'), 'utf8')) });
    }
    // pe captură: încărcările în care există, unitățile instabile (cheia unității → perechile în care a diferit)
    const capturi = new Map();
    const ia = (id) => {
        if (!capturi.has(id)) { capturi.set(id, { id, incarcari: new Set(), perechi: [], unitati: new Map(), zone: [], inCaptura: new Map(), pasi: [], deDecis: [] }); }
        return capturi.get(id);
    };
    const unitate = (x, cheie, o, i, j, exemplu) => {
        if (!x.unitati.has(cheie)) { x.unitati.set(cheie, { ...o, perechi: [], exemple: [] }); }
        const u = x.unitati.get(cheie);
        u.perechi.push([i, j]);
        if (exemplu && u.exemple.length < 3) { u.exemple.push({ pereche: nume[i] + '/' + nume[j], ...exemplu }); }
        return u;
    };
    for (const { i, j, c } of rezPerechi) {
        for (const r of c.rezultate) {
            const x = ia(r.captura);
            x.meta = x.meta || { pagina: r.pagina, profil: r.profil, fereastra: r.fereastra, stare: r.stare };
            x.incarcari.add(i); x.incarcari.add(j);
            const pereche = [nume[i], nume[j]];
            if (r.eroare) { x.deDecis.push({ pereche, motiv: 'eroare: ' + r.eroare }); continue; }
            x.perechi.push([i, j]);
            const baleiaj = !!(r.sonde && r.sonde.pixeli && r.sonde.pixeli.pasi !== undefined);
            x.baleiaj = x.baleiaj || baleiaj;
            for (const [s, v] of Object.entries(r.sonde || {})) {
                if (baleiaj) {
                    if (s === 'pixeli') { for (const p of v.pasi_zone || []) { x.pasi.push({ ...p, i, j }); } continue; }
                    if (v.stare !== 'picat') { continue; }
                    for (const k of v.chei || []) { unitate(x, 'baleiaj|' + s + '|' + k, { fel: 'baleiaj', sonda: s, k }, i, j, (v.detalii || []).find((d) => d.k === k)); }
                    continue;
                }
                if (s === 'pixeli') {
                    (v.exacte || []).forEach((z, n) => x.zone.push({ z, i, j, pixeli: (v.zone[n] || [])[4] || 0 }));
                    if (v.zone_complete === false) { x.deDecis.push({ pereche, sonda: 'pixeli', motiv: 'peste 200 de zone' }); }
                    for (const [parte, ic] of Object.entries(v.instabil_captura || {})) {
                        const inc = parte === 'referinta' ? i : j;
                        if (!x.inCaptura.has(inc)) { x.inCaptura.set(inc, ic.exacte || []); }
                    }
                    continue;
                }
                if (v.stare !== 'picat') { continue; }
                if (s === 'mutatii') {
                    if (!v.unitati || v.chei_complete === false) { x.deDecis.push({ pereche, sonda: s, motiv: 'lista diferențelor e tăiată', diferente: v.diferente }); continue; }
                    for (const d of v.unitati) {
                        const ex = (v.detalii || []).find((y) => y.k === d.k && y.tip === d.tip && (y.atribut || null) === (d.atribut || null));
                        const exemplu = ex ? { tip: ex.tip, k: ex.k, atribut: ex.atribut, referinta: ex.referinta, candidat: ex.candidat, referinta_plus: ex.referinta_plus, candidat_plus: ex.candidat_plus } : { tip: d.tip, k: d.k };
                        if (d.tip === 'atribut') {
                            unitate(x, 'mutatii|atribut|' + d.k + '|' + d.atribut, { fel: 'atribute', sonda: s, k: d.k, atribut: d.atribut, inc: j, varianta: 'm:' }, i, j, exemplu);
                        } else if (d.tip === 'clase') {
                            const u = unitate(x, 'mutatii|clase|' + d.k, { fel: 'clase', sonda: s, k: d.k, inc: j, varianta: 'm:' }, i, j, exemplu);
                            u.clase = [...new Set([...(u.clase || []), ...(d.clase || [])])];
                        } else if (d.tip === 'text') {
                            unitate(x, 'mutatii|text|' + d.k, { fel: 'element', sonda: s, k: d.k, inc: j, varianta: 'm:' }, i, j, exemplu);
                        } else if (d.tip === 'nod-doar-in-referinta' || d.tip === 'nod-doar-in-candidat') {
                            unitate(x, 'mutatii|nod|' + d.parinte, { fel: 'element', sonda: s, k: d.parinte, inc: j, varianta: 'm:' }, i, j, exemplu);
                        } else { x.deDecis.push({ pereche, sonda: s, motiv: 'diferență fără element (' + d.tip + ')' }); }
                    }
                    continue;
                }
                if (!(v.chei || []).length || v.chei_complete === false) {
                    x.deDecis.push({ pereche, sonda: s, motiv: (v.chei || []).length ? 'lista cheilor e tăiată sau are diferențe fără element' : 'diferențe fără element', diferente: v.diferente, detalii: (v.detalii || []).slice(0, 2) });
                    if (!(v.chei || []).length) { continue; }
                }
                for (const k of v.chei || []) {
                    if (FARA_ELEMENT.has(k)) {
                        // textul întregii pagini se scrie fără textul elementelor mascate: îl rezolvă masca elementului instabil
                        if (k === '(innerText al paginii)' && v.chei.some((q) => !FARA_ELEMENT.has(q))) { continue; }
                        x.deDecis.push({ pereche, sonda: s, k, motiv: 'diferență fără element', detalii: (v.detalii || []).filter((d) => d.k === k).slice(0, 2) });
                        continue;
                    }
                    const u = unitate(x, s + '|' + k, { fel: 'element', sonda: s, k, inc: i, varianta: '' }, i, j, (v.detalii || []).find((y) => (y.k || y.parinte) === k));
                    if (s === 'stil') { u.proprietati = [...new Set([...(u.proprietati || []), ...(v.detalii || []).filter((y) => y.k === k && y.proprietate && !/^::/.test(y.proprietate)).map((y) => y.proprietate)])]; }
                }
            }
        }
    }
    const depozite = a.incarcari.map((d) => new Depozit(d));
    const metaDin = (d, id) => {
        const f = path.join(d, ...id.split('/'), 'meta.json');
        return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : null;
    };
    const browser = await chromium.launch({ proxy: { server: 'http://127.0.0.1:9' } });
    const ctx = await browser.newContext();
    await ctx.route('**/*', (r) => r.abort());
    const pagina = await ctx.newPage();
    await pagina.setContent('<!DOCTYPE html><title>zgomot</title>');
    const ignora = ((config.normalizari || {}).dom || {}).ignora_elemente || [];
    const tinte = [];
    const deDecis = [];
    const doarInCaptura = [];
    for (const x of [...capturi.values()].sort((p, q) => p.id.localeCompare(q.id))) {
        const inc = [...x.incarcari].sort();
        for (const d of x.deDecis) { deDecis.push({ captura: x.id, ...d }); }
        const dovadaUnitatii = (u, g) => ({ captura: x.id, sonda: u.sonda, cheie: u.k, ...(u.atribut ? { atribut: u.atribut } : {}), k: g.k, grupuri: g.grupuri.map((l) => l.map((i) => nume[i])), exemple: u.exemple });
        if (x.baleiaj) {
            // baleiajul antetului: cheile din zonă; zonele de pixeli ale pașilor → cel mai mic element al pasului care le cuprinde
            const m0 = metaDin(a.incarcari[inc[0]], x.id);
            const bal = m0 && m0.scenarii && typeof m0.scenarii.baleiaj === 'string' ? depozite[inc[0]].citesteJson(m0.scenarii.baleiaj) : null;
            const pePas = new Map(((bal && bal.pasi) || []).map((p) => [p.w, p]));
            for (const p of x.pasi) {
                const pas = pePas.get(p.w);
                const d = p.decupaj || (pas && pas.decupaj) || [0, 0];
                for (const z of p.exacte || []) {
                    const zp = [z[0] + d[0], z[1] + d[1], z[2], z[3]];
                    const el = pas ? pas.el.filter((e) => !e.m && e.g[2] > 0 && e.g[3] > 0 && cuprinde(e.g, zp)).sort((q, r) => q.g[2] * q.g[3] - r.g[2] * r.g[3])[0] : null;
                    if (!el || el.k.split('>').length < 2) { deDecis.push({ captura: x.id, sonda: 'pixeli', motiv: 'zona baleiajului la ' + p.w + ' px nu e cuprinsă de un element al zonei', zona: z }); continue; }
                    unitate(x, 'baleiaj|pixeli|' + el.k, { fel: 'baleiaj', sonda: 'pixeli', k: el.k }, p.i, p.j, { w: p.w, zona: z });
                }
            }
            const pe = new Map();
            for (const u of x.unitati.values()) {
                const g = grupuri(inc, u.perechi, x.perechi, []);
                if (!pe.has(u.k)) { pe.set(u.k, { sonde: new Set(), k: 0, dovezi: [] }); }
                const t = pe.get(u.k);
                t.sonde.add(u.sonda); t.k = Math.max(t.k, g.k);
                if (t.dovezi.length < 3) { t.dovezi.push(dovadaUnitatii(u, g)); }
            }
            // o cheie sub alta (pe aceleași sonde) e acoperită de aceea
            const chei = [...pe.keys()];
            const peSonde = new Map();
            for (const k of chei.filter((q) => !chei.some((w) => w !== q && q.startsWith(w + '>') && [...pe.get(q).sonde].every((s) => pe.get(w).sonde.has(s))))) {
                const t = pe.get(k);
                const s = [...t.sonde].sort().join(',');
                if (!peSonde.has(s)) { peSonde.set(s, { chei: [], k: 0, dovezi: [] }); }
                const g = peSonde.get(s);
                g.chei.push(k); g.k = Math.max(g.k, t.k); g.dovezi.push(...t.dovezi);
            }
            for (const [s, g] of peSonde) {
                tinte.push({ fel: 'baleiaj', tinta: { baleiaj_chei: g.chei.sort() }, sonde: s.split(','), k: g.k, n: inc.length, captura: x.id, meta: x.meta, dovezi: g.dovezi.slice(0, 6) });
            }
            continue;
        }
        // zonele de pixeli; cele instabile doar în interiorul încărcărilor sunt indicii, nu măști
        const rs = roiuri(x.zone, x.inCaptura);
        for (const r of rs.filter((q) => !q.perechi.length)) { doarInCaptura.push({ captura: x.id, zona: r.z, incarcari: [...r.inCaptura].map((i) => nume[i]) }); }
        const rsPerechi = rs.filter((q) => q.perechi.length);
        if (!x.unitati.size && !rsPerechi.length) { continue; }
        // DOM-ul (după scripturi), HTML-ul static și măsurarea fiecărei încărcări, la nevoie
        const cache = new Map();
        const date = (i) => {
            if (!cache.has(i)) {
                const m = metaDin(a.incarcari[i], x.id);
                cache.set(i, m && m.obiecte && m.obiecte.dom ? {
                    html: depozite[i].citesteText(m.obiecte.dom, '.html'),
                    htmlStatic: m.obiecte.static ? depozite[i].citesteText(m.obiecte.static, '.html') : null,
                    mas: m.obiecte.masurare ? depozite[i].citesteJson(m.obiecte.masurare) : null, fereastra: m.fereastraDim || [0, 1000],
                } : null);
            }
            return cache.get(i);
        };
        const d0 = date(inc[0]);
        const elemente = d0 && d0.mas ? d0.mas.stiluri.elemente.filter((e) => e.g[2] > 0 && e.g[3] > 0) : [];
        const aria = d0 && d0.mas ? d0.mas.doc.latime * Math.max(1, Math.min(d0.mas.doc.inaltime, d0.fereastra[1] * 3)) : 1e9;
        for (const r of rsPerechi) {
            const g = grupuri(inc, r.perechi, x.perechi, [...r.inCaptura]);
            r.k = g.k; r.grupuri = g.grupuri;
            // cel mai mic element care cuprinde zona (±2 px); dacă e mult mai mare decât zona (peste 16 ori și peste 150 000 px²,
            // cât un modul), rămâne dreptunghiul
            const el = elemente.filter((e) => cuprinde(e.g, r.z) && !PREA_MARE.test(e.k)).sort((p, q) => p.g[2] * p.g[3] - q.g[2] * q.g[3])[0];
            const az = Math.max(1, r.z[2] * r.z[3]);
            if (el && el.g[2] * el.g[3] <= Math.max(16 * az, 150000) && el.g[2] * el.g[3] <= aria * 0.25) { r.k0 = el.k; }
        }
        // selectorii: pe încărcarea în care cheia e valabilă (referința perechii la sondele măsurării, candidatul la mutatii)
        const deRezolvat = new Map();
        const cere = (i, k, varianta) => {
            if (!deRezolvat.has(i)) { deRezolvat.set(i, { chei: new Set(), cheiM: new Set() }); }
            deRezolvat.get(i)[varianta === 'm:' ? 'cheiM' : 'chei'].add(k);
        };
        for (const u of x.unitati.values()) { cere(u.inc !== undefined ? u.inc : inc[0], u.k, u.varianta || ''); }
        for (const r of rsPerechi) { if (r.k0) { cere(inc[0], r.k0, ''); } }
        const sel = new Map();
        for (const [i, cer] of deRezolvat) {
            const d = date(i);
            if (!d) { continue; }
            const rez = await pagina.evaluate(selectoriPentruChei, { html: d.html, htmlStatic: d.htmlStatic, chei: [...cer.chei], cheiFaraIgnorate: [...cer.cheiM], ignora });
            for (const [k, v] of Object.entries(rez)) { sel.set(i + '|' + k, v); }
        }
        const selector = (i, k, varianta) => sel.get(i + '|' + (varianta === 'm:' ? 'm:' : '') + k);
        // țintele capturii: elementele (selectorul și sondele), atributele și clasele, dreptunghiurile
        const peSelector = new Map();
        const puneTinta = (cheie, o, sonda, k, dovada) => {
            if (!peSelector.has(cheie)) { peSelector.set(cheie, { ...o, sonde: new Set(), k: 0, dovezi: [], chei: new Set() }); }
            const t = peSelector.get(cheie);
            t.sonde.add(sonda); t.k = Math.max(t.k, k); if (o.cheie) { t.chei.add(o.cheie); }
            if (o.clase) { t.clase = [...new Set([...(t.clase || []), ...o.clase])].sort(); }
            if (t.dovezi.length < 4) { t.dovezi.push(dovada); }
        };
        for (const u of x.unitati.values()) {
            const g = grupuri(inc, u.perechi, x.perechi, []);
            const i = u.inc !== undefined ? u.inc : inc[0];
            const s = selector(i, u.k, u.varianta || '');
            if (!s || s.prinde !== 1) { deDecis.push({ captura: x.id, sonda: u.sonda, cheie: u.k, motiv: s ? 'niciun selector unic (prinde ' + s.prinde + ')' : 'cheia nu e în DOM-ul încărcării ' + nume[i], exemple: u.exemple.slice(0, 2) }); continue; }
            if (u.fel === 'element' && PREA_MARE.test(u.k)) { deDecis.push({ captura: x.id, sonda: u.sonda, cheie: u.k, motiv: 'element prea mare pentru o mască (' + s.selector + ')', exemple: u.exemple.slice(0, 2) }); continue; }
            const dovada = dovadaUnitatii(u, g);
            if (u.fel === 'atribute') {
                puneTinta('a|' + s.selector + '|' + u.atribut, { fel: 'atribute', selector: s.selector, atribute: [u.atribut], cheie: u.k }, 'mutatii', g.k, dovada);
            } else if (u.fel === 'clase') {
                puneTinta('c|' + s.selector, { fel: 'clase', selector: s.selector, clase: u.clase || [], cheie: u.k }, 'mutatii', g.k, dovada);
            } else {
                puneTinta('e|' + s.selector, { fel: 'element', selector: s.selector, cheie: u.k }, u.sonda, g.k, dovada);
                // stil: stările observate ale proprietăților care diferă, pe fiecare încărcare (candidatul trebuie să cadă pe una)
                if (u.sonda === 'stil' && (u.proprietati || []).length) {
                    const t = peSelector.get('e|' + s.selector);
                    const peInc = inc.map((li) => {
                        const d = date(li);
                        const e = d && d.mas ? d.mas.stiluri.elemente.find((y) => y.k === u.k) : null;
                        return e ? u.proprietati.map((pr) => { const ix = d.mas.stiluri.proprietati.indexOf(pr); return ix < 0 ? null : d.mas.stiluri.tabel[e.v[ix]]; }) : null;
                    });
                    const stari = [];
                    peInc.forEach((val, n) => {
                        if (!val) { return; }
                        const st = stari.find((y) => JSON.stringify(y.valori) === JSON.stringify(val));
                        if (st) { st.incarcari.push(nume[inc[n]]); } else { stari.push({ valori: val, incarcari: [nume[inc[n]]] }); }
                    });
                    t.stari = t.stari || {};
                    t.stari[u.k] = { proprietati: u.proprietati, stari };
                }
            }
        }
        const dreptunghiuri = [];
        for (const r of rsPerechi) {
            const s = r.k0 ? selector(inc[0], r.k0, '') : null;
            const dovada = { captura: x.id, sonda: 'pixeli', zona: r.z, pixeli: r.pixeli, k: r.k, grupuri: r.grupuri.map((l) => l.map((i) => nume[i])), in_captura: [...r.inCaptura].map((i) => nume[i]) };
            if (s && s.prinde === 1) { puneTinta('e|' + s.selector, { fel: 'element', selector: s.selector, cheie: r.k0 }, 'pixeli', r.k, dovada); } else { dreptunghiuri.push({ z: inPagina(largit(r.z, MARGINE)), k: r.k, dovada }); }
        }
        // un element sub altul (pe aceleași sonde) e acoperit de acela; un atribut sau o clasă, de masca elementului lor (sau a unui
        // strămoș) pe sonda mutatii, care golește elementul cu tot cu atribute
        const lista = [...peSelector.values()];
        const elem = lista.filter((t) => t.fel === 'element');
        const acoperit = (t) => elem.some((q) => q !== t && [...t.sonde].every((s) => q.sonde.has(s))
            && [...t.chei].every((c) => [...q.chei].some((d) => c.startsWith(d + '>') || (t.fel !== 'element' && c === d))));
        for (const t of lista) {
            if (acoperit(t)) { continue; }
            const tinta = { selector: t.selector, ...(t.atribute ? { atribute: t.atribute } : {}), ...(t.fel === 'clase' ? { clase: t.clase } : {}) };
            tinte.push({ fel: t.fel, tinta, sonde: [...t.sonde].sort(), k: t.k, n: inc.length, captura: x.id, meta: x.meta, dovezi: t.dovezi, ...(t.stari ? { stari: t.stari } : {}) });
        }
        if (dreptunghiuri.length) {
            tinte.push({ fel: 'dreptunghi', tinta: { dreptunghiuri: dreptunghiuri.map((d) => d.z) }, sonde: ['pixeli'], k: Math.max(...dreptunghiuri.map((d) => d.k)), n: inc.length, captura: x.id, meta: x.meta, dovezi: dreptunghiuri.map((d) => d.dovada).slice(0, 6) });
        }
    }
    await browser.close();
    // măștile: aceeași țintă și aceleași sonde → o singură mască, pe lista exactă a capturilor în care s-a văzut
    // (dreptunghiurile și cheile baleiajului rămân pe captura lor); apoi elementele de pe aceleași pagini, cu aceleași sonde,
    // într-o singură mască cu lista selectorilor (de exemplu aceeași cursă pe două rânduri)
    const grup0 = new Map();
    for (const t of tinte) {
        const cheie = (t.fel === 'dreptunghi' || t.fel === 'baleiaj' ? t.captura + '|' : '') + t.fel + '|' + JSON.stringify(t.tinta) + '|' + t.sonde.join(',');
        if (!grup0.has(cheie)) { grup0.set(cheie, []); }
        grup0.get(cheie).push(t);
    }
    const grup = new Map();
    for (const [cheie, l] of grup0) {
        const t = l[0];
        const c2 = t.fel === 'element' ? 'element|' + [...new Set(l.map((x) => x.meta.pagina))].sort().join(',') + '|' + t.sonde.join(',') : cheie;
        if (!grup.has(c2)) { grup.set(c2, []); }
        grup.get(c2).push(...l);
    }
    for (const l of grup.values()) {
        if (l[0].fel !== 'element') { continue; }
        const sel = [...new Set(l.flatMap((x) => x.tinta.selector.split(', ')))].sort().join(', ');
        for (const x of l) { x.tinta = { selector: sel }; }
    }
    const masti = [];
    let nr = 0;
    const scurt = (s) => (s.length > 70 ? s.slice(0, 67) + '…' : s);
    const ordonate = [...grup.values()].sort((p, q) => q.length - p.length || p[0].captura.localeCompare(q[0].captura));
    for (const l of ordonate) {
        nr++;
        const t = l[0];
        const capt = [...new Set(l.map((x) => x.captura))].sort();
        const uniq = (f) => [...new Set(l.map((x) => x.meta[f]))].sort();
        const k = Math.max(...l.map((x) => x.k));
        const n = Math.max(...l.map((x) => x.n));
        const tintaText = t.tinta.selector ? t.tinta.selector + (t.tinta.atribute ? ' [' + t.tinta.atribute.join(', ') + ']' : '') + (t.tinta.clase ? ' .{' + t.tinta.clase.join(' ') + '}' : '')
            : t.tinta.dreptunghiuri ? t.tinta.dreptunghiuri.length + ' dreptunghiuri' : 'baleiaj: ' + t.tinta.baleiaj_chei.length + ' chei';
        const masca = {
            nume: 'zgomot ' + String(nr).padStart(2, '0') + ': ' + scurt(tintaText) + ' · ' + t.sonde.join(', ') + ' · ' + (capt.length === 1 ? capt[0] : capt.length + ' capturi'),
            ...t.tinta,
            capturi: capt, pagini: uniq('pagina'), profiluri: uniq('profil'), ferestre: uniq('fereastra'), stari: uniq('stare'), sonde: t.sonde,
            motiv: 'instabil pe referință (' + k + '/' + n + ' încărcări)',
            propus_automat: true, declarata: true, k, n, incarcari: nume,
            dovada: l.flatMap((x) => x.dovezi).slice(0, 12),
        };
        // stil: stările observate, pe captură și element; compara.cjs cere ca candidatul să cadă pe una și spune pe care
        if (l.some((x) => x.stari)) { masca.stari_observate = Object.fromEntries(l.filter((x) => x.stari).map((x) => [x.captura, x.stari])); }
        // câmpurile scrise de mână pe o mască propusă înainte, cu aceeași țintă, trec pe cea nouă
        const tintaDe = (o) => JSON.stringify([o.selector || null, o.atribute || null, o.clase || null, o.dreptunghiuri || null, o.baleiaj_chei || null]);
        const v = vechi.find((o) => tintaDe(o) === tintaDe(masca) && JSON.stringify(o.sonde) === JSON.stringify(masca.sonde));
        for (const c of ['nota', 'revizuit']) { if (v && v[c] !== undefined) { masca[c] = v[c]; } }
        masti.push(masca);
    }
    const iesire = {
        ora: new Date().toISOString(), incarcari: a.incarcari, config: path.resolve(a.config), felie: a.felie,
        perechi: perechi.map(([i, j]) => nume[i] + '/' + nume[j]), capturi: capturi.size,
        sumar: {
            masti: masti.length, pe_selector: masti.filter((m) => m.selector && !m.atribute && !m.clase).length, pe_atribute: masti.filter((m) => m.atribute || m.clase).length,
            pe_dreptunghi: masti.filter((m) => m.dreptunghiuri).length, pe_baleiaj: masti.filter((m) => m.baleiaj_chei).length,
            capturi_cu_masti: new Set(masti.flatMap((m) => m.capturi)).size, de_decis: deDecis.length, doar_in_captura: doarInCaptura.length, inlocuite: vechi.length,
        },
        masti, de_decis: deDecis, doar_in_captura: doarInCaptura,
    };
    fs.writeFileSync(path.join(a.iesire, 'propuneri.json'), JSON.stringify(iesire, null, 1));
    if (a.scrie) {
        fs.writeFileSync(path.join(a.iesire, 'paritate.inainte.json'), configText);
        const nou = { ...config, masti: [...baza.masti, ...masti] };
        fs.writeFileSync(a.config, JSON.stringify(nou, null, 1) + '\n');
    }
    console.log('ZGOMOT ' + JSON.stringify(iesire.sumar) + (a.scrie ? ' — scrise în ' + a.config : ' — propuneri în ' + path.join(a.iesire, 'propuneri.json')));
    return 0;
}

if (require.main === module) {
    main().then((c) => { process.exitCode = c; }).catch((e) => { console.error('EROARE ' + (e && e.stack || e)); process.exit(2); });
}

module.exports = { grupuri, roiuri };
