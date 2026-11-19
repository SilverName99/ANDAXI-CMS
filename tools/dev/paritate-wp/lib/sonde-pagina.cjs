// Sondele din pagină ale harness-ului de paritate (DECIZIE §6.2): funcțiile rulate în browser, aceleași la referință și la
// candidat. Nimic din ele nu schimbă pagina, în afară de faza de măsurare (pozele leneșe încărcate), care vine după ce DOM-ul
// de la încărcare a fost citit.
//
//  - INIT (în fiecare cadru, înaintea scripturilor paginii): MutationObserver pe tot documentul de la început (clase, stiluri,
//    atribute cu valoarea veche, noduri, text), momentele DOMContentLoaded/load, încărcarea fiecărui script extern,
//    încălcările CSP (`securitypolicyviolation`) și erorile. Totul cu performance.now(), deci față de începutul navigării;
//  - instantaneu(): DOM-ul după scripturi, head-ul, linkurile, imaginile, textul, stilurile calculate și geometria fiecărui
//    element vizibil (cheia: drumul de etichete de la <html>, ca în tools/dev/capturi-publice.cjs), CSSOM-ul ordonat;
//  - diferente(html static): inventarul mutațiilor = DOM-ul după scripturi minus HTML-ul static (parsat cu DOMParser, fără
//    scripturi), nod cu nod, cu momentul fiecărei mutații din înregistrările observatorului.

'use strict';

// Proprietățile calculate: lista din tools/dev/capturi-publice.php (PROPRIETATI), extinsă cum cer DECIZIE §6.2 (P3-stil) și
// I-C §10, plus câteva de așezare care altfel ar scăpa (right, bottom, float, min/max, aliniamentele).
const PROPRIETATI = [
    'display', 'position', 'top', 'left', 'width', 'height',
    'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'color', 'background-color', 'background-image',
    'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'text-align', 'text-transform',
    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
    'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
    'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
    'box-shadow', 'opacity', 'visibility', 'z-index',
    'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
    'grid-template-columns', 'grid-template-rows', 'grid-template-areas', 'row-gap', 'column-gap',
    'transform',
    // DECIZIE §6.2 / I-C §10
    'letter-spacing', 'overflow-x', 'overflow-y', 'cursor', 'background-size', 'background-position', 'background-repeat',
    'object-fit', 'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay',
    'animation-name', 'animation-duration', 'animation-timing-function', 'animation-delay', 'animation-iteration-count',
    'animation-fill-mode', 'font-variant-ligatures', 'font-variant-caps', 'font-variant-numeric', 'appearance', 'white-space',
    'filter', 'clip-path',
    // de așezare și de text
    'right', 'bottom', 'float', 'clear', 'box-sizing', 'min-width', 'max-width', 'min-height', 'max-height', 'vertical-align',
    'text-decoration-line', 'text-decoration-style', 'text-decoration-color', 'text-shadow', 'text-indent', 'text-overflow',
    'word-spacing', 'word-break', 'overflow-wrap', 'list-style-type', 'list-style-position', 'object-position',
    'align-items', 'align-self', 'align-content', 'justify-content', 'order', 'pointer-events', 'mix-blend-mode',
    'outline-style', 'outline-width', 'outline-color', 'backdrop-filter', 'background-clip', 'background-attachment',
];

// Pseudo-elementele (::before, ::after), doar unde `content` nu e none/normal.
const PROPRIETATI_PSEUDO = [
    'content', 'display', 'position', 'top', 'left', 'right', 'bottom', 'width', 'height', 'color', 'background-color',
    'background-image', 'font-family', 'font-size', 'font-weight', 'line-height', 'transform', 'opacity',
    'border-top-width', 'border-top-style', 'border-top-color', 'margin-left', 'margin-right', 'padding-left', 'padding-right',
    'transition-property', 'transition-duration', 'animation-name', 'z-index', 'letter-spacing', 'text-transform',
];

