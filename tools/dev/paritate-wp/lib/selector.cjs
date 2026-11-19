// Selectorii unici ai elementelor, pentru măștile propuse de zgomot.cjs și pentru regiunile punctelor: doar id-uri, clase,
// etichete, spații și „>” (fără :nth-child), ca să rămână lizibili și să se poată verifica pe ambele părți.
// Funcția rulează în pagină (page.evaluate): primește HTML-ul DOM-ului după scripturi și cheile elementelor (drumul de etichete
// „html:1>body:1>div:2…”, ca în sonde-pagina.cjs și arbori.cjs) și întoarce, pe cheie, selectorul și câte elemente prinde.
// Folosește doar id-urile și clasele care există în HTML-ul static al paginii (cele puse de server, nu de scripturi: un
// selector pe o clasă adăugată de un script n-ar prinde nimic pe un candidat care o pune altfel), fără clasele de stare.
//   o = { html, htmlStatic, chei: [...], cheiFaraIgnorate: [...] (cheile sondei mutatii: după scoaterea elementelor
//         ignorate), ignora: [...] }

'use strict';

function selectoriPentruChei(o) {
    const STARE = /^(swiper-|slick-|is-|has-|js-|active$|current|visible|hidden$|hover|focus|open$|opened|loaded|lazy|animated|et_animated|et-animated|et-waypoint|et_pb_animation_|et-fb|cmplz-)/i;
    const valid = (c) => /^-?[_a-zA-Z][_a-zA-Z0-9-]*$/.test(c);
    const statice = { clase: null, iduri: null };
    if (o.htmlStatic) {
        const ds = new DOMParser().parseFromString(o.htmlStatic, 'text/html');
        statice.clase = new Set();
        statice.iduri = new Set();
        for (const e of Array.from(ds.querySelectorAll('[class], [id]'))) {
            for (const c of Array.from(e.classList)) { statice.clase.add(c); }
            if (e.id) { statice.iduri.add(e.id); }
        }
    }
    const pregateste = (ignora) => {
        const doc = new DOMParser().parseFromString(o.html, 'text/html');
        for (const s of ignora || []) {
            try { doc.querySelectorAll(s).forEach((e) => e.remove()); } catch (e) { /* selectorul greșit apare în rezolvaMasti */ }
        }
        const h = new Map();
        const umbla = (el, cale) => {
            h.set(cale, el);
            const n = {};
            for (const c of Array.from(el.children)) {
                const t = c.tagName.toLowerCase();
                n[t] = (n[t] || 0) + 1;
                umbla(c, cale + '>' + t + ':' + n[t]);
            }
        };
        umbla(doc.documentElement, 'html:1');
        return { doc, h };
    };
    const numar = (doc, s) => { try { return doc.querySelectorAll(s).length; } catch (e) { return 0; } };
    const unic = (doc, s, el) => { try { const l = doc.querySelectorAll(s); return l.length === 1 && l[0] === el; } catch (e) { return false; } };
    // clasele de folosit: întâi cele de ordine (et_pb_row_3, et_pb_text_2_tb_footer), apoi celelalte; fără clasele de stare
    const clase = (el) => {
        const c = Array.from(el.classList).filter(valid).filter((x) => !STARE.test(x) && (!statice.clase || statice.clase.has(x)));
        const ord = c.filter((x) => /_\d+(_tb_(header|footer|body))?$/.test(x));
        return [...ord, ...c.filter((x) => !ord.includes(x))];
    };
    const idUnic = (doc, el) => (el.id && valid(el.id) && (!statice.iduri || statice.iduri.has(el.id)) && numar(doc, '#' + el.id) === 1 ? '#' + el.id : null);
    // un pas al lanțului: id-ul unic, altfel eticheta cu prima clasă (scurt) sau cu toate clasele (lung)
    const pas = (doc, el, lung) => {
        const i = idUnic(doc, el);
        if (i) { return i; }
        const c = clase(el);
        return el.tagName.toLowerCase() + (lung ? c : c.slice(0, 1)).map((x) => '.' + x).join('');
    };
    const ancora = (doc, el) => {
        const i = idUnic(doc, el);
        if (i) { return i; }
        for (const c of clase(el)) { if (numar(doc, '.' + c) === 1) { return '.' + c; } }
        return null;
    };
    const selectorUnic = (doc, el) => {
        if (el.tagName === 'BODY' || el.tagName === 'HTML') { return { selector: el.tagName.toLowerCase(), prinde: 1 }; }
        const a = ancora(doc, el);
        if (a) { return { selector: a, prinde: 1 }; }
        const t = el.tagName.toLowerCase();
        for (const c of clase(el)) { if (unic(doc, t + '.' + c, el)) { return { selector: t + '.' + c, prinde: 1 }; } }
        for (const lung of [false, true]) {
            const pe = pas(doc, el, lung);
            if (unic(doc, pe, el)) { return { selector: pe, prinde: 1 }; }
        }
        // strămoșul cel mai apropiat cu ancoră, apoi lanțul cu „>” (pași scurți, apoi lungi) sau cu spațiu
        const scurt = [pas(doc, el, false)];
        const lung = [pas(doc, el, true)];
        for (let p = el.parentElement; p && p.tagName !== 'HTML'; p = p.parentElement) {
            const ap = ancora(doc, p);
            if (ap) {
                for (const s of [ap + ' > ' + scurt.join(' > '), ap + ' > ' + lung.join(' > '), ap + ' ' + scurt[scurt.length - 1], ap + ' ' + lung[lung.length - 1]]) {
                    if (unic(doc, s, el)) { return { selector: s, prinde: 1 }; }
                }
            }
            scurt.unshift(pas(doc, p, false));
            lung.unshift(pas(doc, p, true));
        }
        const s = 'html > ' + lung.join(' > ');
        return { selector: s, prinde: numar(doc, s) };
    };
    const rez = {};
    const fa = (chei, ignora, eticheta) => {
        if (!chei || !chei.length) { return; }
        const { doc, h } = pregateste(ignora);
        for (const k of chei) {
            const el = h.get(k);
            if (!el) { rez[eticheta + k] = null; continue; }
            const s = selectorUnic(doc, el);
            rez[eticheta + k] = { ...s, tag: el.tagName.toLowerCase(), id: el.id || null, clase: (el.getAttribute('class') || '').trim() };
        }
    };
    fa(o.chei, [], '');
    fa(o.cheiFaraIgnorate, o.ignora || [], 'm:');
    return rez;
}

module.exports = { selectoriPentruChei };
