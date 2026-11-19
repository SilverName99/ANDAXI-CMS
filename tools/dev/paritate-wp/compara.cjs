// Comparația harness-ului de paritate (P3; DECIZIE §6.2, §6.6, §6.7): capturile candidatului față de capturile referinței,
// aceeași pagină, profil, fereastră și stare, sondă cu sondă:
//   pixeli (prag 0,1, doar măștile declarate; o diferență într-o zonă instabilă a referinței se numără și e doar marcată:
//   zona devine mască numai declarată, cu zgomot.cjs), stil (proprietățile calculate pe
//   cheia drumului de etichete, după rotunjire), geometrie (±toleranță), cssom (foile și regulile, în ordine, cu adresele
//   rezolvate), text (punctele de cod exacte), linkuri, imagini (și sha256 al octeților primiți), head, mutatii (DOM-ul după
//   scripturi, nod cu nod: inventarul mutațiilor trebuie să fie același), retea (nicio cerere terță înainte de acord, niciun
//   4xx/5xx, nicio cerere de tracker fără fișier de probă, aceleași adrese proprii), csp (nicio încălcare în afara excepțiilor).
// Normalizările sunt doar cele declarate în paritate.json (listă închisă, DECIZIE §6.7); fiecare are un nume și un motiv.
// Măștile (paritate.json „masti”) au un nume și un motiv și o arie: pagini, profiluri, ferestre („1440” sau „1440x900”), stari,
// felii, capturi (lista exactă „pagina/profil/fereastra/stare”), sonde; prind elemente (selector), doar atribute sau clase ale
// elementelor (selector + atribute / clase: doar sonda mutatii), dreptunghiuri (dreptunghi / dreptunghiuri, în coordonatele
// paginii) sau, la baleiajul antetului, elemente după cheia lor din zonă (baleiaj_chei). Fiecare captură spune ce măști a
// avut în arie și ce au prins (rez.masti), pentru lista măștilor din raport.
// Regiunile punctelor (paritate.json „harta”: regiuni {nume: {selector | dreptunghiuri, pagini}} și id[ID].regiune, un nume
// de regiune, un selector sau {selector | dreptunghiuri}) se rezolvă pe fiecare captură: rez.regiuni[nume] = {chei,
// dreptunghiuri} (pentru ID-urile din alte regiuni: „id:<ID>”); fiecare sondă dă lista completă a cheilor diferite (chei,
// chei_complete), iar pixelii toate zonele (zone, exacte, zone_complete), ca raportul să le împartă pe regiuni.
//
//   node compara.cjs --referinta=<dosar> --candidat=<dosar> --config=<paritate.json> --iesire=<dosar> [--felie=1]
//       [--doar=<pagina>/<profil>/<fereastra>/<stare> (cu *)] [--doar-comune=1 (diagnostic: doar capturile de pe ambele părți)]
//       [--sonde=<s1>,<s2> (diagnostic: alte sonde decât ale feliei)] [--instabil=ref|ambele (zgomot.cjs: zonele în care cele
//       două capturi de ecran ale aceleiași încărcări diferă, în afara măștilor)]
//
// Ieșirea (privată): <iesire>/comparatii.json (pe captură și sondă: trecut/picat, numărul diferențelor, primele diferențe,
// dovezile) și <iesire>/dovezi/ (suprapunerile diferențelor de pixeli). Cod 0 = nicio diferență nedeclarată; 1 = diferențe;
// 2 = eroare de folosire.

'use strict';

const IESIRE_GARDA = require('./lib/iesire.cjs');
IESIRE_GARDA.pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo, prin nicio funcție
const REGULI_CSS = require('./lib/reguli-css.cjs');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('playwright');
const { Depozit } = require('./lib/depozit.cjs');
const PNG = require('./lib/png.cjs');
const A = require('./lib/arbori.cjs');

const SONDE = ['pixeli', 'stil', 'geometrie', 'cssom', 'text', 'linkuri', 'imagini', 'head', 'mutatii', 'retea', 'csp'];

function argumente() {
    const a = { felie: '1' };
    for (const x of process.argv.slice(2)) {
        const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
        if (!m) { throw new Error('argument necunoscut: ' + x); }
        a[m[1]] = m[2];
    }
    for (const k of ['referinta', 'candidat', 'config', 'iesire']) {
        if (!a[k]) { throw new Error('lipsește --' + k + '= (vezi capul fișierului)'); }
    }
    // Recenzia 2, R3-6: ieșirea în afara repo-ului, configurația niciodată din pachetul public.
    a.iesire = IESIRE_GARDA.verificaIesirea(a.iesire, 'ieșirea');
    a.config = IESIRE_GARDA.verificaConfig(a.config);
    return a;
}

function citesteMeta(dosar) {
    const rez = new Map();
    const umbla = (d) => {
        for (const n of fs.readdirSync(d, { withFileTypes: true })) {
            if (n.name.startsWith('_')) { continue; }
            const c = path.join(d, n.name);
            if (n.isDirectory()) { umbla(c); } else if (n.name === 'meta.json') {
                const m = JSON.parse(fs.readFileSync(c, 'utf8'));
                rez.set(m.captura, m);
            }
        }
    };
    umbla(dosar);
    return rez;
}

// Potrivirea unei liste de tipare („*”, nume exacte) pe o valoare.
const potriveste = (lista, v) => !lista || lista.includes('*') || lista.includes(v);
// o fereastră se poate scrie „1440x900” sau doar „1440”
const potrivesteFereastra = (lista, v) => !lista || lista.includes('*') || lista.includes(v) || lista.map(String).includes(String(v).split('x')[0]);
const intersectie = (z, w) => z[0] < w[0] + w[2] && z[0] + z[2] > w[0] && z[1] < w[1] + w[3] && z[1] + z[3] > w[1];
const LIMITA_CHEI = 5000;