// Rulează în fiecare cadru, la începutul documentului. Datele stau într-o proprietate neenumerabilă a ferestrei.
function init() {
    if (window.__axParitate) { return; }
    const d = { mo: [], ev: [], scripturi: [], csp: [], erori: [], noduri: null };
    Object.defineProperty(window, '__axParitate', { value: d, enumerable: false, configurable: false, writable: false });
    const acum = () => Math.round(performance.now() * 10) / 10;
    try {
        const mo = new MutationObserver((lista) => {
            const t = acum();
            for (const r of lista) {
                d.mo.push({
                    t, tip: r.type, tinta: r.target, atribut: r.attributeName || null, vechi: r.oldValue,
                    adaugate: r.addedNodes.length ? Array.from(r.addedNodes) : null,
                    scoase: r.removedNodes.length ? Array.from(r.removedNodes) : null,
                });
            }
        });
        mo.observe(document, { subtree: true, childList: true, attributes: true, attributeOldValue: true, characterData: true, characterDataOldValue: true });
        d.observator = mo;
    } catch (e) { d.erori.push('observator: ' + e.message); }
    document.addEventListener('DOMContentLoaded', () => d.ev.push({ ev: 'DOMContentLoaded', t: acum() }), true);
    window.addEventListener('load', () => d.ev.push({ ev: 'load', t: acum() }), true);
    document.addEventListener('readystatechange', () => d.ev.push({ ev: 'readyState:' + document.readyState, t: acum() }), true);
    document.addEventListener('load', (e) => {
        const el = e.target;
        if (el && el.tagName === 'SCRIPT') { d.scripturi.push({ t: acum(), src: el.src, id: el.id || '' }); }
        if (el && el.tagName === 'LINK') { d.scripturi.push({ t: acum(), link: el.href, id: el.id || '', rel: el.rel }); }
    }, true);
    document.addEventListener('securitypolicyviolation', (e) => {
        d.csp.push({ t: acum(), uri: e.blockedURI, directiva: e.violatedDirective, efectiva: e.effectiveDirective,
            sursa: e.sourceFile, linia: e.lineNumber, mostra: e.sample, dispozitie: e.disposition, document: location.href });
    }, true);
    window.addEventListener('error', (e) => { d.erori.push(String((e && e.message) || 'eroare').slice(0, 300)); }, true);
}

// ---- funcțiile de mai jos rulează prin page.evaluate (fiecare își are ajutoarele înăuntru) ----

