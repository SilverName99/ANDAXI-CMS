// Raportul de paritate al unei felii (DECIZIE §6.4, §6.9; C2 §10): fiecare punct din fișierul de verificare (VC-ID) primește
// starea lui, cu dovada fiecărei sonde:
//   - sondele P3-* vin din comparația harness-ului (compara.cjs), pe paginile, lățimile și stările punctului (harta din
//     paritate.json: zona → pagini, plus excepțiile pe ID: pagini, stări, lățimi, regiunea, sondele);
//   - regiunea unui ID (harta.id[ID].regiune: numele unei regiuni din harta.regiuni, un selector sau {selector |
//     dreptunghiuri}): sondele cu chei de element (stil, geometrie, text, linkuri, imagini, mutatii) și pixelii numără doar
//     diferențele din regiune; head, cssom, retea, csp rămân pe pagină; o regiune care nu prinde nimic pe nicio captură lasă
//     ID-ul „fără dovadă”; o listă de chei tăiată se socotește întreagă în regiune;
//   - sondele unui ID se pot înlocui din config (harta.id[ID].sonde, cu motiv_sonde), de exemplu după o decizie: raportul
//     arată sondele inițiale și motivul;
//   - P1-*, P2-*, T-* vin din dovezile date (--dovezi=, JSON: [{id|"*", sonda, stare, dovada}]); o sondă cu calificativ
//     („P2-http(…)”) cere o dovadă cu același nume, sau una fără calificativ când ID-ul n-are alta cu aceeași bază;
//     M-declarat și M-decizie din ce-se-schimba.md (--declaratii=, ID-ul scris acolo) sau din dovezi; P4-dispozitiv și
//     M-trimitere-test sunt „amânat (pasul 15)”;
//   - **un ID fără dovadă face raportul să pice**; un ID dintr-o felie de mai târziu e „în așteptare”, nu picat;
//   - un ID picat e „declarat” doar dacă punctul are o diferență declarată și ID-ul e scris în ce-se-schimba.md;
//   - **o diferență pe care n-o acoperă niciun ID** (în afara regiunilor tuturor ID-urilor capturii, sau pe o sondă fără ID)
//     face raportul să pice („neatribuite”): regiunile nu pot ascunde nimic;
//   - lista măștilor declarate (fiecare cu motivul, aria, sondele, dacă e propusă automat, de câte ori a prins ceva) și a
//     normalizărilor e în raport; o mască fără nicio captură prinsă e marcată „neaplicată”; la o mască cu stări observate
//     (zgomot.cjs, sonda stil) raportul spune pe ce stare a căzut candidatul, iar o stare nouă e o diferență. Config-ul trebuie să fie cel al
//     comparației (sha256).
// Raportul (HTML și JSON, privat) poartă commit-ul versiunii, amprenta bazei, sha256 manifestului și sha256 al lui însuși
// (calculat pe JSON-ul compact cu câmpul „sha256” gol; `verifica` îl recalculează). E `--paritate-ok=` al pasului 11.
//
//   node raport.cjs scrie --comparatii=<comparatii.json> --verificare=<verificare-per-id.json> --config=<paritate.json>
//       --iesire=<dosar> [--felie=1] [--dovezi=<a.json>,<b.json>] [--declaratii=<ce-se-schimba.md>] [--commit=<sha>]
//       [--amprenta-baza=<text>] [--manifest=<fișier>]
//   node raport.cjs verifica <raport.json> [--sha256=<așteptat>]
// Cod: 0 = verdict „trecut” (la verifica: sha256 corect); 1 = „picat” (sau sha256 greșit); 2 = eroare de folosire.

'use strict';

const IESIRE_GARDA = require('./lib/iesire.cjs');
IESIRE_GARDA.pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo, prin nicio funcție
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const sha256 = (b) => crypto.createHash('sha256').update(b).digest('hex');

const P3 = {
    'P3-pixeli': 'pixeli', 'P3-stil': 'stil', 'P3-geometrie': 'geometrie', 'P3-cssom': 'cssom', 'P3-text': 'text',
    'P3-linkuri': 'linkuri', 'P3-imagini': 'imagini', 'P3-head': 'head', 'P3-mutatii': 'mutatii', 'P3-retea': 'retea',
    'P3-csp': 'csp', 'P3-scenariu': 'scenariu',
};
const AMANATE = ['P4-dispozitiv', 'M-trimitere-test'];
// sondele fără chei de element: o regiune nu le împarte
const FARA_CHEI = new Set(['head', 'cssom', 'retea', 'csp']);

