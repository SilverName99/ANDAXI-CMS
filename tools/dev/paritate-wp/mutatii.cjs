// Inventarul mutațiilor (DECIZIE §6.1; C2 §10.1): DOM-ul după scripturi minus HTML-ul static, pe fiecare pagină, profil,
// fereastră și stare a unei capturi, adunat într-un singur fișier pentru autorii runtime-ului (pistele R și R2).
//
//   node mutatii.cjs --capturi=<dosar>[,<dosar2>…] --iesire=<mutatii.json> [--reguli=<paritate.json>]
//
// Dosarele se citesc în ordine; o captură găsită și într-un dosar de mai târziu îl înlocuiește pe cel dinainte (de exemplu o
// rulare doar pentru mutații, cu momente mai curate). Fiecare mutație unică (tip, cheie, semnătură, valori) primește un id;
// pe captură rămân id-ul, momentul primei apariții (ms de la începutul navigării), de câte ori s-a schimbat și faza față de
// DOMContentLoaded/load. `reguli` (paritate.json → mutatii.module: [{modul, re, motiv}]) propune modulul de runtime al
// fiecărei mutații după text (o propunere, de verificat de autorul modulului); fără regulă: „(nealocat)”.

'use strict';

const fs = require('fs');
require('./lib/iesire.cjs').pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo (codul 2)
const path = require('path');
const crypto = require('crypto');
const { Depozit } = require('./lib/depozit.cjs');

function argumente() {
    const a = {};
    for (const x of process.argv.slice(2)) {
        const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
        if (!m) { throw new Error('argument necunoscut: ' + x); }
        a[m[1]] = m[2];
    }
    if (!a.capturi || !a.iesire) { throw new Error('folosire: mutatii.cjs --capturi=<dosar>[,…] --iesire=<mutatii.json> [--reguli=<paritate.json>]'); }
    return a;
}

function metaDin(dosar) {
    const l = [];
    const umbla = (d) => {
        for (const n of fs.readdirSync(d, { withFileTypes: true })) {
            if (n.name.startsWith('_')) { continue; }
            const c = path.join(d, n.name);
            if (n.isDirectory()) { umbla(c); } else if (n.name === 'meta.json') { l.push(JSON.parse(fs.readFileSync(c, 'utf8'))); }
        }
    };
    umbla(dosar);
    return l;
}

function rezumat(x) {
    switch (x.tip) {
        case 'clase': return x.k + ' ' + x.semn + ' +[' + x.plus.join(' ') + '] -[' + x.minus.join(' ') + ']';
        case 'stil': return x.k + ' ' + x.semn + ' style: ' + x.inainte + ' → ' + x.dupa;
        case 'atribut': return x.k + ' ' + x.semn + ' @' + x.atribut + ': ' + x.inainte + ' → ' + x.dupa;
        case 'text': return x.k + ' ' + x.semn + ' text: ' + JSON.stringify(x.inainte) + ' → ' + JSON.stringify(x.dupa);
        case 'nod-adaugat': return 'nod adăugat în ' + x.parinte + ' (poziția ' + x.pozitie + '): ' + x.semn;
        case 'nod-mutat': return 'nod mutat din ' + x.mutatDin + ' în ' + x.parinte + ': ' + x.semn;
        case 'nod-scos': return 'nod scos din ' + x.parinte + ': ' + x.semn;
        default: return x.tip + ' ' + (x.k || '') + ' ' + (x.semn || '');
    }
}

function main() {
    const a = argumente();
    const reguli = a.reguli ? ((JSON.parse(fs.readFileSync(a.reguli, 'utf8')).mutatii || {}).module || []).map((r) => ({ ...r, re: new RegExp(r.re) })) : [];
    const capturi = new Map();
    for (const d of a.capturi.split(',')) {
        const dep = new Depozit(d);
        for (const m of metaDin(d)) {
            if (m.eroare || !m.obiecte || !m.obiecte.mutatii) { continue; }
            capturi.set(m.captura, { m, dep, dosar: d });
        }
    }
    const unice = new Map();
    const iesire = { _despre: 'Inventarul mutațiilor referinței (DOM după scripturi − HTML static), pe captură; generat de tools/dev/paritate-wp/mutatii.cjs. Privat.', generat: new Date().toISOString(), surse: a.capturi.split(','), mutatii: {}, capturi: {}, pe_pagina: {} };
    for (const [id, { m, dep, dosar }] of [...capturi.entries()].sort()) {
        const d = dep.citesteJson(m.obiecte.mutatii);
        const ev = Object.fromEntries((d.evenimente || []).map((e) => [e.ev, e.t]));
        const dcl = ev.DOMContentLoaded;
        const load = ev.load;
        const lista = [];
        for (const x of d.mutatii) {
            const desc = { ...x };
            delete desc.timp;
            delete desc.html;
            delete desc.noduri;
            const text = rezumat(x);
            const cheie = crypto.createHash('sha1').update(JSON.stringify(desc)).digest('hex').slice(0, 12);
            if (!unice.has(cheie)) {
                const r = reguli.find((g) => g.re.test(text));
                unice.set(cheie, { ...desc, rezumat: text, html: x.html || undefined, noduri: x.noduri || undefined, modul_propus: r ? r.modul : '(nealocat)', motiv_regula: r ? r.motiv : undefined });
            }
            const t = x.timp ? x.timp.prima : null;
            const faza = t === null ? 'fara-moment' : (dcl !== undefined && t < dcl ? 'inainte-de-DOMContentLoaded' : (load !== undefined && t < load ? 'intre-DOMContentLoaded-si-load' : 'dupa-load'));
            lista.push([cheie, t, x.timp ? x.timp.ori : null, faza]);
            const pg = iesire.pe_pagina[m.pagina] || (iesire.pe_pagina[m.pagina] = {});
            const e = pg[cheie] || (pg[cheie] = { profiluri: [], ferestre: [], stari: [] });
            if (!e.profiluri.includes(m.profil)) { e.profiluri.push(m.profil); }
            if (!e.ferestre.includes(m.fereastra)) { e.ferestre.push(m.fereastra); }
            if (!e.stari.includes(m.stare)) { e.stari.push(m.stare); }
        }
        iesire.capturi[id] = { dosar, dcl, load, nr: lista.length, lista };
    }
    for (const [k, v] of unice) { iesire.mutatii[k] = v; }
    const pm = {};
    for (const v of unice.values()) { pm[v.modul_propus] = (pm[v.modul_propus] || 0) + 1; }
    iesire.sumar = { capturi: capturi.size, mutatii_unice: unice.size, pe_modul: pm };
    fs.writeFileSync(a.iesire, JSON.stringify(iesire));
    console.log('mutatii: ' + capturi.size + ' capturi, ' + unice.size + ' mutații unice → ' + a.iesire + ' ' + JSON.stringify(pm));
}

if (require.main === module) {
    try { main(); } catch (e) { console.error('EROARE ' + (e && e.message || e)); process.exit(2); }
}
