// Scenariul „baleiaj” (DECIZIE §6.5: „Antetul: baleiaj 981–2560”): o singură încărcare, apoi fereastra lărgită pas cu pas;
// la fiecare lățime, pentru elementele vizibile ale zonei (selectorul din configurație): cutiile, câteva proprietăți
// calculate și captura zonei (decupată). Rezultatul îl compară compara.cjs (sondele geometrie, stil și pixeli ale stării).
//
// Configurația capturii: "baleiaj": { "selector": "<zona>", "de_la": 981, "pana_la": 2560, "pas": 10, "inaltime": 900,
//                                     "inaltime_decupaj": 600, "masti": ["<selectorii măștilor feliei>"] }
// Elementele din măști (closest) poartă m:1 și nu se compară; dreptunghiurile lor mascază pixelii decupajului.

'use strict';

const PROPRIETATI = ['display', 'position', 'top', 'left', 'width', 'height', 'margin-left', 'margin-right', 'padding-left', 'padding-right',
    'font-size', 'line-height', 'color', 'background-color', 'opacity', 'visibility', 'z-index', 'flex-direction', 'flex-wrap', 'justify-content', 'transform'];

async function baleiaj(page, u) {
    const b = u.c.baleiaj || {};
    const sel = b.selector || 'header';
    const rez = { tip: 'baleiaj', selector: sel, proprietati: PROPRIETATI, pasi: [] };
    for (let w = b.de_la || 981; w <= (b.pana_la || 2560); w += (b.pas || 10)) {
        const h = b.inaltime || 900;
        await page.setViewportSize({ width: w, height: h });
        await page.evaluate(() => new Promise((g) => requestAnimationFrame(() => requestAnimationFrame(g))));
        await page.waitForTimeout(60);
        const d = await page.evaluate(({ sel, prop, masti }) => {
            const rad = document.querySelector(sel);
            if (!rad) { return null; }
            const cheie = (el) => {
                const parti = [];
                for (let e = el; e && e !== rad.parentElement; e = e.parentElement) {
                    let i = 1;
                    for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
                    parti.unshift(e.tagName.toLowerCase() + ':' + i);
                }
                return parti.join('>');
            };
            const rot = (v) => String(v).replace(/(-?\d*\.?\d+)px/g, (_, n) => Math.round(parseFloat(n)) + 'px');
            const r0 = rad.getBoundingClientRect();
            const sm = masti.length ? masti.join(', ') : null;
            const el = [rad, ...rad.querySelectorAll('*')].filter((e) => e.getClientRects().length > 0).map((e) => {
                const r = e.getBoundingClientRect();
                const cs = getComputedStyle(e);
                const x = { k: cheie(e), g: [Math.round((r.x + scrollX) * 10) / 10, Math.round((r.y + scrollY) * 10) / 10, Math.round(r.width * 10) / 10, Math.round(r.height * 10) / 10], v: prop.map((p) => rot(cs.getPropertyValue(p))) };
                if (sm) { try { if (e.closest(sm)) { x.m = 1; } } catch (err) { /* selector greșit: nemascat */ } }
                return x;
            });
            // dreptunghiurile măștilor (pentru pixeli), în coordonatele paginii
            const zone = [];
            if (sm) { try { for (const e of rad.querySelectorAll(sm)) { for (const r of e.getClientRects()) { zone.push([r.x + scrollX, r.y + scrollY, r.width, r.height]); } } } catch (err) { /* idem */ } }
            return { el, zone, cutie: [r0.x + scrollX, r0.y + scrollY, r0.width, r0.height], sw: document.documentElement.scrollWidth };
        }, { sel, prop: PROPRIETATI, masti: b.masti || [] });
        if (!d) { rez.pasi.push({ w, h, lipsa: true }); continue; }
        const clip = { x: 0, y: Math.max(0, d.cutie[1]), width: w, height: Math.max(1, Math.min(d.cutie[3], b.inaltime_decupaj || 600)) };
        const png = await page.screenshot({ clip, animations: 'disabled', caret: 'hide' });
        rez.pasi.push({ w, h, sw: d.sw, el: d.el, decupaj: [clip.x, clip.y, clip.width, clip.height], masti: d.zone.map((z) => [z[0] - clip.x, z[1] - clip.y, z[2], z[3]]), ecran: u.depozit.puneOcteti(png, '.png') });
    }
    await page.setViewportSize({ width: u.fereastra.latime, height: u.fereastra.inaltime });
    return rez;
}

module.exports = { baleiaj };
