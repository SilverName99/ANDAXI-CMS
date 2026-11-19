// Funcțiile din pagină ale comparației (compara.cjs), rulate într-o pagină goală, fără rețea și fără scripturi de pagină:
// documentele capturate (DOM-ul după scripturi al referinței și al candidatului) se parsează cu DOMParser, deci nimic din
// ele nu rulează și nu se încarcă.
//
//  - rezolvaMasti: selectorii măștilor → cheile elementelor prinse (drumul de etichete, ca în sonde-pagina.cjs);
//  - comparaDom: cei doi arbori, aliniați nod cu nod (aceeași potrivire ca inventarul mutațiilor), după normalizările
//    declarate (elemente și atribute ignorate, clasele ca mulțimi, stilul gol = absent, înlocuirile pe valori).

'use strict';

function rezolvaMasti(o) {
    const doc = new DOMParser().parseFromString(o.html, 'text/html');
    const cheie = (el) => {
        const parti = [];
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
            let i = 1;
            for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
            parti.unshift(e.tagName.toLowerCase() + ':' + i);
        }
        return parti.join('>');
    };
    const rez = {};
    for (const [nume, selector] of Object.entries(o.selectori)) {
        try {
            rez[nume] = Array.from(doc.querySelectorAll(selector)).map(cheie);
        } catch (e) {
            rez[nume] = { eroare: 'selector invalid: ' + selector };
        }
    }
    return rez;
}

