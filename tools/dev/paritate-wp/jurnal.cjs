// Jurnalul de rețea al referinței, rezumat pentru captură (C2 4b: „jurnalul de rețea al referinței ajunge aici ca --jurnal=”):
// fiecare adresă cerută de browser pe site și pe gazdele permise (fonturile), cu felul ei în vocabularul lui
// Captura\Resurse (document, foaie, script, imagine, font, media, xhr, altul), statusul, tipul, sha256 și mărimea primite,
// paginile/profilurile/ferestrele unde a apărut; plus încercările blocate (trackere, gazde terțe, căi abortate, non-GET),
// numărate pe gazdă și motiv, cu paginile lor.
//
//   node jurnal.cjs --jurnal=<jurnal-retea.jsonl>[,<alt.jsonl>] --iesire=<jurnal-resurse.json>

'use strict';

const fs = require('fs');
require('./lib/iesire.cjs').pazesteScrierile(); // recenzia 3, R3-6: nicio scriere în repo (codul 2)

const FEL = { document: 'document', stylesheet: 'foaie', script: 'script', image: 'imagine', font: 'font', media: 'media', xhr: 'xhr', fetch: 'xhr' };

function main() {
    const a = {};
    for (const x of process.argv.slice(2)) {
        const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
        if (!m) { throw new Error('argument necunoscut: ' + x); }
        a[m[1]] = m[2];
    }
    if (!a.jurnal || !a.iesire) { throw new Error('folosire: jurnal.cjs --jurnal=<jurnal.jsonl>[,…] --iesire=<jurnal-resurse.json>'); }
    const resurse = new Map();
    const blocate = new Map();
    let cereri = 0;
    const actiuni = {};
    for (const f of a.jurnal.split(',')) {
        for (const linie of fs.readFileSync(f, 'utf8').split('\n')) {
            if (!linie.trim()) { continue; }
            let e;
            try { e = JSON.parse(linie); } catch (err) { continue; }
            cereri++;
            actiuni[e.actiune] = (actiuni[e.actiune] || 0) + 1;
            const loc = (e.pagina || '?') + '/' + (e.profil || '?') + '/' + (e.fereastra || '?');
            if (e.actiune === 'abortat') {
                let gazda = '?';
                try { gazda = new URL(e.url).host; } catch (err) { /* adresă tăiată */ }
                const k = e.clasa + '|' + gazda + '|' + e.motiv;
                const b = blocate.get(k) || { clasa: e.clasa, gazda, motiv: e.motiv, incercari: 0, adrese: new Set(), pagini: new Set(), acord: new Set() };
                b.incercari++;
                if (b.adrese.size < 50) { b.adrese.add(e.url); }
                b.pagini.add(e.pagina);
                b.acord.add(e.acord || '?');
                blocate.set(k, b);
                continue;
            }
            if (e.actiune !== 'cache' && e.actiune !== 'descarcat') { continue; }
            const r = resurse.get(e.url) || { url: e.url, clasa: e.clasa, fel: FEL[e.tip] || 'altul', tip_browser: e.tip, status: new Set(), tip_continut: e.tip_continut, sha256: new Set(), octeti: e.octeti,
                pagini: new Set(), profiluri: new Set(), ferestre: new Set(), descarcari: 0, servit_din_cache: 0, prima: loc };
            r.status.add(e.status);
            if (e.sha256) { r.sha256.add(e.sha256); }
            r.pagini.add(e.pagina);
            r.profiluri.add(e.profil);
            r.ferestre.add(e.fereastra);
            if (e.actiune === 'descarcat') { r.descarcari++; } else { r.servit_din_cache++; }
            resurse.set(e.url, r);
        }
    }
    const lista = [...resurse.values()].map((r) => ({ ...r, status: [...r.status], sha256: [...r.sha256], pagini: [...r.pagini].sort(), profiluri: [...r.profiluri].sort(), ferestre: [...r.ferestre].sort() }))
        .sort((x, y) => x.url.localeCompare(y.url));
    const bl = [...blocate.values()].map((b) => ({ ...b, adrese: [...b.adrese], pagini: [...b.pagini].sort(), acord: [...b.acord] })).sort((x, y) => y.incercari - x.incercari);
    const peFel = {};
    for (const r of lista) { peFel[r.clasa + '/' + r.fel] = (peFel[r.clasa + '/' + r.fel] || 0) + 1; }
    const iesire = {
        _despre: 'Resursele cerute de browser pe referința live (fiecare adresă o dată) și încercările blocate; din jurnalul harness-ului de paritate (tools/dev/paritate-wp/jurnal.cjs). Privat.',
        generat: new Date().toISOString(), surse: a.jurnal.split(','),
        sumar: { cereri_in_jurnal: cereri, actiuni, adrese_unice: lista.length, pe_fel: peFel, blocate_incercari: bl.reduce((s, b) => s + b.incercari, 0) },
        resurse: lista, blocate: bl,
    };
    fs.writeFileSync(a.iesire, JSON.stringify(iesire, null, 1));
    console.log('jurnal: ' + lista.length + ' adrese, ' + bl.length + ' grupuri blocate → ' + a.iesire + ' ' + JSON.stringify(iesire.sumar));
}

if (require.main === module) {
    try { main(); } catch (e) { console.error('EROARE ' + (e && e.message || e)); process.exit(2); }
}
