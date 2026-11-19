'use strict';
// Forma normală a unei foi mici (decizia coordonatorului pentru fit-vids-style): fiecare selector → mulțimea declarațiilor lui,
// fără deosebire de spații, ordine, „;” final, litere mari ale proprietăților, culori (#abc = #aabbcc, litere mici) și unitățile
// unui zero (0px = 0). Două texte cu aceleași reguli dau aceeași formă. Folosită doar pentru stilurile declarate (opt-in, ca
// măștile: paritate.json → normalizari.head.stiluri_pe_reguli), restul head-ului se compară pe text.

function curata(css) {
    return String(css || '').replace(/\/\*[\s\S]*?\*\//g, '');
}

function valoare(v) {
    let x = v.trim().replace(/\s+/g, ' ').replace(/\s*,\s*/g, ',').replace(/\(\s+/g, '(').replace(/\s+\)/g, ')');
    x = x.replace(/#([0-9a-f])([0-9a-f])([0-9a-f])\b/gi, '#$1$1$2$2$3$3').replace(/#[0-9a-f]{6}\b/gi, (m) => m.toLowerCase());
    x = x.replace(/(^|[\s,(])[+-]?0+(?:\.0+)?(?:px|em|rem|%|pt|vh|vw|ex|ch|cm|mm|in|pc)?(?=$|[\s,)])/gi, '$10');
    return x;
}

function selector(s) {
    return s.trim().replace(/\s+/g, ' ').replace(/\s*([>+~])\s*/g, '$1');
}

/** Forma normală: o listă sortată de „selector{decl;decl}”, cu declarațiile sortate; null dacă textul nu e o foaie simplă. */
function formaReguli(css) {
    const text = curata(css);
    const harta = new Map();
    const re = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    let rest = text;
    while ((m = re.exec(text)) !== null) {
        rest = rest.replace(m[0], '');
        const decl = m[2].split(';').map((d) => d.trim()).filter((d) => d !== '').map((d) => {
            const i = d.indexOf(':');
            return i < 0 ? d.toLowerCase() : d.slice(0, i).trim().toLowerCase() + ':' + valoare(d.slice(i + 1));
        });
        for (const s of m[1].split(',').map(selector).filter((s) => s !== '')) {
            const set = harta.get(s) || new Set();
            decl.forEach((d) => set.add(d));
            harta.set(s, set);
        }
    }
    if (rest.trim() !== '') {
        return null; // altceva decât reguli simple (at-rules, text rămas): nu se compară pe reguli
    }
    return [...harta.entries()].map(([s, d]) => s + '{' + [...d].sort().join(';') + '}').sort();
}

/** Aceleași reguli? */
function egalePeReguli(a, b) {
    const fa = formaReguli(a);
    const fb = formaReguli(b);
    return fa !== null && fb !== null && JSON.stringify(fa) === JSON.stringify(fb);
}

module.exports = { formaReguli, egalePeReguli };