function comparaDom(o) {
    const n = o.normalizari || {};
    const ignoraAtribute = (n.ignora_atribute || []).map((x) => new RegExp(x));
    const inlocuiri = (o.inlocuiri || []).map((x) => ({ re: new RegExp(x.re, 'g'), cu: x.cu }));
    const atributeUrl = new Set(['href', 'src', 'action', 'poster', 'data-src', 'data-mfp-src', 'data-placeholder-image', 'cite', 'formaction']);
    const pregateste = (html, urlDoc, origine) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        for (const sel of (n.ignora_elemente || [])) {
            try { doc.querySelectorAll(sel).forEach((e) => e.remove()); } catch (e) { /* selectorul greșit apare în raport prin rezolvaMasti */ }
        }
        // clasele și atributele ignorate doar pe anumite elemente (de exemplu clasele de sistem puse de server după UA)
        for (const r of (n.ignora_clase || [])) {
            try { doc.querySelectorAll(r.selector).forEach((e) => { for (const c of r.clase) { e.classList.remove(c); } }); } catch (e) { /* idem */ }
        }
        for (const r of (n.ignora_atribute_selector || [])) {
            try { doc.querySelectorAll(r.selector).forEach((e) => { for (const a of r.atribute) { e.removeAttribute(a); } }); } catch (e) { /* idem */ }
        }
        // măștile: un selector golește elementul (conținutul și atributele nu se mai compară); o mască pe atribute
        // ({selector, atribute, clase}) scoate doar atributele sau clasele date de pe elementele prinse
        for (const m of (o.masti || [])) {
            const sel = typeof m === 'string' ? m : m.selector;
            const peAtribute = typeof m === 'object' && m && ((m.atribute && m.atribute.length) || (m.clase && m.clase.length));
            try {
                doc.querySelectorAll(sel).forEach((e) => {
                    if (peAtribute) {
                        for (const a of m.atribute || []) { e.removeAttribute(a); }
                        for (const c of m.clase || []) { e.classList.remove(c); }
                        if (e.getAttribute('class') === '') { e.removeAttribute('class'); }
                        return;
                    }
                    e.setAttribute('data-paritate-mascat', ''); while (e.firstChild) { e.removeChild(e.firstChild); } Array.from(e.attributes).forEach((a) => { if (a.name !== 'data-paritate-mascat') { e.removeAttribute(a.name); } });
                });
            } catch (e) { /* idem */ }
        }
        return { doc, urlDoc, origine };
    };
    const valoare = (ctx, el, nume, v) => {
        let x = v;
        if (atributeUrl.has(nume) && x !== '' && !/^(#|javascript:|mailto:|tel:|data:)/i.test(x.trim())) {
            try { x = new URL(x.trim(), ctx.urlDoc).href; } catch (e) { /* rămâne textul */ }
        }
        if (nume === 'srcset') {
            x = x.split(',').map((p) => {
                const t = p.trim().split(/\s+/);
                try { t[0] = new URL(t[0], ctx.urlDoc).href; } catch (e) { /* idem */ }
                return t.join(' ');
            }).join(', ');
        }
        x = x.split(ctx.origine).join('{{origine}}').split(ctx.origine.replace(/\//g, '\\/')).join('{{origine}}');
        // adresele proprii absolute și cele relative se compară la fel (DECIZIE §6.7 #2)
        x = x.replace(/\{\{origine\}\}(?=\\?\/)/g, '');
        for (const r of inlocuiri) { x = x.replace(r.re, r.cu); }
        return x;
    };
    const clase = (el) => (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean);
    const atribute = (ctx, el) => {
        const r = {};
        for (const a of Array.from(el.attributes)) {
            if (ignoraAtribute.some((re) => re.test(a.name))) { continue; }
            if (a.name === 'class') {
                const c = clase(el);
                if (c.length === 0) { continue; }
                r.class = n.clase_multimi === false ? c.join(' ') : c.slice().sort().join(' ');
                continue;
            }
            if (a.name === 'style' && n.stil_gol_absent !== false && a.value.trim() === '') { continue; }
            r[a.name] = valoare(ctx, el, a.name, a.value);
        }
        return r;
    };
    const cheie = (el) => {
        const parti = [];
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
            let i = 1;
            for (let f = e.previousElementSibling; f; f = f.previousElementSibling) { if (f.tagName === e.tagName) { i++; } }
            parti.unshift(e.tagName.toLowerCase() + ':' + i);
        }
        return parti.join('>');
    };
    const textPropriu = (ctx, el) => {
        let t = '';
        for (const c of el.childNodes) { if (c.nodeType === 3) { t += c.data; } }
        if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') { t = valoare(ctx, el, '#text', t); }
        return t;
    };
    const numeste = (ctx, el) => {
        switch (el.tagName) {
            case 'SCRIPT': return (el.getAttribute('src') ? valoare(ctx, el, 'src', el.getAttribute('src')) : '') + '|' + (el.getAttribute('src') ? '' : valoare(ctx, el, '#text', (el.textContent || '').trim().slice(0, 120)));
            case 'LINK': return valoare(ctx, el, 'href', el.getAttribute('href') || '');
            case 'META': return (el.getAttribute('name') || el.getAttribute('property') || el.getAttribute('http-equiv') || (el.hasAttribute('charset') ? 'charset' : '')) + '|' + (el.getAttribute('itemprop') || '');
            case 'STYLE': return (el.textContent || '').trim().slice(0, 120);
            default: return null;
        }
    };
    const potrivire = (a, b) => {
        if (a.tagName !== b.tagName) { return false; }
        if ((a.getAttribute('id') || '') !== (b.getAttribute('id') || '')) { return false; }
        const na = numeste(A, a);
        if (na !== null && !(a.getAttribute('id')) && na !== numeste(B, b)) { return false; }
        const ca = new Set(clase(a));
        const cb = new Set(clase(b));
        if (ca.size === 0 && cb.size === 0) { return true; }
        if (ca.size === 0 || cb.size === 0) { return (a.getAttribute('id') || '') !== '' || ['BODY', 'HTML', 'HEAD'].includes(a.tagName); }
        let comune = 0;
        for (const c of ca) { if (cb.has(c)) { comune++; } }
        return comune > 0 && comune >= Math.min(ca.size, cb.size) * 0.5;
    };
    const aliniaza = (la, lb) => {
        const nn = la.length;
        const m = lb.length;
        if (nn * m > 4000000) {
            const perechi = [];
            let j = 0;
            for (let i = 0; i < nn && j < m; i++) {
                let k = j;
                while (k < m && !potrivire(la[i], lb[k])) { k++; }
                if (k < m) { perechi.push([i, k]); j = k + 1; }
            }
            return perechi;
        }
        const dp = Array.from({ length: nn + 1 }, () => new Uint32Array(m + 1));
        for (let i = nn - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                dp[i][j] = potrivire(la[i], lb[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }
        const perechi = [];
        let i = 0;
        let j = 0;
        while (i < nn && j < m) {
            if (potrivire(la[i], lb[j]) && dp[i][j] === dp[i + 1][j + 1] + 1) { perechi.push([i, j]); i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) { i++; } else { j++; }
        }
        return perechi;
    };
    const scurt = (s, k) => (s && s.length > k ? s.slice(0, k) + '…' : s);
    const A = pregateste(o.referinta, o.urlReferinta, o.origineReferinta);
    const B = pregateste(o.candidat, o.urlCandidat, o.origineCandidat);
    const dif = [];
    const LIMITA = o.limita || 2000;
    let total = 0;
    const pune = (x) => { total++; if (dif.length < LIMITA) { dif.push(x); } };
    const compara = (a, b) => {
        const k = cheie(b);
        const ra = atribute(A, a);
        const rb = atribute(B, b);
        for (const nume of new Set([...Object.keys(ra), ...Object.keys(rb)])) {
            if (ra[nume] === rb[nume]) { continue; }
            if (nume === 'class') {
                const sa = new Set((ra.class || '').split(' ').filter(Boolean));
                const sb = new Set((rb.class || '').split(' ').filter(Boolean));
                pune({ tip: 'clase', k, referinta_plus: [...sa].filter((c) => !sb.has(c)), candidat_plus: [...sb].filter((c) => !sa.has(c)) });
            } else {
                pune({ tip: 'atribut', k, atribut: nume, referinta: ra[nume] === undefined ? null : scurt(ra[nume], 300), candidat: rb[nume] === undefined ? null : scurt(rb[nume], 300) });
            }
        }
        if (a.tagName !== 'TEMPLATE') {
            const ta = textPropriu(A, a);
            const tb = textPropriu(B, b);
            if (ta !== tb) { pune({ tip: 'text', k, referinta: scurt(ta, 300), candidat: scurt(tb, 300) }); }
        }
        if (a.hasAttribute('data-paritate-mascat')) { return; }
        const la = Array.from(a.children);
        const lb = Array.from(b.children);
        const perechi = aliniaza(la, lb);
        const va = new Set(perechi.map((p) => p[0]));
        const vb = new Set(perechi.map((p) => p[1]));
        la.forEach((x, i) => { if (!va.has(i)) { pune({ tip: 'nod-doar-in-referinta', parinte: k, k: cheie(x), html: scurt(x.outerHTML, 400) }); } });
        lb.forEach((x, j) => { if (!vb.has(j)) { pune({ tip: 'nod-doar-in-candidat', parinte: k, k: cheie(x), html: scurt(x.outerHTML, 400) }); } });
        for (const [i, j] of perechi) { compara(la[i], lb[j]); }
    };
    compara(A.doc.documentElement, B.doc.documentElement);
    const dtA = A.doc.doctype ? A.doc.doctype.name : '';
    const dtB = B.doc.doctype ? B.doc.doctype.name : '';
    if (dtA !== dtB) { pune({ tip: 'doctype', referinta: dtA, candidat: dtB }); }
    return { total, diferente: dif };
}

module.exports = { rezolvaMasti, comparaDom };