function argumente() {
    const [mod, ...rest] = process.argv.slice(2);
    const a = { mod, felie: '1', pozitionale: [] };
    for (const x of rest) {
        const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
        if (m) { a[m[1]] = m[2]; } else { a.pozitionale.push(x); }
    }
    // Recenzia 2, R3-6: ieșirea în afara repo-ului, configurația niciodată din pachetul public.
    a.iesire = IESIRE_GARDA.verificaIesirea(a.iesire, 'ieșirea');
    a.config = IESIRE_GARDA.verificaConfig(a.config);
    return a;
}

// sha256 al raportului: JSON-ul compact, cu câmpul sha256 gol.
function semnatura(raport) {
    return sha256(JSON.stringify({ ...raport, sha256: '' }));
}

function latimiPunct(text) {
    if (!text || text === '-') { return null; }
    const l = [];
    for (const m of String(text).matchAll(/\b(\d{3,4})(?:x(\d{3,4}))?\b/g)) { l.push(Number(m[1])); }
    return l.length ? new Set(l) : null;
}

function scrie(a) {
    for (const k of ['comparatii', 'verificare', 'config', 'iesire']) {
        if (!a[k]) { throw new Error('lipsește --' + k + '='); }
    }
    const comparatii = JSON.parse(fs.readFileSync(a.comparatii, 'utf8'));
    const verificareText = fs.readFileSync(a.verificare);
    const verificare = JSON.parse(verificareText.toString('utf8'));
    const configText = fs.readFileSync(a.config);
    const config = JSON.parse(configText.toString('utf8'));
    if (comparatii.config_sha256 && comparatii.config_sha256 !== sha256(configText)) {
        throw new Error('config-ul dat (' + a.config + ') nu e cel al comparației (sha256 ' + comparatii.config_sha256 + '): măștile din raport n-ar fi cele aplicate');
    }
    const felie = Number(a.felie);
    const harta = config.harta || {};
    const zone = harta.zone || {};
    const peId = harta.id || {};
    const regiuniNumite = harta.regiuni || {};
    const dovezi = [];
    for (const f of (a.dovezi ? a.dovezi.split(',') : [])) {
        const d = JSON.parse(fs.readFileSync(f, 'utf8'));
        for (const x of (Array.isArray(d) ? d : d.dovezi || [])) { dovezi.push({ ...x, fisier: path.basename(f) }); }
    }
    const declaratiiText = a.declaratii ? fs.readFileSync(a.declaratii, 'utf8') : '';
    const declarat = (id) => declaratiiText !== '' && new RegExp('(^|[^A-Z0-9-])' + id.replace(/[-]/g, '\\-') + '([^0-9]|$)').test(declaratiiText);
    const puncte = verificare.puncte || verificare;
    const rezultate = comparatii.rezultate || [];

    // dovada unei sonde: cu același nume; una fără calificativ doar dacă ID-ul n-are mai multe sonde cu aceeași bază
    const dovadaExterna = (id, sonda, toate) => {
        const baza = sonda.split('(')[0];
        const unica = toate.filter((x) => x.split('(')[0] === baza).length === 1;
        const l = dovezi.filter((d) => (d.id === id || d.id === '*') && (d.sonda === sonda || (d.sonda === baza && (unica || sonda === baza))));
        if (!l.length) { return null; }
        const picate = l.filter((d) => d.stare !== 'trecut');
        return { stare: picate.length ? 'picat' : 'trecut', dovada: l.map((d) => d.fisier + ': ' + (d.dovada || d.stare)).slice(0, 5) };
    };

    // aria unui ID: paginile, stările, lățimile, regiunea, sondele (după excepțiile din harta.id)
    const aria = (p) => {
        const h = peId[p.id] || {};
        const pagini = h.pagini || zone[p.zona] || null;
        const stari = h.stari || ['incarcare'];
        const latimi = h.latimi ? new Set(h.latimi) : latimiPunct(p.latimi);
        let regiune = null;
        if (h.regiune) { regiune = typeof h.regiune === 'string' && regiuniNumite[h.regiune] ? h.regiune : 'id:' + p.id; }
        const sonde = Array.isArray(h.sonde) ? h.sonde : p.sonde;
        const capturi = pagini ? rezultate.filter((r) => (pagini.includes('*') || pagini.includes(r.pagina)) && stari.includes(r.stare)
            && (!latimi || latimi.has(Number(String(r.fereastra).split('x')[0])))) : [];
        return { h, pagini, stari, regiune, sonde, capturi };
    };

    const evalueaza = (p) => {
        const { h, pagini, regiune, sonde: listaSonde, capturi } = aria(p);
        const sonde = {};
        for (const s of listaSonde) {
            const baza = s.split('(')[0];
            if (AMANATE.includes(baza)) { sonde[s] = { stare: 'amânat', dovada: ['pasul 15 (P4 și trimiterea de probă)'] }; continue; }
            if (P3[baza]) {
                const nume = P3[baza];
                if (!capturi.length) { sonde[s] = { stare: 'fără dovadă', dovada: ['nicio captură comparată pentru ' + (pagini ? pagini.join(',') : 'zona ' + p.zona)] }; continue; }
                const picate = [];
                let vazute = 0;
                let prinseRegiune = 0;
                for (const r of capturi) {
                    if (r.eroare) { picate.push({ captura: r.captura, eroare: r.eroare }); vazute++; continue; }
                    const reg = regiune && r.regiuni ? r.regiuni[regiune] : null;
                    if (reg && reg.eroare) { picate.push({ captura: r.captura, eroare: 'regiunea ' + regiune + ': ' + reg.eroare }); vazute++; continue; }
                    if (reg && ((reg.chei || []).length || (reg.dreptunghiuri || []).length)) { prinseRegiune++; }
                    if (nume === 'scenariu') {
                        vazute++;
                        const rele = Object.entries(r.sonde || {}).filter(([, x]) => x.stare === 'picat').map(([k, x]) => [k, reg ? inRegiune(x, k, reg) : x.diferente]).filter(([, n]) => n > 0);
                        if (rele.length) { picate.push({ captura: r.captura, sonde: rele.map(([k, n]) => k + ':' + n) }); }
                        continue;
                    }
                    const x = (r.sonde || {})[nume];
                    if (!x) { continue; }
                    vazute++;
                    let dif = x.stare === 'picat' ? x.diferente : 0;
                    if (dif && reg) { dif = inRegiune(x, nume, reg); }
                    if (dif) { picate.push({ captura: r.captura, diferente: dif, dovada: x.dovada || null, primele: (x.detalii || []).slice(0, 3) }); }
                }
                if (!vazute) { sonde[s] = { stare: 'fără dovadă', dovada: ['sonda ' + nume + ' n-a rulat (nu e în felia ' + felie + ')'] }; continue; }
                if (regiune && !prinseRegiune && !picate.length) { sonde[s] = { stare: 'fără dovadă', dovada: ['regiunea ' + regiune + ' nu prinde nimic pe ' + vazute + ' capturi'] }; continue; }
                const peRegiune = regiune ? { regiune, capturi_cu_regiune: prinseRegiune } : {};
                sonde[s] = picate.length ? { stare: 'picat', capturi: vazute, ...peRegiune, picate: picate.slice(0, 20), nrPicate: picate.length } : { stare: 'trecut', capturi: vazute, ...peRegiune };
                continue;
            }
            if (baza === 'M-declarat' || baza === 'M-decizie') {
                const e = dovadaExterna(p.id, s, listaSonde);
                if (e) { sonde[s] = e; continue; }
                sonde[s] = declarat(p.id) ? { stare: 'trecut', dovada: ['ce-se-schimba.md'] } : { stare: 'fără dovadă', dovada: ['ID-ul nu e în ce-se-schimba.md și nicio dovadă dată'] };
                continue;
            }
            const e = dovadaExterna(p.id, s, listaSonde);
            sonde[s] = e || { stare: 'fără dovadă', dovada: ['nicio dovadă ' + s + ' dată (--dovezi=)'] };
        }
        const stariS = Object.values(sonde).map((x) => x.stare);
        let stare = 'trecut';
        if (stariS.includes('picat')) {
            stare = (p.diferenta_declarata && declarat(p.id)) ? 'declarat' : 'picat';
        } else if (stariS.includes('fără dovadă')) {
            stare = 'fără dovadă';
        }
        const extra = {};
        if (regiune) { extra.regiune = { nume: regiune, selector: typeof h.regiune === 'string' && !regiuniNumite[h.regiune] ? h.regiune : (regiuniNumite[h.regiune] || h.regiune) }; }
        if (Array.isArray(h.sonde)) { extra.sonde_inlocuite = { initial: p.sonde, motiv: h.motiv_sonde || null }; }
        return { stare, sonde, capturi: capturi.length, ...extra };
    };

    const lista = [];
    for (const p of puncte) {
        if (Number(p.felie) > felie) {
            lista.push({ id: p.id, felie: p.felie, clasa: p.clasa, critic: !!p.critic, zona: p.zona, stare: 'în așteptare', sonde: {} });
            continue;
        }
        const e = evalueaza(p);
        lista.push({ id: p.id, felie: p.felie, clasa: p.clasa, critic: !!p.critic, zona: p.zona, ...e });
    }

    // diferențele neatribuite: pe fiecare captură și sondă, ce nu cade în aria niciunui ID al feliei (un ID fără regiune
    // acoperă toată captura; P3-scenariu acoperă toate sondele capturii)
    const acoperire = new Map();
    for (const p of puncte) {
        if (Number(p.felie) > felie) { continue; }
        const ar = aria(p);
        for (const s of ar.sonde) {
            const baza = s.split('(')[0];
            if (!P3[baza]) { continue; }
            for (const r of ar.capturi) {
                const sondeAcoperite = P3[baza] === 'scenariu' ? Object.keys(r.sonde || {}) : [P3[baza]];
                for (const sn of sondeAcoperite) {
                    const c = r.captura + '|' + sn;
                    if (!acoperire.has(c)) { acoperire.set(c, { pagina: false, regiuni: new Set(), id: [] }); }
                    const x = acoperire.get(c);
                    // o captură pe care regiunea nu s-a rezolvat (baleiajul antetului) e acoperită întreagă, ca în evalueaza
                    if (ar.regiune && r.regiuni && r.regiuni[ar.regiune]) { x.regiuni.add(ar.regiune); } else { x.pagina = true; }
                    if (x.id.length < 5) { x.id.push(p.id); }
                }
            }
        }
    }
    const neatribuite = [];
    for (const r of rezultate) {
        if (r.eroare) { continue; }
        for (const [sn, x] of Object.entries(r.sonde || {})) {
            if (x.stare !== 'picat') { continue; }
            const ac = acoperire.get(r.captura + '|' + sn);
            if (ac && ac.pagina) { continue; }
            const regs = ac ? [...ac.regiuni].map((n) => (r.regiuni || {})[n]).filter(Boolean) : [];
            const rest = regs.length ? inAfaraRegiunilor(x, sn, regs) : x.diferente;
            if (rest > 0) { neatribuite.push({ captura: r.captura, sonda: sn, diferente: rest, id_cu_regiune: ac ? ac.id : [], primele: (x.detalii || []).slice(0, 3) }); }
        }
    }

    // măștile declarate și folosirea lor (rez.masti al fiecărei capturi), normalizările
    const folosire = new Map();
    for (const r of rezultate) {
        for (const m of r.masti || []) {
            const k = m.i !== undefined ? m.i : m.nume;
            if (!folosire.has(k)) { folosire.set(k, { capturi_in_arie: 0, capturi_prinse: 0, elemente: 0 }); }
            const f = folosire.get(k);
            f.capturi_in_arie++;
            const el = (Number(m.referinta) || 0) + (Number(m.candidat) || 0);
            if (el > 0 || m.dreptunghiuri > 0) { f.capturi_prinse++; }
            f.elemente += el;
        }
    }
    // măștile cu stări observate: pe ce stare a căzut candidatul (cea a primei încărcări = referința, sau alta)
    const stariCand = new Map();
    for (const r of rezultate) {
        for (const x of ((r.sonde || {}).stil || {}).stari_masti || []) {
            if (!stariCand.has(x.i)) { stariCand.set(x.i, {}); }
            const c = stariCand.get(x.i);
            const et = x.stare < 0 ? 'în afara stărilor observate' : 'starea încărcărilor ' + x.incarcari.join(', ');
            c[et] = (c[et] || 0) + 1;
        }
    }
    const masti = (config.masti || []).map((m, i) => {
        const f = folosire.get(i) || folosire.get(m.nume) || { capturi_in_arie: 0, capturi_prinse: 0, elemente: 0 };
        const inFelie = !m.felii || m.felii.map(String).includes(String(felie));
        return {
            nr: i + 1, nume: m.nume, motiv: m.motiv || null, selector: m.selector || null, dreptunghiuri: m.dreptunghiuri || (m.dreptunghi ? [m.dreptunghi] : null),
            ...(m.atribute ? { atribute: m.atribute } : {}), ...(m.clase ? { clase: m.clase } : {}), ...(m.baleiaj_chei ? { baleiaj_chei: m.baleiaj_chei } : {}),
            arie: { pagini: m.pagini || ['*'], profiluri: m.profiluri || ['*'], ferestre: m.ferestre || ['*'], stari: m.stari || ['*'], felii: m.felii || ['*'] },
            ...(m.capturi ? { capturi: m.capturi } : {}),
            sonde: m.sonde || ['toate'], propus_automat: !!m.propus_automat, ...(m.k !== undefined ? { k: m.k, n: m.n } : {}),
            ...(m.nota ? { nota: m.nota } : {}), ...(m.revizuit ? { revizuit: m.revizuit } : {}),
            folosire: f, stare: !inFelie ? 'în afara feliei' : (f.capturi_prinse ? 'aplicată' : 'neaplicată'),
            ...(m.stari_observate ? { stari_candidat: stariCand.get(i) || {} } : {}),
        };
    });
    const normalizari = listaNormalizari(config);

    const numara = {};
    for (const x of lista) { numara[x.stare] = (numara[x.stare] || 0) + 1; }
    const blocante = lista.filter((x) => x.stare === 'picat' || x.stare === 'fără dovadă');
    const harnessDir = __dirname;
    const fisiereHarness = {};
    for (const f of ['captura.cjs', 'compara.cjs', 'raport.cjs', 'zgomot.cjs', 'lib/retea.cjs', 'lib/sonde-pagina.cjs', 'lib/arbori.cjs', 'lib/png.cjs', 'lib/profiluri.cjs', 'lib/depozit.cjs', 'lib/selector.cjs']) {
        const c = path.join(harnessDir, f);
        if (fs.existsSync(c)) { fisiereHarness[f] = sha256(fs.readFileSync(c)); }
    }
    const indexSha = (d) => { const f = path.join(d, 'index.json'); return fs.existsSync(f) ? sha256(fs.readFileSync(f)) : null; };
    const raport = {
        format: 'paritate-wp/2',
        felie,
        ora: new Date().toISOString(),
        commit: a.commit || null,
        amprenta_baza: a['amprenta-baza'] || null,
        manifest: a.manifest ? { cale: path.resolve(a.manifest), sha256: sha256(fs.readFileSync(a.manifest)) } : null,
        referinta: { dosar: comparatii.referinta, index_sha256: indexSha(comparatii.referinta) },
        candidat: { dosar: comparatii.candidat, index_sha256: indexSha(comparatii.candidat) },
        config_sha256: sha256(configText),
        verificare_sha256: sha256(verificareText),
        comparatii_sha256: sha256(fs.readFileSync(a.comparatii)),
        declaratii_sha256: declaratiiText ? sha256(Buffer.from(declaratiiText)) : null,
        harness: fisiereHarness,
        verdict: blocante.length === 0 && neatribuite.length === 0 ? 'trecut' : 'picat',
        sumar: {
            puncte: lista.length, ...numara, blocante: blocante.length, critice_blocante: blocante.filter((x) => x.critic).length, capturi: rezultate.length,
            neatribuite: neatribuite.length, masti: masti.length, masti_propuse_automat: masti.filter((m) => m.propus_automat).length,
            masti_neaplicate: masti.filter((m) => m.stare === 'neaplicată').length,
        },
        masti,
        // Stilurile din head comparate pe reguli, nu pe text (declarate: normalizari.head.stiluri_pe_reguli).
        comparate_pe_reguli: (((config.normalizari || {}).head || {}).stiluri_pe_reguli || []).map((id) => ({ id, nota: 'comparat pe reguli' })),
        normalizari,
        neatribuite: neatribuite.slice(0, 500),
        puncte: lista,
        sha256: '',
    };
    raport.sha256 = semnatura(raport);
    fs.mkdirSync(a.iesire, { recursive: true });
    fs.writeFileSync(path.join(a.iesire, 'raport.json'), JSON.stringify(raport, null, 1));
    fs.writeFileSync(path.join(a.iesire, 'raport.html'), html(raport, comparatii));
    console.log('RAPORT felia ' + felie + ': ' + raport.verdict.toUpperCase() + ' ' + JSON.stringify(raport.sumar) + ' sha256=' + raport.sha256);
    return raport.verdict === 'trecut' ? 0 : 1;
}