function instantaneu(o) {
    const PROP = o.proprietati;
    const PSEUDO = o.pseudo;
    const rotunjeste = (v) => String(v)
        .replace(/(-?\d*\.?\d+)px/g, (_, n) => Math.round(parseFloat(n)) + 'px')
        .replace(/(-?\d+\.\d{4,})/g, (_, n) => String(Math.round(parseFloat(n) * 1000) / 1000));
    const cheie = (el) => {
        const parti = [];
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
            let i = 1;
            for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
            parti.unshift(e.tagName.toLowerCase() + ':' + i);
        }
        return parti.join('>');
    };
    const atribute = (el) => {
        const r = {};
        for (const a of Array.from(el.attributes)) { r[a.name] = a.value; }
        return r;
    };
    const rez = {};
    const de = document.documentElement;
    rez.doc = {
        url: location.href, titlu: document.title, readyState: document.readyState,
        latime: Math.max(de.scrollWidth, document.body ? document.body.scrollWidth : 0),
        inaltime: Math.max(de.scrollHeight, document.body ? document.body.scrollHeight : 0),
        fereastra: [innerWidth, innerHeight], dpr: devicePixelRatio, scroll: [scrollX, scrollY],
        html: atribute(de), body: document.body ? atribute(document.body) : null,
        doctype: document.doctype ? '<!DOCTYPE ' + document.doctype.name + (document.doctype.publicId ? ' PUBLIC "' + document.doctype.publicId + '"' : '') + '>' : '',
        compat: document.compatMode,
    };
    // DOM-ul după scripturi, serializat HTML.
    if (o.dom) { rez.dom = rez.doc.doctype + de.outerHTML; }
    // Head-ul: fiecare copil, cu atributele; titlul, textul stilurilor (prin lungime, sha-ul îl face Node) și JSON-LD citit.
    rez.head = Array.from(document.head ? document.head.children : []).map((el) => {
        const t = el.tagName.toLowerCase();
        const x = { tag: t, atribute: atribute(el) };
        if (t === 'title') { x.text = el.textContent; }
        if (t === 'style') { x.text = el.textContent; }
        if (t === 'script') {
            x.text = el.textContent;
            if (/ld\+json/i.test(el.type)) { try { x.json = JSON.parse(el.textContent); } catch (e) { x.json = '(JSON invalid)'; } }
        }
        if (t === 'link') { x.hrefRezolvat = el.href; }
        return x;
    });
    const toate = [de].concat(Array.from(de.querySelectorAll('*')));
    const vizibil = (el) => el === de || el.getClientRects().length > 0;
    // Linkurile.
    rez.linkuri = Array.from(document.querySelectorAll('a[href], area[href]')).map((a) => ({
        k: cheie(a), href: a.href, atribut: a.getAttribute('href'), target: a.getAttribute('target'), rel: a.getAttribute('rel'),
        text: a.textContent, vizibil: vizibil(a),
    }));
    // Imaginile.
    rez.imagini = Array.from(document.images).map((img) => {
        const r = img.getBoundingClientRect();
        return {
            k: cheie(img), currentSrc: img.currentSrc, src: img.getAttribute('src'), srcset: img.getAttribute('srcset'),
            sizes: img.getAttribute('sizes'), natural: [img.naturalWidth, img.naturalHeight], alt: img.getAttribute('alt'),
            title: img.getAttribute('title'), loading: img.getAttribute('loading'), decoding: img.getAttribute('decoding'),
            complet: img.complete, cutie: [Math.round(r.x + scrollX), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)],
        };
    });
    // Textul: pe fiecare element, textul nodurilor-text proprii (punctele de cod exacte) și, la frunze vizibile, innerText.
    if (o.text) {
        rez.text = [];
        for (const el of toate) {
            if (el.closest('head') || el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'NOSCRIPT' || el.tagName === 'TEMPLATE') { continue; }
            let propriu = '';
            for (const n of el.childNodes) { if (n.nodeType === 3) { propriu += n.data; } }
            const frunza = el.children.length === 0 && vizibil(el);
            if (propriu.trim() === '' && !frunza) { continue; }
            const x = { k: cheie(el) };
            if (propriu.trim() !== '') { x.t = propriu; }
            if (frunza) { x.i = el.innerText; }
            rez.text.push(x);
        }
        rez.textPagina = document.body ? document.body.innerText : '';
    }
    // Stilurile calculate și geometria elementelor vizibile; valorile într-un tabel (indici), ca fișierul să rămână mic.
    if (o.stiluri) {
        const tabel = [];
        const indici = new Map();
        const idx = (v) => {
            let i = indici.get(v);
            if (i === undefined) { i = tabel.length; tabel.push(v); indici.set(v, i); }
            return i;
        };
        const elemente = [];
        for (const el of toate) {
            if (el !== de && el.closest('head')) { continue; }
            if (!vizibil(el)) { continue; }
            const cs = getComputedStyle(el);
            const v = PROP.map((p) => idx(rotunjeste(cs.getPropertyValue(p))));
            const r = el.getBoundingClientRect();
            const x = { k: cheie(el), v, g: [Math.round((r.x + scrollX) * 10) / 10, Math.round((r.y + scrollY) * 10) / 10, Math.round(r.width * 10) / 10, Math.round(r.height * 10) / 10] };
            for (const ps of ['::before', '::after']) {
                const c = getComputedStyle(el, ps);
                const cont = c.getPropertyValue('content');
                if (cont && cont !== 'none' && cont !== 'normal') {
                    x[ps === '::before' ? 'b' : 'a'] = PSEUDO.map((p) => idx(rotunjeste(c.getPropertyValue(p))));
                }
            }
            elemente.push(x);
        }
        rez.stiluri = { proprietati: PROP, pseudo: PSEUDO, tabel, elemente };
    }
    // CSSOM-ul: foile în ordinea documentului, cu regulile (și cele din @media/@supports, cu contextul), adresele rezolvate.
    if (o.cssom) {
        const rezolva = (text, baza) => text.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (m, q, u) => {
            if (/^(data:|#)/i.test(u)) { return m; }
            try { return 'url("' + new URL(u, baza).href + '")'; } catch (e) { return m; }
        });
        const foi = [];
        const reguli = (lista, baza, context, iesire) => {
            for (const r of Array.from(lista)) {
                if (r.cssRules && (r.media || r.conditionText !== undefined || r.type === 12 || r.type === 4)) {
                    const cond = r.media ? '@media ' + r.media.mediaText : (r.conditionText !== undefined ? '@' + (r.constructor.name.replace(/^CSS|Rule$/g, '').toLowerCase()) + ' ' + r.conditionText : '');
                    reguli(r.cssRules, baza, context.concat([cond]), iesire);
                    continue;
                }
                if (r.styleSheet) {
                    iesire.push({ c: context, r: '@import ' + r.href });
                    try { reguli(r.styleSheet.cssRules, r.styleSheet.href || baza, context.concat(['@import ' + r.href]), iesire); } catch (e) { iesire.push({ c: context, r: '(import inaccesibil) ' + r.href }); }
                    continue;
                }
                iesire.push(context.length ? { c: context, r: rezolva(r.cssText, baza) } : { r: rezolva(r.cssText, baza) });
            }
        };
        for (const s of Array.from(document.styleSheets)) {
            const nod = s.ownerNode;
            const f = {
                nod: nod ? (nod.tagName.toLowerCase() + (nod.id ? '#' + nod.id : '')) : '',
                href: s.href, media: s.media ? s.media.mediaText : '', dezactivata: s.disabled,
                rel: nod && nod.getAttribute ? nod.getAttribute('rel') : null,
            };
            try {
                const lista = [];
                reguli(s.cssRules, s.href || document.baseURI, [], lista);
                f.reguli = lista;
            } catch (e) {
                f.inaccesibila = true;
            }
            foi.push(f);
        }
        rez.cssom = foi;
    }
    // Momentele navigării și desenarea.
    const nav = performance.getEntriesByType('navigation')[0];
    rez.timp = {
        nav: nav ? { responseStart: nav.responseStart, domInteractive: nav.domInteractive, dclStart: nav.domContentLoadedEventStart,
            dclEnd: nav.domContentLoadedEventEnd, loadStart: nav.loadEventStart, loadEnd: nav.loadEventEnd } : null,
        desenare: performance.getEntriesByType('paint').map((p) => ({ nume: p.name, t: Math.round(p.startTime * 10) / 10 })),
        acum: performance.now(),
    };
    const ax = window.__axParitate;
    rez.csp = ax ? ax.csp.slice() : [];
    rez.erori = ax ? ax.erori.slice() : [];
    rez.evenimente = ax ? ax.ev.slice() : [];
    rez.scripturi = ax ? ax.scripturi.slice() : [];
    rez.nrMutatii = ax ? ax.mo.length : 0;
    return rez;
}