// Înlocuirile declarate, aplicate unui text (valorile stilurilor, regulile CSS, textul head-ului, adresele).
function aplicaInlocuiri(text, inlocuiri, origini) {
    let x = String(text === null || text === undefined ? '' : text);
    for (const o of origini) {
        if (!o) { continue; }
        x = x.split(o).join('{{origine}}').split(o.replace(/\//g, '\\/')).join('{{origine}}');
    }
    // adresele proprii absolute și cele relative se compară la fel (DECIZIE §6.7 #2)
    x = x.replace(/\{\{origine\}\}(?=\\?\/)/g, '');
    for (const r of inlocuiri) { x = x.replace(r.re, r.cu); }
    return x;
}

function sub(cheie, prefixe) {
    for (const p of prefixe) {
        if (cheie === p || cheie.startsWith(p + '>')) { return true; }
    }
    return false;
}

class Comparatie {
    constructor(a) {
        this.a = a;
        const configText = fs.readFileSync(a.config);
        this.configSha = crypto.createHash('sha256').update(configText).digest('hex');
        this.config = JSON.parse(configText.toString('utf8'));
        this.ref = new Depozit(a.referinta);
        this.cand = new Depozit(a.candidat);
        this.metaRef = citesteMeta(a.referinta);
        this.metaCand = citesteMeta(a.candidat);
        const n = this.config.normalizari || {};
        this.inlocuiri = (n.inlocuiri || []).map((x) => ({ ...x, re: new RegExp(x.re, 'g') }));
        this.felie = String(a.felie);
        const fel = (this.config.felii || {})[this.felie] || {};
        this.sondeFelie = a.sonde ? a.sonde.split(',').filter((x) => SONDE.includes(x)) : (fel.sonde_p3 || SONDE);
        this.paginiFelie = fel.pagini || null;
        this.stariFelie = fel.stari || null;
        this.toleranta = { geometrie_px: 1, prag_pixeli: 0.1, ...(this.config.toleranta || {}) };
        fs.mkdirSync(path.join(a.iesire, 'dovezi'), { recursive: true });
    }

    // Măștile în aria capturii (pagina, profilul, fereastra, starea, felia); i = poziția în paritate.json.
    masti(m) {
        return (this.config.masti || []).map((x, i) => ({ ...x, i })).filter((x) => potriveste(x.pagini, m.pagina) && potriveste(x.profiluri, m.profil)
            && potrivesteFereastra(x.ferestre, m.fereastra) && potriveste(x.stari, m.stare) && (!x.felii || x.felii.map(String).includes(this.felie))
            && (!x.capturi || x.capturi.includes(m.captura)));
    }

    // Regiunile de rezolvat pe captură: cele cu nume (harta.regiuni) și cele scrise direct pe un ID (harta.id[ID].regiune).
    regiuni(m) {
        const h = this.config.harta || {};
        const l = [];
        const pune = (nume, r, pagini) => {
            if (!r || !potriveste(r.pagini || pagini, m.pagina)) { return; }
            l.push({ nume, selector: r.selector || null, dreptunghiuri: r.dreptunghiuri || (r.dreptunghi ? [r.dreptunghi] : []) });
        };
        for (const [nume, r] of Object.entries(h.regiuni || {})) { pune(nume, typeof r === 'string' ? { selector: r } : r, null); }
        for (const [id, x] of Object.entries(h.id || {})) {
            if (!x || !x.regiune || (typeof x.regiune === 'string' && (h.regiuni || {})[x.regiune])) { continue; }
            pune('id:' + id, typeof x.regiune === 'string' ? { selector: x.regiune } : x.regiune, x.pagini);
        }
        return l;
    }

    async ruleaza() {
        const browser = await chromium.launch({ proxy: { server: 'http://127.0.0.1:9' } });
        const ctx = await browser.newContext({ javaScriptEnabled: true });
        await ctx.route('**/*', (r) => r.abort());
        this.pagina = await ctx.newPage();
        await this.pagina.setContent('<!DOCTYPE html><title>comparatie</title>');
        let ids = [...this.metaRef.keys()].sort();
        if (this.a.doar) {
            const re = new RegExp('^' + this.a.doar.split('/').map((x) => (x === '*' ? '[^/]+' : x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))).join('/'));
            ids = ids.filter((i) => re.test(i));
        }
        ids = ids.filter((id) => {
            const m = this.metaRef.get(id);
            return potriveste(this.paginiFelie, m.pagina) && potriveste(this.stariFelie, m.stare);
        });
        // diagnostic (de exemplu zgomotul referinței față de o a doua captură a ei): doar capturile prezente pe ambele părți;
        // un raport de felie nu-l folosește, acolo o captură lipsă a candidatului pică.
        if (this.a['doar-comune']) { ids = ids.filter((id) => this.metaCand.has(id)); }
        const rezultate = [];
        for (const id of ids) {
            const r = await this.comparaCaptura(id).catch((e) => ({ captura: id, eroare: String(e && e.stack || e).split('\n').slice(0, 3).join(' | ') }));
            rezultate.push(r);
            const picate = r.sonde ? Object.entries(r.sonde).filter(([, s]) => s.stare === 'picat').map(([k, s]) => k + '(' + s.diferente + ')') : [];
            console.log((r.eroare ? 'EROARE ' : picate.length ? 'DIFERĂ ' : 'OK     ') + id + (picate.length ? ' ' + picate.join(' ') : '') + (r.eroare ? ' ' + r.eroare : ''));
        }
        await browser.close();
        const sumar = { capturi: rezultate.length, cuDiferente: rezultate.filter((r) => r.eroare || Object.values(r.sonde || {}).some((s) => s.stare === 'picat')).length };
        const iesire = {
            felie: this.felie, ora: new Date().toISOString(), referinta: path.resolve(this.a.referinta), candidat: path.resolve(this.a.candidat),
            config: path.resolve(this.a.config), config_sha256: this.configSha, sonde: this.sondeFelie, ...(this.a.instabil ? { instabil: this.a.instabil } : {}), sumar, rezultate,
        };
        fs.writeFileSync(path.join(this.a.iesire, 'comparatii.json'), JSON.stringify(iesire, null, 1));
        console.log('REZUMAT ' + JSON.stringify(sumar));
        return sumar.cuDiferente === 0 ? 0 : 1;
    }

    async comparaCaptura(id) {
        const mr = this.metaRef.get(id);
        const mc = this.metaCand.get(id);
        const rez = { captura: id, pagina: mr.pagina, profil: mr.profil, fereastra: mr.fereastra, stare: mr.stare, sonde: {} };
        const lipsa = (motiv) => { for (const s of this.sondeFelie) { rez.sonde[s] = { stare: 'picat', diferente: 1, detalii: [motiv] }; } return rez; };
        if (!mc) { return lipsa('captura lipsește la candidat'); }
        if (mr.eroare) { return lipsa('captura referinței are o eroare: ' + mr.eroare); }
        if (mc.eroare) { return lipsa('captura candidatului are o eroare: ' + mc.eroare); }
        const origini = [new URL(mr.url).origin, new URL(mc.url).origin];
        // o captură de baleiaj (scenarii/baleiaj.cjs): doar geometria, stilul și pixelii zonei, lățime cu lățime
        if (mr.scenarii && typeof mr.scenarii.baleiaj === 'string') {
            if (!mc.scenarii || typeof mc.scenarii.baleiaj !== 'string') {
                for (const s of ['pixeli', 'stil', 'geometrie']) { rez.sonde[s] = { stare: 'picat', diferente: 1, detalii: ['baleiajul lipsește la candidat'] }; }
                return rez;
            }
            const mb = this.masti(mr).filter((m) => m.baleiaj_chei);
            rez.masti = mb.map((m) => ({ nume: m.nume, i: m.i, selector: null, referinta: m.baleiaj_chei.length, candidat: 0, dreptunghiuri: 0 }));
            rez.sonde = this.sondaBaleiaj(this.ref.citesteJson(mr.scenarii.baleiaj), this.cand.citesteJson(mc.scenarii.baleiaj), (v) => aplicaInlocuiri(v, this.inlocuiri, origini), id, mb);
            return rez;
        }
        const masti = this.masti(mr);
        // cheile prinse de măști și de regiuni, în ambele DOM-uri (după scripturi)
        const domRef = mr.obiecte.dom ? this.ref.citesteText(mr.obiecte.dom, '.html') : '';
        const domCand = mc.obiecte.dom ? this.cand.citesteText(mc.obiecte.dom, '.html') : '';
        const regiuni = this.regiuni(mr);
        const selectori = {};
        masti.forEach((m, i) => { if (m.selector) { selectori['m' + i] = m.selector; } });
        regiuni.forEach((r, i) => { if (r.selector) { selectori['r' + i] = r.selector; } });
        const kRef = Object.keys(selectori).length ? await this.pagina.evaluate(A.rezolvaMasti, { html: domRef, selectori }) : {};
        const kCand = Object.keys(selectori).length ? await this.pagina.evaluate(A.rezolvaMasti, { html: domCand, selectori }) : {};
        const prinse = (n) => [...new Set([...(Array.isArray(kRef[n]) ? kRef[n] : []), ...(Array.isArray(kCand[n]) ? kCand[n] : [])])];
        const prefixe = (sonda) => {
            const l = [];
            masti.forEach((m, i) => {
                if (m.sonde && !m.sonde.includes(sonda)) { return; }
                if (m.atribute || m.clase) { return; } // măștile pe atribute lucrează doar în sonda mutatii
                for (const k of prinse('m' + i)) { l.push(k); }
            });
            return l;
        };
        const nr = (x) => (Array.isArray(x) ? x.length : (x === undefined ? 0 : x));
        rez.masti = masti.map((m, i) => ({
            nume: m.nume, i: m.i, selector: m.selector || null, referinta: nr(kRef['m' + i]), candidat: nr(kCand['m' + i]),
            dreptunghiuri: (m.dreptunghiuri || (m.dreptunghi ? [m.dreptunghi] : [])).length,
        }));
        const mRef = mr.obiecte.masurare ? this.ref.citesteJson(mr.obiecte.masurare) : null;
        const mCand = mc.obiecte.masurare ? this.cand.citesteJson(mc.obiecte.masurare) : null;
        if (regiuni.length) {
            // regiunea: cheile elementelor prinse (pe ambele părți) și cutiile lor măsurate (ale rădăcinilor prinse)
            rez.regiuni = {};
            regiuni.forEach((r, i) => {
                const chei = r.selector ? prinse('r' + i) : [];
                const set = new Set(chei);
                const cutii = [...r.dreptunghiuri];
                for (const m of [mRef, mCand]) {
                    if (!m || !set.size) { continue; }
                    for (const e of m.stiluri.elemente) { if (set.has(e.k) && e.g[2] > 0 && e.g[3] > 0) { cutii.push([e.g[0], e.g[1], e.g[2], e.g[3]]); } }
                }
                const err = [kRef['r' + i], kCand['r' + i]].find((x) => x && x.eroare);
                rez.regiuni[r.nume] = { selector: r.selector, chei, dreptunghiuri: cutii, ...(err ? { eroare: err.eroare } : {}) };
            });
        }
        const iRef = this.ref.citesteJson(mr.obiecte.incarcare);
        const iCand = this.cand.citesteJson(mc.obiecte.incarcare);
        const norm = (v) => aplicaInlocuiri(v, this.inlocuiri, origini);
        for (const s of this.sondeFelie) {
            const p = prefixe(s);
            try {
                rez.sonde[s] = await this['sonda_' + s]({ mr, mc, mRef, mCand, iRef, iCand, domRef, domCand, p, masti, norm, origini, id });
            } catch (e) {
                rez.sonde[s] = { stare: 'picat', diferente: 1, detalii: ['eroare a sondei: ' + String(e && e.message || e)] };
            }
        }
        return rez;
    }

    // Baleiajul antetului: pas cu pas (lățime cu lățime), geometria și stilul elementelor zonei și pixelii decupajului. Măștile
    // capturii (selectorii din configurația baleiajului) vin din captură (m:1 și dreptunghiurile pasului); măștile declarate cu
    // baleiaj_chei prind elementele după cheia lor din zonă (și elementele de sub ele), pe sondele lor.
    sondaBaleiaj(a, b, norm, id, masti) {
        const t = this.toleranta.geometrie_px;
        const g = [];
        const st = [];
        const px = [];
        const pasiZone = [];
        let pixeliDif = 0;
        const pb = new Map(b.pasi.map((p) => [p.w, p]));
        const prefixe = (sonda) => (masti || []).filter((m) => !m.sonde || m.sonde.includes(sonda)).flatMap((m) => m.baleiaj_chei);
        const pg = prefixe('geometrie');
        const ps = prefixe('stil');
        const pp = prefixe('pixeli');
        for (const pa of a.pasi) {
            const pc = pb.get(pa.w);
            if (!pc || pa.lipsa || pc.lipsa) { g.push({ w: pa.w, tip: 'pasul lipsește sau zona lipsește' }); continue; }
            const hc = new Map(pc.el.map((e) => [e.k, e]));
            for (const ea of pa.el) {
                if (ea.m) { continue; }
                const ec = hc.get(ea.k);
                if (!ec) { if (!sub(ea.k, pg)) { g.push({ w: pa.w, k: ea.k, tip: 'element doar în referință' }); } continue; }
                if (!sub(ea.k, pg) && ea.g.some((v, i) => Math.abs(v - ec.g[i]) > t)) { g.push({ w: pa.w, k: ea.k, referinta: ea.g, candidat: ec.g }); }
                if (!sub(ea.k, ps)) { ea.v.forEach((v, i) => { if (norm(v) !== norm(ec.v[i])) { st.push({ w: pa.w, k: ea.k, proprietate: a.proprietati[i], referinta: v, candidat: ec.v[i] }); } }); }
            }
            for (const ec of pc.el) { if (!ec.m && !sub(ec.k, pg) && !pa.el.some((e) => e.k === ec.k)) { g.push({ w: pa.w, k: ec.k, tip: 'element doar la candidat' }); } }
            if (pa.ecran !== pc.ecran) {
                // cutiile elementelor mascate (în coordonatele decupajului), pe ambele părți
                const cutii = [];
                for (const [p, d] of [[pa, pa.decupaj], [pc, pc.decupaj]]) {
                    for (const e of p.el) { if (sub(e.k, pp) && e.g[2] > 0 && e.g[3] > 0) { cutii.push([e.g[0] - ((d || [0])[0] || 0), e.g[1] - ((d || [0, 0])[1] || 0), e.g[2], e.g[3]]); } }
                }
                const cmp = PNG.compara(PNG.decodeaza(this.ref.citesteOcteti(pa.ecran, '.png')), PNG.decodeaza(this.cand.citesteOcteti(pc.ecran, '.png')),
                    { prag: this.toleranta.prag_pixeli, masti: [...(pa.masti || []), ...(pc.masti || []), ...cutii] });
                if (cmp.diferiti > 0) {
                    pixeliDif += cmp.diferiti;
                    px.push({ w: pa.w, pixeli: cmp.diferiti, zone: cmp.zone.slice(0, 5), marimi: cmp.marimi });
                    pasiZone.push({ w: pa.w, zone: cmp.zone, exacte: cmp.exacte, decupaj: pa.decupaj || null, zone_complete: cmp.zoneTotal === cmp.zone.length });
                }
            }
        }
        return {
            geometrie: this.rezultat(g, { pasi: a.pasi.length }), stil: this.rezultat(st, { pasi: a.pasi.length }),
            pixeli: { stare: pixeliDif === 0 ? 'trecut' : 'picat', diferente: pixeliDif, detalii: px.slice(0, 60), pasi: a.pasi.length, pasi_zone: pasiZone },
        };
    }

    // Rezultatul unei sonde: primele 60 de diferențe în detalii și lista completă a cheilor diferite (pentru regiunile punctelor
    // și pentru zgomot.cjs); chei_complete = false când lista e tăiată (raportul o socotește atunci întreagă în regiune).
    rezultat(dif, extra) {
        const total = extra && extra.total !== undefined ? extra.total : dif.length;
        const chei = [...new Set(dif.map((d) => d.k || d.parinte).filter((k) => typeof k === 'string'))];
        return {
            stare: total === 0 ? 'trecut' : 'picat', diferente: total, detalii: dif.slice(0, 60), ...(extra || {}),
            chei: chei.slice(0, LIMITA_CHEI), chei_complete: total === dif.length && chei.length <= LIMITA_CHEI && dif.every((d) => typeof (d.k || d.parinte) === 'string'),
        };
    }

    async sonda_pixeli({ mr, mc, mRef, mCand, p, masti, id }) {
        if (!mr.obiecte.ecran || !mc.obiecte.ecran) { return { stare: 'picat', diferente: 1, detalii: ['captura de ecran lipsește'] }; }
        // măștile: cutiile elementelor prinse, din geometria măsurată a fiecărei părți
        const dreptunghiuri = [];
        const adaugaCutii = (m) => {
            if (!m) { return; }
            for (const e of m.stiluri.elemente) {
                if (sub(e.k, p) && e.g[2] > 0 && e.g[3] > 0) { dreptunghiuri.push([e.g[0], e.g[1], e.g[2], e.g[3]]); }
            }
        };
        adaugaCutii(mRef);
        adaugaCutii(mCand);
        for (const m of masti) {
            if (!m.sonde || m.sonde.includes('pixeli')) {
                for (const d of (m.dreptunghiuri || (m.dreptunghi ? [m.dreptunghi] : []))) { dreptunghiuri.push(d); }
            }
        }
        if (mr.obiecte.ecran === mc.obiecte.ecran) {
            return { stare: 'trecut', diferente: 0, detalii: [], identic: true, zone: [], exacte: [], zone_complete: true, ...this.instabilCaptura(mr, mc, dreptunghiuri) };
        }
        const a = PNG.decodeaza(this.ref.citesteOcteti(mr.obiecte.ecran, '.png'));
        const b = PNG.decodeaza(this.cand.citesteOcteti(mc.obiecte.ecran, '.png'));
        const cmp = PNG.compara(a, b, { prag: this.toleranta.prag_pixeli, masti: dreptunghiuri, suprapunere: true });
        // zonele instabile ale referinței (două capturi de ecran ale aceleiași încărcări): diferențele din ele SE NUMĂRĂ (nicio
        // mască tăcută); sunt doar marcate, iar zgomot.cjs le propune ca măști declarate
        const instabile = (mr.instabil && mr.instabil.zone) || [];
        const inInstabil = (z) => instabile.some((w) => intersectie(z, w));
        const zone = cmp.zone;
        const diferiti = cmp.diferiti;
        let dovada = null;
        if (cmp.diferiti > 0) {
            const nume = id.replace(/[^a-z0-9-]+/gi, '_') + '.png';
            // o suprapunere decupată în jurul diferențelor (pagina întreagă ar fi prea mare)
            const x0 = Math.max(0, Math.min(...cmp.zone.map((z) => z[0])) - 64);
            const y0 = Math.max(0, Math.min(...cmp.zone.map((z) => z[1])) - 64);
            const x1 = Math.min(cmp.latime, Math.max(...cmp.zone.map((z) => z[0] + z[2])) + 64);
            const y1 = Math.min(cmp.inaltime, Math.min(Math.max(...cmp.zone.map((z) => z[1] + z[3])) + 64, y0 + 6000));
            const w = Math.max(1, x1 - x0);
            const h = Math.max(1, y1 - y0);
            const dec = Buffer.alloc(w * h * 4);
            for (let y = 0; y < h; y++) { cmp.suprapunere.date.copy(dec, y * w * 4, ((y0 + y) * cmp.latime + x0) * 4, ((y0 + y) * cmp.latime + x0 + w) * 4); }
            fs.writeFileSync(path.join(this.a.iesire, 'dovezi', nume), PNG.codeaza({ latime: w, inaltime: h, date: dec }));
            dovada = { suprapunere: 'dovezi/' + nume, decupaj: [x0, y0, w, h] };
        }
        const detalii = zone.slice(0, 40).map((z, i) => ({ zona: z.slice(0, 4), exact: cmp.exacte[i], pixeli: z[4], ...(inInstabil(z) ? { instabil_in_captura: true } : {}) }));
        if (cmp.marimi[0][0] !== cmp.marimi[1][0] || cmp.marimi[0][1] !== cmp.marimi[1][1]) { detalii.unshift({ marimi: cmp.marimi }); }
        return {
            stare: diferiti === 0 ? 'trecut' : 'picat', diferente: diferiti, detalii, mascati: cmp.mascati, netezite: cmp.netezite,
            zone: zone.map((z) => z.slice(0, 5)), exacte: cmp.exacte, zone_complete: cmp.zoneTotal === zone.length,
            in_zone_instabile: zone.filter(inInstabil).reduce((s, z) => s + z[4], 0), dovada, ecrane: { referinta: mr.obiecte.ecran, candidat: mc.obiecte.ecran },
            ...this.instabilCaptura(mr, mc, dreptunghiuri),
        };
    }

    // zgomot.cjs (--instabil=): zonele în care cele două capturi de ecran ale aceleiași încărcări diferă, în afara măștilor
    instabilCaptura(mr, mc, dreptunghiuri) {
        if (!this.a.instabil) { return {}; }
        const rez = {};
        const parti = this.a.instabil === 'ambele' ? [['referinta', this.ref, mr], ['candidat', this.cand, mc]] : [['referinta', this.ref, mr]];
        for (const [nume, dep, m] of parti) {
            if (!m.obiecte.ecran2 || m.obiecte.ecran2 === m.obiecte.ecran) { rez[nume] = { diferiti: 0, zone: [], exacte: [], zone_complete: true }; continue; }
            const c2 = PNG.compara(PNG.decodeaza(dep.citesteOcteti(m.obiecte.ecran, '.png')), PNG.decodeaza(dep.citesteOcteti(m.obiecte.ecran2, '.png')),
                { prag: this.toleranta.prag_pixeli, masti: dreptunghiuri });
            rez[nume] = { diferiti: c2.diferiti, zone: c2.zone, exacte: c2.exacte, zone_complete: c2.zoneTotal === c2.zone.length };
        }
        return { instabil_captura: rez };
    }

    sonda_stil({ mRef, mCand, p, norm, masti, id }) {
        if (!mRef || !mCand) { return { stare: 'picat', diferente: 1, detalii: ['stilurile lipsesc'] }; }
        const harta = (m) => {
            const h = new Map();
            for (const e of m.stiluri.elemente) { if (!sub(e.k, p)) { h.set(e.k, e); } }
            return h;
        };
        const hr = harta(mRef);
        const hc = harta(mCand);
        const dif = [];
        const prop = mRef.stiluri.proprietati;
        const ps = mRef.stiluri.pseudo;
        for (const [k, er] of hr) {
            const ec = hc.get(k);
            if (!ec) { dif.push({ k, tip: 'element vizibil doar în referință' }); continue; }
            for (let i = 0; i < prop.length; i++) {
                const vr = norm(mRef.stiluri.tabel[er.v[i]]);
                const ic = mCand.stiluri.proprietati.indexOf(prop[i]);
                const vc = ic < 0 ? '(lipsă)' : norm(mCand.stiluri.tabel[ec.v[ic]]);
                if (vr !== vc) { dif.push({ k, proprietate: prop[i], referinta: vr, candidat: vc }); }
            }
            for (const ps2 of ['b', 'a']) {
                if (!er[ps2] && !ec[ps2]) { continue; }
                if (!er[ps2] || !ec[ps2]) { dif.push({ k, proprietate: ps2 === 'b' ? '::before' : '::after', referinta: er[ps2] ? 'există' : 'nu', candidat: ec[ps2] ? 'există' : 'nu' }); continue; }
                for (let i = 0; i < ps.length; i++) {
                    const vr = norm(mRef.stiluri.tabel[er[ps2][i]]);
                    const vc = norm(mCand.stiluri.tabel[ec[ps2][mCand.stiluri.pseudo.indexOf(ps[i])]]);
                    if (vr !== vc) { dif.push({ k, proprietate: (ps2 === 'b' ? '::before ' : '::after ') + ps[i], referinta: vr, candidat: vc }); }
                }
            }
        }
        for (const k of hc.keys()) { if (!hr.has(k)) { dif.push({ k, tip: 'element vizibil doar la candidat' }); } }
        // măștile cu stări observate (zgomot.cjs): elementul mascat trebuie să cadă pe una dintre ele; se spune pe care
        const stariMasti = [];
        for (const m of masti || []) {
            const so = m.stari_observate && m.stari_observate[id];
            if (!so || (m.sonde && !m.sonde.includes('stil'))) { continue; }
            for (const [k, x] of Object.entries(so)) {
                const ec = mCand.stiluri.elemente.find((e) => e.k === k);
                const val = ec ? x.proprietati.map((pr) => { const ix = mCand.stiluri.proprietati.indexOf(pr); return ix < 0 ? null : norm(mCand.stiluri.tabel[ec.v[ix]]); }) : null;
                const idx = val ? x.stari.findIndex((st) => JSON.stringify(st.valori.map((v) => norm(v))) === JSON.stringify(val)) : -1;
                stariMasti.push({ masca: m.nume, i: m.i, k, stare: idx, incarcari: idx >= 0 ? x.stari[idx].incarcari : null, candidat: val });
                if (idx < 0) { dif.push({ k, tip: 'în afara stărilor observate ale măștii „' + m.nume + '”', proprietati: x.proprietati, candidat: val, stari: x.stari.map((st) => st.valori) }); }
            }
        }
        return this.rezultat(dif, { elemente: hr.size, ...(stariMasti.length ? { stari_masti: stariMasti } : {}) });
    }

    sonda_geometrie({ mRef, mCand, p }) {
        if (!mRef || !mCand) { return { stare: 'picat', diferente: 1, detalii: ['geometria lipsește'] }; }
        const t = this.toleranta.geometrie_px;
        const hc = new Map(mCand.stiluri.elemente.map((e) => [e.k, e]));
        const dif = [];
        for (const er of mRef.stiluri.elemente) {
            if (sub(er.k, p)) { continue; }
            const ec = hc.get(er.k);
            if (!ec) { continue; } // lipsa elementului o raportează sonda stilului și sonda mutațiilor
            const d = er.g.map((v, i) => Math.abs(v - ec.g[i]));
            if (d.some((x) => x > t)) { dif.push({ k: er.k, referinta: er.g, candidat: ec.g }); }
        }
        if (mRef.doc.latime !== mCand.doc.latime || mRef.doc.inaltime !== mCand.doc.inaltime) {
            dif.unshift({ k: '(documentul)', referinta: [mRef.doc.latime, mRef.doc.inaltime], candidat: [mCand.doc.latime, mCand.doc.inaltime] });
        }
        return this.rezultat(dif);
    }

    sonda_cssom({ mr, mc, norm }) {
        if (!mr.obiecte.cssom || !mc.obiecte.cssom) { return { stare: 'picat', diferente: 1, detalii: ['CSSOM-ul lipsește'] }; }
        const nc = (this.config.normalizari || {}).cssom || {};
        const ignora = (nc.ignora_foi || []).map((x) => ({ href: x.href_re ? new RegExp(x.href_re) : null, nod: x.nod_re ? new RegExp(x.nod_re) : null }));
        const pastreaza = (f) => !ignora.some((x) => (x.href && f.href && x.href.test(f.href)) || (x.nod && x.nod.test(f.nod)));
        const fr = this.ref.citesteJson(mr.obiecte.cssom).filter(pastreaza);
        const fc = this.cand.citesteJson(mc.obiecte.cssom).filter(pastreaza);
        const dif = [];
        const n = Math.max(fr.length, fc.length);
        for (let i = 0; i < n; i++) {
            const a = fr[i];
            const b = fc[i];
            if (!a || !b) { dif.push({ foaia: i, referinta: a ? a.nod + ' ' + (a.href || '') : null, candidat: b ? b.nod + ' ' + (b.href || '') : null }); continue; }
            const ida = norm(a.nod + '|' + (a.href || '') + '|' + a.media + '|' + a.dezactivata);
            const idb = norm(b.nod + '|' + (b.href || '') + '|' + b.media + '|' + b.dezactivata);
            if (ida !== idb) { dif.push({ foaia: i, referinta: ida, candidat: idb }); }
            if (a.inaccesibila || b.inaccesibila) {
                if (!!a.inaccesibila !== !!b.inaccesibila) { dif.push({ foaia: i, nod: a.nod, referinta: a.inaccesibila ? 'inaccesibilă' : 'accesibilă', candidat: b.inaccesibila ? 'inaccesibilă' : 'accesibilă' }); }
                continue;
            }
            const ra = a.reguli.map((r) => norm((r.c || []).join(' » ') + ' ' + r.r));
            const rb = b.reguli.map((r) => norm((r.c || []).join(' » ') + ' ' + r.r));
            if (ra.length !== rb.length || ra.some((x, j) => x !== rb[j])) {
                let j = 0;
                while (j < Math.min(ra.length, rb.length) && ra[j] === rb[j]) { j++; }
                dif.push({ foaia: i, nod: a.nod, reguli: [ra.length, rb.length], prima_diferenta: j, referinta: (ra[j] || '').slice(0, 300), candidat: (rb[j] || '').slice(0, 300) });
            }
        }
        return this.rezultat(dif, { foi: fr.length });
    }

    sonda_text({ iRef, iCand, p }) {
        const dif = [];
        // textul vizibil al paginii, cu textul elementelor mascate înlocuit (fiecare parte cu al ei)
        // (o singură înlocuire pe text mascat, cele lungi întâi, cu un marcaj fără litere: înlocuirile nu se pot hrăni una pe alta)
        const faraMascate = (i) => {
            let t = i.textPagina || '';
            const mascate = (i.text || []).filter((x) => sub(x.k, p)).map((x) => (x.i !== undefined ? x.i : x.t)).filter((v) => v && v.trim().length >= 2)
                .sort((u, v) => v.length - u.length);
            for (const v of mascate) {
                const j = t.indexOf(v);
                if (j >= 0) { t = t.slice(0, j) + '\u0000' + t.slice(j + v.length); }
            }
            return t;
        };
        const a = faraMascate(iRef);
        const b = faraMascate(iCand);
        if (a !== b) {
            let j = 0;
            while (j < Math.min(a.length, b.length) && a[j] === b[j]) { j++; }
            dif.push({ k: '(innerText al paginii)', la: j, referinta: JSON.stringify(a.slice(Math.max(0, j - 40), j + 80)), candidat: JSON.stringify(b.slice(Math.max(0, j - 40), j + 80)) });
        }
        const hc = new Map((iCand.text || []).map((x) => [x.k, x]));
        const hr = new Map((iRef.text || []).map((x) => [x.k, x]));
        for (const [k, a] of hr) {
            if (sub(k, p)) { continue; }
            const b = hc.get(k);
            if (!b) { dif.push({ k, referinta: a.t || a.i, candidat: null }); continue; }
            if ((a.t || '') !== (b.t || '') || (a.i || '') !== (b.i || '')) { dif.push({ k, referinta: a.t !== undefined ? a.t : a.i, candidat: b.t !== undefined ? b.t : b.i }); }
        }
        for (const [k, b] of hc) { if (!hr.has(k) && !sub(k, p)) { dif.push({ k, referinta: null, candidat: b.t || b.i }); } }
        return this.rezultat(dif);
    }

    sonda_linkuri({ iRef, iCand, p, norm }) {
        const cheie = (l) => l.k;
        const hc = new Map(iCand.linkuri.map((l) => [cheie(l), l]));
        const dif = [];
        for (const a of iRef.linkuri) {
            if (sub(a.k, p)) { continue; }
            const b = hc.get(cheie(a));
            if (!b) { dif.push({ k: a.k, referinta: a.href, candidat: null }); continue; }
            for (const c of ['href', 'target', 'rel', 'text']) {
                const va = c === 'href' ? norm(a[c]) : a[c];
                const vb = c === 'href' ? norm(b[c]) : b[c];
                if (va !== vb) { dif.push({ k: a.k, camp: c, referinta: va, candidat: vb }); }
            }
        }
        const kr = new Set(iRef.linkuri.map(cheie));
        for (const b of iCand.linkuri) { if (!kr.has(cheie(b)) && !sub(b.k, p)) { dif.push({ k: b.k, referinta: null, candidat: b.href }); } }
        return this.rezultat(dif);
    }

    sonda_imagini({ mr, mc, mRef, mCand, p, norm }) {
        if (!mRef || !mCand) { return { stare: 'picat', diferente: 1, detalii: ['imaginile lipsesc'] }; }
        const shaRef = this.shaPrimite(this.ref, mr);
        const shaCand = this.shaPrimite(this.cand, mc);
        const hc = new Map(mCand.imagini.map((i) => [i.k, i]));
        const dif = [];
        for (const a of mRef.imagini) {
            if (sub(a.k, p)) { continue; }
            const b = hc.get(a.k);
            if (!b) { dif.push({ k: a.k, referinta: a.currentSrc, candidat: null }); continue; }
            for (const c of ['currentSrc', 'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding']) {
                const va = norm(a[c]);
                const vb = norm(b[c]);
                if (va !== vb) { dif.push({ k: a.k, camp: c, referinta: va, candidat: vb }); }
            }
            if (a.natural[0] !== b.natural[0] || a.natural[1] !== b.natural[1]) { dif.push({ k: a.k, camp: 'natural', referinta: a.natural, candidat: b.natural }); }
            const sa = shaRef.get(norm(a.currentSrc));
            const sb = shaCand.get(norm(b.currentSrc));
            if (sa && sb && sa !== sb) { dif.push({ k: a.k, camp: 'sha256', referinta: sa, candidat: sb }); }
        }
        const kr = new Set(mRef.imagini.map((i) => i.k));
        for (const b of mCand.imagini) { if (!kr.has(b.k) && !sub(b.k, p)) { dif.push({ k: b.k, referinta: null, candidat: b.currentSrc }); } }
        return this.rezultat(dif);
    }

    shaPrimite(dep, m) {
        const h = new Map();
        if (!m.obiecte.retea) { return h; }
        const origini = [new URL(m.url).origin];
        for (const e of dep.citesteJson(m.obiecte.retea)) {
            if (e.sha256 && (e.actiune === 'cache' || e.actiune === 'descarcat')) { h.set(aplicaInlocuiri(e.url, this.inlocuiri, origini), e.sha256); }
        }
        return h;
    }

    sonda_head({ iRef, iCand, norm }) {
        const nh = (this.config.normalizari || {}).head || {};
        const ignora = (nh.ignora || []).map((x) => ({ tag: x.tag || null, atribut: x.atribut || null, re: x.re ? new RegExp(x.re) : null }));
        const ignorat = (el) => ignora.some((x) => (!x.tag || x.tag === el.tag) && (!x.atribut || (el.atribute[x.atribut] !== undefined && (!x.re || x.re.test(el.atribute[x.atribut])))) && (x.atribut || !x.re || x.re.test(JSON.stringify(el))));
        // Stilurile declarate (opt-in, normalizari.head.stiluri_pe_reguli: id-urile lor) se compară pe reguli, nu pe text:
        // selector → mulțimea declarațiilor (lib/reguli-css.cjs). Restul head-ului, pe text.
        const peReguli = new Set(nh.stiluri_pe_reguli || []);
        const forma = (el) => {
            if (el.tag === 'style' && el.atribute && peReguli.has(el.atribute.id)) {
                const f = REGULI_CSS.formaReguli(el.text || '');
                if (f !== null) {
                    return norm(JSON.stringify({ tag: 'style', at: { id: el.atribute.id }, reguli: f, comparat: 'pe reguli' }));
                }
            }
            const at = {};
            for (const [k, v] of Object.entries(el.atribute)) {
                if ((nh.ignora_atribute || []).some((r) => new RegExp(r).test(k))) { continue; }
                at[k] = norm(v);
            }
            return norm(JSON.stringify({ tag: el.tag, at, text: el.json !== undefined ? JSON.stringify(el.json) : (el.text || '') }));
        };
        const a = iRef.head.filter((el) => !ignorat(el)).map(forma);
        const b = iCand.head.filter((el) => !ignorat(el)).map(forma);
        const dif = [];
        const ha = norm(JSON.stringify(iRef.doc.html));
        const hb = norm(JSON.stringify(iCand.doc.html));
        if (ha !== hb) { dif.push({ k: '<html>', referinta: ha, candidat: hb }); }
        const n = Math.max(a.length, b.length);
        for (let i = 0; i < n; i++) {
            if (a[i] !== b[i]) { dif.push({ pozitia: i, referinta: (a[i] || null) && a[i].slice(0, 400), candidat: (b[i] || null) && b[i].slice(0, 400) }); }
        }
        return this.rezultat(dif, { elemente: a.length, ...(peReguli.size ? { comparate_pe_reguli: [...peReguli].map((id) => id + ': comparat pe reguli') } : {}) });
    }

    async sonda_mutatii({ domRef, domCand, mr, mc, masti, p }) {
        if (!domRef || !domCand) { return { stare: 'picat', diferente: 1, detalii: ['DOM-ul după scripturi lipsește'] }; }
        const nd = (this.config.normalizari || {}).dom || {};
        const sel = masti.filter((m) => m.selector && (!m.sonde || m.sonde.includes('mutatii'))).map((m) => (m.atribute || m.clase ? { selector: m.selector, atribute: m.atribute || [], clase: m.clase || [] } : m.selector));
        const r = await this.pagina.evaluate(A.comparaDom, {
            referinta: domRef, candidat: domCand, urlReferinta: mr.urlFinal || mr.url, urlCandidat: mc.urlFinal || mc.url,
            origineReferinta: new URL(mr.url).origin, origineCandidat: new URL(mc.url).origin,
            normalizari: nd, inlocuiri: (this.config.normalizari || {}).inlocuiri || [], masti: sel,
        });
        // unitățile (felul, cheia, părintele, atributul, clasele) tuturor diferențelor, pentru zgomot.cjs
        const unitati = r.diferente.map((d) => ({ tip: d.tip, k: d.k, ...(d.parinte ? { parinte: d.parinte } : {}), ...(d.atribut ? { atribut: d.atribut } : {}),
            ...(d.tip === 'clase' ? { clase: [...(d.referinta_plus || []), ...(d.candidat_plus || [])] } : {}) }));
        return this.rezultat(r.diferente, { total: r.total, unitati });
    }

    sonda_retea({ mr, mc }) {
        const nr = (this.config.normalizari || {}).retea || {};
        const ignora = (nr.ignora_cai || []).map((x) => new RegExp(x));
        const dif = [];
        const ec = mc.obiecte.retea ? this.cand.citesteJson(mc.obiecte.retea) : [];
        const er = mr.obiecte.retea ? this.ref.citesteJson(mr.obiecte.retea) : [];
        const doarRef = (nr.doar_referinta || []).map((x) => new RegExp(x));
        const doarCand = (nr.doar_candidat || []).map((x) => new RegExp(x));
        for (const e of ec) {
            if (e.acord === 'inainte' && (e.clasa === 'terta' || e.clasa === 'tracker' || e.clasa === 'fonturi')) {
                dif.push({ tip: 'cerere terță înainte de acord', url: e.url, actiune: e.actiune, motiv: e.motiv || '' });
            }
            if (e.clasa === 'tracker' && e.actiune !== 'proba') {
                dif.push({ tip: 'tracker fără fișier de probă', url: e.url });
            }
            if (e.clasa === 'propriu' && e.status >= 400 && !ignora.some((re) => re.test(new URL(e.url).pathname))) {
                dif.push({ tip: 'răspuns ' + e.status, url: e.url });
            }
            if (e.clasa === 'propriu' && e.actiune === 'eroare') { dif.push({ tip: 'cerere fără răspuns', url: e.url, motiv: e.motiv }); }
        }
        // mulțimea adreselor proprii cerute (fără ordine): aceeași pe ambele părți, după normalizări
        const cale = (e) => { const u = new URL(e.url); return u.pathname + u.search; };
        const proprii = (lista) => new Set(lista.filter((e) => e.clasa === 'propriu' && e.metoda === 'GET').map(cale).filter((c) => !ignora.some((re) => re.test(c))));
        const sr = proprii(er);
        const sc = proprii(ec);
        // declarate: adresele pe care doar referința le cere (scripturile furnizorilor, scoase) și doar candidatul (runtime-ul nostru)
        for (const c of sr) { if (!sc.has(c) && !doarRef.some((re) => re.test(c))) { dif.push({ tip: 'cerută doar de referință', cale: c }); } }
        for (const c of sc) { if (!sr.has(c) && !doarCand.some((re) => re.test(c))) { dif.push({ tip: 'cerută doar de candidat', cale: c }); } }
        return this.rezultat(dif, { cereri: [er.length, ec.length] });
    }

    sonda_csp({ mc }) {
        const exceptii = (this.config.csp_exceptii || []).map((x) => ({ ...x, uri: x.uri_re ? new RegExp(x.uri_re) : null }));
        const dif = [];
        const toate = [...(mc.csp || []), ...((mc.rapoarteCsp || []).map((r) => ({ raport: true, ...r })))];
        for (const v of toate) {
            const ok = exceptii.some((x) => (!x.directiva || x.directiva === v.efectiva || x.directiva === v.directiva) && (!x.uri || x.uri.test(v.uri || '')));
            if (!ok) { dif.push(v); }
        }
        return this.rezultat(dif);
    }
}

if (require.main === module) {
    (async () => {
        const a = argumente();
        const c = new Comparatie(a);
        process.exitCode = await c.ruleaza();
    })().catch((e) => { console.error('EROARE ' + (e && e.stack || e)); process.exit(2); });
}

module.exports = { Comparatie, SONDE, aplicaInlocuiri };