// Diferențele unei sonde care cad în regiunea punctului (cheile elementelor sau dreptunghiurile); o listă tăiată sau o sondă
// fără chei se socotește întreagă în regiune.
function inRegiune(x, nume, reg) {
    if (FARA_CHEI.has(nume)) { return x.diferente; }
    if (nume === 'pixeli') {
        if (!x.zone || x.zone_complete === false) { return x.diferente; }
        const dr = reg.dreptunghiuri || [];
        return x.zone.reduce((s, z, i) => s + (dr.some((w) => intersecteaza((x.exacte || [])[i] || z, w)) ? z[4] : 0), 0);
    }
    if (!x.chei || x.chei_complete === false) { return x.diferente; }
    const chei = reg.chei || [];
    return x.chei.filter((k) => chei.some((c) => k === c || k.startsWith(c + '>'))).length;
}

// Diferențele care nu cad în nicio regiune dintre cele date (cele neatribuite).
function inAfaraRegiunilor(x, nume, regs) {
    if (FARA_CHEI.has(nume)) { return x.diferente; }
    if (nume === 'pixeli') {
        if (!x.zone || x.zone_complete === false) { return x.diferente; }
        const dr = regs.flatMap((r) => r.dreptunghiuri || []);
        return x.zone.reduce((s, z, i) => s + (dr.some((w) => intersecteaza((x.exacte || [])[i] || z, w)) ? 0 : z[4]), 0);
    }
    if (!x.chei || x.chei_complete === false) { return x.diferente; }
    const chei = regs.flatMap((r) => r.chei || []);
    return x.chei.filter((k) => !chei.some((c) => k === c || k.startsWith(c + '>'))).length;
}