// Inventarul mutațiilor: DOM-ul după scripturi față de HTML-ul static, cu momentul fiecărei mutații.
function diferente(o) {
    const html = o.html;
    const ax = window.__axParitate;
    const cheie = (el) => {
        const parti = [];
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
            let i = 1;
            for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
            parti.unshift(e.tagName.toLowerCase() + ':' + i);
        }
        return parti.join('>');
    };
    const semn = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.getAttribute('class') ? '.' + el.getAttribute('class').trim().split(/\s+/).sort().join('.') : '');
    const clase = (el) => new Set((el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean));
    const declaratii = (s) => {
        const r = new Map();
        for (const parte of String(s || '').split(';')) {
            const i = parte.indexOf(':');
            if (i > 0) { r.set(parte.slice(0, i).trim().toLowerCase(), parte.slice(i + 1).trim()); }
        }
        return r;
    };
    const scurt = (s, n) => (s.length > n ? s.slice(0, n) + '…' : s);
    // Înregistrările observatorului, pe nod.
    const peNod = new Map();
    const pune = (n, i) => { if (!peNod.has(n)) { peNod.set(n, []); } peNod.get(n).push(i); };
    const mo = ax ? ax.mo : [];
    mo.forEach((r, i) => {
        if (r.tip === 'attributes' || r.tip === 'characterData') { pune(r.tinta, i); }
        if (r.adaugate) { for (const n of r.adaugate) { pune(n, i); } }
    });
    const momente = (n, filtru) => {
        const l = (peNod.get(n) || []).map((i) => mo[i]).filter(filtru);
        return l.length ? { prima: l[0].t, ultima: l[l.length - 1].t, ori: l.length } : null;
    };
    const stat = new DOMParser().parseFromString(html, 'text/html');
    const iesire = [];
    // Elementele fără clase și fără id (scripturi, foi, meta) se potrivesc după atributul care le numește; `rel` nu intră
    // (preload → stylesheet e chiar o mutație de găsit).
    const numeste = (el) => {
        switch (el.tagName) {
            case 'SCRIPT': return (el.getAttribute('src') || '') + '|' + (el.getAttribute('src') ? '' : (el.textContent || '').trim().slice(0, 120));
            case 'LINK': return el.getAttribute('href') || '';
            case 'META': return (el.getAttribute('name') || el.getAttribute('property') || el.getAttribute('http-equiv') || (el.hasAttribute('charset') ? 'charset' : '')) + '|' + (el.getAttribute('itemprop') || '');
            case 'STYLE': return (el.textContent || '').trim().slice(0, 120);
            default: return null;
        }
    };
    const potrivire = (a, b) => {
        if (a.tagName !== b.tagName) { return false; }
        const ia = a.getAttribute('id') || '';
        const ib = b.getAttribute('id') || '';
        if (ia !== ib) { return false; }
        const na = numeste(a);
        if (na !== null && ia === '' && na !== numeste(b)) { return false; }
        const ca = clase(a);
        const cb = clase(b);
        if (ca.size === 0 && cb.size === 0) { return true; }
        if (ca.size === 0 || cb.size === 0) { return ia !== '' || a.tagName === 'BODY' || a.tagName === 'HTML'; }
        let comune = 0;
        for (const c of ca) { if (cb.has(c)) { comune++; } }
        return comune > 0 && comune >= Math.min(ca.size, cb.size) * 0.5;
    };
    const aliniaza = (la, lb) => {
        const n = la.length;
        const m = lb.length;
        if (n * m > 4000000) {
            // Liste uriașe: potrivire lacomă, în ordine.
            const perechi = [];
            let j = 0;
            for (let i = 0; i < n && j < m; i++) {
                let k = j;
                while (k < m && !potrivire(la[i], lb[k])) { k++; }
                if (k < m) { perechi.push([i, k]); j = k + 1; }
            }
            return perechi;
        }
        const dp = Array.from({ length: n + 1 }, () => new Uint32Array(m + 1));
        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                dp[i][j] = potrivire(la[i], lb[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }
        const perechi = [];
        let i = 0;
        let j = 0;
        while (i < n && j < m) {
            if (potrivire(la[i], lb[j]) && dp[i][j] === dp[i + 1][j + 1] + 1) { perechi.push([i, j]); i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) { i++; } else { j++; }
        }
        return perechi;
    };
    const textPropriu = (el) => {
        let t = '';
        for (const n of el.childNodes) { if (n.nodeType === 3) { t += n.data; } }
        return t;
    };
    const compara = (a, b) => {
        const k = cheie(b);
        // atributele
        const nume = new Set([...Array.from(a.attributes).map((x) => x.name), ...Array.from(b.attributes).map((x) => x.name)]);
        for (const n of nume) {
            const va = a.getAttribute(n);
            const vb = b.getAttribute(n);
            if (va === vb) { continue; }
            const t = momente(b, (r) => r.tip === 'attributes' && r.atribut === n);
            if (n === 'class') {
                const ca = clase(a);
                const cb = clase(b);
                const plus = [...cb].filter((c) => !ca.has(c));
                const minus = [...ca].filter((c) => !cb.has(c));
                if (plus.length || minus.length) { iesire.push({ tip: 'clase', k, semn: semn(a), plus, minus, timp: t }); } else { iesire.push({ tip: 'ordine-clase', k, semn: semn(a), timp: t }); }
            } else if (n === 'style') {
                const da = declaratii(va);
                const db = declaratii(vb);
                const sch = {};
                for (const [p, v] of db) { if (da.get(p) !== v) { sch[p] = v; } }
                const scoase = [...da.keys()].filter((p) => !db.has(p));
                iesire.push({ tip: 'stil', k, semn: semn(a), inainte: va, dupa: vb, schimbate: sch, scoase, timp: t });
            } else {
                iesire.push({ tip: 'atribut', k, semn: semn(a), atribut: n, inainte: va === null ? null : scurt(va, 400), dupa: vb === null ? null : scurt(vb, 400), timp: t });
            }
        }
        // textul propriu
        if (!['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'TEXTAREA'].includes(b.tagName)) {
            const ta = textPropriu(a);
            const tb = textPropriu(b);
            if (ta !== tb) {
                iesire.push({ tip: 'text', k, semn: semn(a), inainte: scurt(ta, 300), dupa: scurt(tb, 300), timp: momente(b, (r) => r.tip === 'characterData' || r.tip === 'childList') });
            }
        }
        if (b.tagName === 'NOSCRIPT' || a.tagName === 'NOSCRIPT' || b.tagName === 'TEMPLATE') { return; }
        const la = Array.from(a.children);
        const lb = Array.from(b.children);
        const perechi = aliniaza(la, lb);
        const vazuteA = new Set(perechi.map((p) => p[0]));
        const vazuteB = new Set(perechi.map((p) => p[1]));
        la.forEach((x, i) => {
            if (!vazuteA.has(i)) { iesire.push({ tip: 'nod-scos', parinte: k, semn: semn(x), pozitie: i, html: scurt(x.outerHTML, 600) }); }
        });
        lb.forEach((x, j) => {
            if (!vazuteB.has(j)) {
                const t = momente(x, (r) => r.tip === 'childList');
                iesire.push({ tip: 'nod-adaugat', parinte: k, k: cheie(x), semn: semn(x), pozitie: j, html: scurt(x.outerHTML, 600), noduri: 1 + x.querySelectorAll('*').length, timp: t });
            }
        });
        for (const [i, j] of perechi) { compara(la[i], lb[j]); }
    };
    compara(stat.documentElement, document.documentElement);
    // Perechile scos/adăugat cu aceeași semnătură și același text: noduri mutate.
    const scoase = iesire.filter((x) => x.tip === 'nod-scos');
    for (const ad of iesire.filter((x) => x.tip === 'nod-adaugat')) {
        const s = scoase.find((x) => !x.mutat && x.semn === ad.semn);
        if (s) { s.mutat = true; ad.mutatDin = s.parinte; ad.tip = 'nod-mutat'; }
    }
    const evenimente = ax ? ax.ev.slice() : [];
    return { mutatii: iesire.filter((x) => !(x.tip === 'nod-scos' && x.mutat)), evenimente, scripturi: ax ? ax.scripturi.slice() : [], inregistrari: mo.length };
}

// Linia de timp brută (OBS-01, OBS-02): fiecare înregistrare a observatorului, cu cheia nodului la final (sau „(scos)”).
function linieTimp(o) {
    const ax = window.__axParitate;
    if (!ax) { return null; }
    const cheie = (el) => {
        if (!el || el.nodeType !== 1 || !el.isConnected) { return null; }
        const parti = [];
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
            let i = 1;
            for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
            parti.unshift(e.tagName.toLowerCase() + ':' + i);
        }
        return parti.join('>');
    };
    const semn = (n) => (n && n.nodeType === 1 ? n.tagName.toLowerCase() + (n.id ? '#' + n.id : '') + (n.getAttribute('class') ? '.' + n.getAttribute('class').trim().split(/\s+/).join('.') : '') : (n && n.nodeType === 3 ? '#text' : String(n && n.nodeName)));
    const filtru = o && o.filtru ? new RegExp(o.filtru) : null;
    const rez = [];
    for (const r of ax.mo) {
        if (o && o.faraParsare && r.tip === 'childList' && r.t <= (o.dcl || 0) && !r.scoase) { continue; }
        const x = { t: r.t, tip: r.tip, tinta: semn(r.tinta), k: cheie(r.tinta) };
        if (r.atribut) { x.atribut = r.atribut; x.vechi = r.vechi === null ? null : String(r.vechi).slice(0, 300); x.nou = r.tinta.getAttribute ? (r.tinta.getAttribute(r.atribut) || '').slice(0, 300) : null; }
        if (r.adaugate) { x.adaugate = r.adaugate.map(semn); }
        if (r.scoase) { x.scoase = r.scoase.map(semn); }
        if (filtru && !filtru.test(JSON.stringify(x))) { continue; }
        rez.push(x);
    }
    return { mo: rez, ev: ax.ev.slice(), scripturi: ax.scripturi.slice() };
}

module.exports = { PROPRIETATI, PROPRIETATI_PSEUDO, init, instantaneu, diferente, linieTimp };