const intersecteaza = (z, w) => z[0] < w[0] + w[2] && z[0] + z[2] > w[0] && z[1] < w[1] + w[3] && z[1] + z[3] > w[1];

// Normalizările declarate (DECIZIE §6.7), pentru lista din raport: tipul, ce prind, motivul.
function listaNormalizari(config) {
    const n = config.normalizari || {};
    const l = [];
    const pune = (tip, ce, motiv) => l.push({ tip, ce: typeof ce === 'string' ? ce : JSON.stringify(ce), motiv: motiv || null });
    for (const x of n.inlocuiri || []) { pune('înlocuire', x.re + ' → ' + x.cu, x.nume || x.motiv); }
    const d = n.dom || {};
    for (const x of d.ignora_elemente || []) { pune('dom: element ignorat', x, d.motiv_ignora_elemente); }
    for (const x of d.ignora_atribute || []) { pune('dom: atribut ignorat', x, null); }
    for (const x of d.ignora_atribute_selector || []) { pune('dom: atribute ignorate pe selector', x.selector + ' ' + (x.atribute || []).join(','), x.motiv); }
    for (const x of d.ignora_clase || []) { pune('dom: clase ignorate pe selector', x.selector + ' ' + (x.clase || []).join(','), x.motiv); }
    if (d.clase_multimi !== undefined) { pune('dom: clasele ca mulțimi', String(d.clase_multimi), null); }
    if (d.stil_gol_absent !== undefined) { pune('dom: style="" ca absent', String(d.stil_gol_absent), null); }
    const h = n.head || {};
    for (const x of h.ignora || []) { pune('head: element ignorat', [x.tag, x.atribut, x.re].filter(Boolean).join(' '), x.motiv); }
    for (const x of h.ignora_atribute || []) { pune('head: atribut ignorat', x, null); }
    for (const x of (n.cssom || {}).ignora_foi || []) { pune('cssom: foaie ignorată', x.href_re || x.nod_re, x.motiv); }
    const r = n.retea || {};
    for (const x of r.ignora_cai || []) { pune('rețea: cale ignorată', x, null); }
    for (const x of r.doar_referinta || []) { pune('rețea: doar la referință', x, null); }
    for (const x of r.doar_candidat || []) { pune('rețea: doar la candidat', x, null); }
    for (const x of config.csp_exceptii || []) { pune('csp: excepție', [x.directiva, x.uri_re].filter(Boolean).join(' '), x.motiv); }
    return l;
}

function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function html(r, comparatii) {
    const culoare = { trecut: '#1a7f37', picat: '#cf222e', declarat: '#9a6700', 'fără dovadă': '#8250df', 'în așteptare': '#6e7781', amânat: '#6e7781' };
    const eticheta = (s) => '<span class="st" style="background:' + (culoare[s] || '#444') + '">' + esc(s) + '</span>';
    const randuri = r.puncte.map((p) => {
        const sonde = Object.entries(p.sonde || {}).map(([k, v]) => {
            const det = v.picate ? v.picate.slice(0, 5).map((x) => '<li>' + esc(x.captura) + ': ' + esc(x.eroare || x.diferente || (x.sonde || []).join(', '))
                + (x.dovada && x.dovada.suprapunere ? ' — <a href="' + esc(x.dovada.suprapunere) + '">suprapunerea</a>' : '')
                + (x.primele ? '<pre>' + esc(JSON.stringify(x.primele, null, 1).slice(0, 1200)) + '</pre>' : '') + '</li>').join('') : '';
            return '<div>' + esc(k) + ' ' + eticheta(v.stare) + (v.capturi ? ' <small>' + v.capturi + ' capturi</small>' : '')
                + (v.dovada ? ' <small>' + esc([].concat(v.dovada).join('; ')) + '</small>' : '') + (det ? '<ul>' + det + '</ul>' : '') + '</div>';
        }).join('');
        const note = (p.regiune ? '<div><small>regiunea: <code>' + esc(typeof p.regiune.selector === 'string' ? p.regiune.selector : JSON.stringify(p.regiune.selector)) + '</code></small></div>' : '')
            + (p.sonde_inlocuite ? '<div><small>sondele înlocuite din config (inițial: ' + esc(p.sonde_inlocuite.initial.join(', ')) + '): ' + esc(p.sonde_inlocuite.motiv) + '</small></div>' : '');
        return '<tr class="r-' + esc(p.stare.replace(/\s+/g, '-')) + '"><td>' + esc(p.id) + (p.critic ? ' <b>!</b>' : '') + '</td><td>' + esc(p.felie) + '</td><td>' + esc(p.clasa)
            + '</td><td>' + eticheta(p.stare) + '</td><td>' + note + (sonde ? '<details><summary>' + Object.keys(p.sonde || {}).length + ' sonde</summary>' + sonde + '</details>' : '') + '</td></tr>';
    }).join('\n');
    const arie = (a) => Object.entries(a).filter(([, v]) => !(v.length === 1 && v[0] === '*')).map(([k, v]) => k + ': ' + v.join(', ')).join('; ') || 'peste tot';
    const tinta = (m) => (m.selector ? m.selector + (m.atribute ? ' [atributele ' + m.atribute.join(', ') + ']' : '') + (m.clase ? ' [clasele ' + m.clase.join(' ') + ']' : '')
        : m.baleiaj_chei ? 'baleiaj: ' + m.baleiaj_chei.join(' | ') : JSON.stringify(m.dreptunghiuri));
    const peReguli = (r.comparate_pe_reguli || []).map((x) => '<li><code>' + esc(x.id) + '</code>: ' + esc(x.nota) + '</li>').join('');
    const masti = (r.masti || []).map((m) => '<tr><td>' + m.nr + '</td><td>' + esc(m.nume) + (m.propus_automat ? ' <span class="st" style="background:#0969da">propusă automat</span>' : '')
        + '</td><td>' + esc(m.motiv) + (m.nota ? '<br><small>' + esc(m.nota) + '</small>' : '') + '</td><td><code>' + esc(tinta(m)) + '</code></td><td>'
        + esc(arie(m.arie)) + (m.capturi ? '<details><summary>' + m.capturi.length + ' capturi</summary><small>' + esc(m.capturi.join(', ')) + '</small></details>' : '') + '</td><td>' + esc(m.sonde.join(', ')) + '</td><td>' + '<span class="st" style="background:' + ({ aplicată: '#1a7f37', neaplicată: '#cf222e' }[m.stare] || '#6e7781') + '">' + esc(m.stare) + '</span>'
        + ' <small>' + m.folosire.capturi_prinse + '/' + m.folosire.capturi_in_arie + ' capturi, ' + m.folosire.elemente + ' elemente</small>'
        + (m.stari_candidat ? '<br><small>candidatul: ' + esc(Object.entries(m.stari_candidat).map(([k, n]) => k + ' × ' + n).join('; ') || 'nicio captură') + '</small>' : '') + '</td></tr>').join('\n');
    const normalizari = (r.normalizari || []).map((n) => '<tr><td>' + esc(n.tip) + '</td><td><code>' + esc(n.ce) + '</code></td><td>' + esc(n.motiv) + '</td></tr>').join('\n');
    const neatribuite = (r.neatribuite || []).map((n) => '<tr><td>' + esc(n.captura) + '</td><td>' + esc(n.sonda) + '</td><td>' + esc(n.diferente) + '</td><td><pre>'
        + esc(JSON.stringify(n.primele, null, 1).slice(0, 800)) + '</pre></td></tr>').join('\n');
    const capturi = (comparatii.rezultate || []).map((c) => {
        const s = Object.entries(c.sonde || {}).map(([k, v]) => k + ':' + (v.stare === 'trecut' ? 'ok' : v.diferente)).join(' ');
        return '<tr><td>' + esc(c.captura) + '</td><td>' + esc(c.eroare || s) + '</td></tr>';
    }).join('\n');
    return '<!DOCTYPE html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<title>Raportul de paritate, felia ' + esc(r.felie) + '</title><style>body{font:14px/1.45 system-ui,sans-serif;margin:16px;color:#1f2328;background:#fff}'
        + 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #d0d7de;padding:4px 6px;text-align:left;vertical-align:top}'
        + '.st{color:#fff;border-radius:10px;padding:1px 8px;font-size:12px}pre{white-space:pre-wrap;font-size:11px;background:#f6f8fa;padding:4px;max-height:240px;overflow:auto}'
        + 'code{font-size:12px}.sumar td{border:0;padding:2px 10px 2px 0}</style></head><body>'
        + '<h1>Raportul de paritate — felia ' + esc(r.felie) + ' — ' + eticheta(r.verdict) + '</h1>'
        + '<table class="sumar"><tr><td>commit</td><td><code>' + esc(r.commit) + '</code></td></tr><tr><td>amprenta bazei</td><td><code>' + esc(r.amprenta_baza) + '</code></td></tr>'
        + '<tr><td>manifest</td><td><code>' + esc(r.manifest ? r.manifest.sha256 : null) + '</code></td></tr><tr><td>sha256 raport</td><td><code>' + esc(r.sha256) + '</code></td></tr>'
        + '<tr><td>ora</td><td>' + esc(r.ora) + '</td></tr><tr><td>sumar</td><td><code>' + esc(JSON.stringify(r.sumar)) + '</code></td></tr></table>'
        + (neatribuite ? '<h2>Diferențele neatribuite (niciun ID nu le acoperă)</h2><table><tr><th>captura</th><th>sonda</th><th>diferențe</th><th>primele</th></tr>' + neatribuite + '</table>' : '')
        + (peReguli ? '<h2>Stiluri comparate pe reguli</h2><ul>' + peReguli + '</ul>' : '')
        + '<h2>Măștile declarate (' + (r.masti || []).length + ')</h2><p><small>Fiecare mască are un motiv; cele propuse automat vin din zgomotul referinței '
        + '(zgomot.cjs), cu k/N = încărcările care s-au abătut. „neaplicată” = nicio captură n-a avut ceva de prins.</small></p>'
        + '<table><tr><th>#</th><th>numele</th><th>motivul</th><th>ținta</th><th>aria</th><th>sondele</th><th>folosirea</th></tr>' + masti + '</table>'
        + '<h2>Normalizările declarate (' + (r.normalizari || []).length + ')</h2><table><tr><th>tipul</th><th>ce prinde</th><th>motivul</th></tr>' + normalizari + '</table>'
        + '<h2>Punctele</h2><table><tr><th>ID</th><th>felia</th><th>clasa</th><th>starea</th><th>sondele</th></tr>' + randuri + '</table>'
        + '<h2>Capturile comparate</h2><table><tr><th>captura</th><th>sondele</th></tr>' + capturi + '</table></body></html>\n';
}

function verifica(a) {
    const f = a.pozitionale[0];
    if (!f) { throw new Error('folosire: raport.cjs verifica <raport.json> [--sha256=<așteptat>]'); }
    const r = JSON.parse(fs.readFileSync(f, 'utf8'));
    const calculat = semnatura(r);
    const ok = calculat === r.sha256 && (!a.sha256 || a.sha256 === r.sha256);
    console.log((ok ? 'OK ' : 'NU ') + 'sha256 ' + r.sha256 + (calculat !== r.sha256 ? ' (calculat ' + calculat + ')' : '') + (a.sha256 && a.sha256 !== r.sha256 ? ' (așteptat ' + a.sha256 + ')' : '')
        + ' verdict=' + r.verdict + ' felia=' + r.felie + ' commit=' + r.commit);
    return ok ? (r.verdict === 'trecut' ? 0 : 1) : 1;
}

if (require.main === module) {
    try {
        const a = argumente();
        if (a.mod === 'scrie') { process.exitCode = scrie(a); } else if (a.mod === 'verifica') { process.exitCode = verifica(a); } else {
            throw new Error('folosire: raport.cjs scrie|verifica … (vezi capul fișierului)');
        }
    } catch (e) {
        console.error('EROARE ' + (e && e.message || e));
        process.exit(2);
    }
}

module.exports = { semnatura, scrie, verifica };
