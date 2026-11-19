// PNG-ul capturilor și comparația pe pixeli a harness-ului de paritate (P3-pixeli, DECIZIE §6.2: prag 0,1), fără dependențe:
// decodarea și codarea PNG cu zlib, diferența perceptuală în spațiul YIQ (Kotsarenko și Ramos, „Measuring perceived color
// difference using YIQ NTSC transmission color space in mobile applications”, 2010) cu pragul pe pătratul distanței, ca
// pixelmatch, și detecția netezirii marginilor (anti-aliasing) după vecinii pixelului. Scrisă de noi; niciun cod copiat.
//
// Măștile sunt dreptunghiuri în coordonatele paginii [x, y, lățime, înălțime]; pixelii din ele nu se compară.

'use strict';

const zlib = require('zlib');

const SEMNATURA = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

function decodeaza(buf) {
    if (!buf.subarray(0, 8).equals(SEMNATURA)) { throw new Error('nu e PNG'); }
    let p = 8;
    let latime = 0;
    let inaltime = 0;
    let adancime = 0;
    let tipCuloare = 0;
    let intretesut = 0;
    const idat = [];
    let paleta = null;
    let transparenta = null;
    while (p < buf.length) {
        const lung = buf.readUInt32BE(p);
        const tip = buf.toString('ascii', p + 4, p + 8);
        const date = buf.subarray(p + 8, p + 8 + lung);
        if (tip === 'IHDR') {
            latime = date.readUInt32BE(0);
            inaltime = date.readUInt32BE(4);
            adancime = date[8];
            tipCuloare = date[9];
            intretesut = date[12];
        } else if (tip === 'IDAT') {
            idat.push(date);
        } else if (tip === 'PLTE') {
            paleta = date;
        } else if (tip === 'tRNS') {
            transparenta = date;
        } else if (tip === 'IEND') {
            break;
        }
        p += 12 + lung;
    }
    if (adancime !== 8 || intretesut !== 0) { throw new Error('PNG neacceptat (adâncime ' + adancime + ', întrețesut ' + intretesut + ')'); }
    const canale = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[tipCuloare];
    if (!canale) { throw new Error('tip de culoare PNG neacceptat: ' + tipCuloare); }
    const brut = zlib.inflateSync(Buffer.concat(idat));
    const pas = latime * canale;
    const rgba = Buffer.alloc(latime * inaltime * 4);
    let anterior = Buffer.alloc(pas);
    let rand = Buffer.alloc(pas);
    for (let y = 0; y < inaltime; y++) {
        const o = y * (pas + 1);
        const filtru = brut[o];
        for (let i = 0; i < pas; i++) {
            const x = brut[o + 1 + i];
            const a = i >= canale ? rand[i - canale] : 0;
            const b = anterior[i];
            const c = i >= canale ? anterior[i - canale] : 0;
            let v;
            switch (filtru) {
                case 0: v = x; break;
                case 1: v = x + a; break;
                case 2: v = x + b; break;
                case 3: v = x + ((a + b) >> 1); break;
                case 4: {
                    const pp = a + b - c;
                    const pa = Math.abs(pp - a);
                    const pb = Math.abs(pp - b);
                    const pc = Math.abs(pp - c);
                    v = x + (pa <= pb && pa <= pc ? a : (pb <= pc ? b : c));
                    break;
                }
                default: throw new Error('filtru PNG necunoscut: ' + filtru);
            }
            rand[i] = v & 0xff;
        }
        for (let x = 0; x < latime; x++) {
            const d = (y * latime + x) * 4;
            const s = x * canale;
            if (tipCuloare === 6) {
                rgba[d] = rand[s]; rgba[d + 1] = rand[s + 1]; rgba[d + 2] = rand[s + 2]; rgba[d + 3] = rand[s + 3];
            } else if (tipCuloare === 2) {
                rgba[d] = rand[s]; rgba[d + 1] = rand[s + 1]; rgba[d + 2] = rand[s + 2]; rgba[d + 3] = 255;
            } else if (tipCuloare === 0) {
                rgba[d] = rgba[d + 1] = rgba[d + 2] = rand[s]; rgba[d + 3] = 255;
            } else if (tipCuloare === 4) {
                rgba[d] = rgba[d + 1] = rgba[d + 2] = rand[s]; rgba[d + 3] = rand[s + 1];
            } else {
                const k = rand[s];
                rgba[d] = paleta[k * 3]; rgba[d + 1] = paleta[k * 3 + 1]; rgba[d + 2] = paleta[k * 3 + 2];
                rgba[d + 3] = transparenta && k < transparenta.length ? transparenta[k] : 255;
            }
        }
        const t = anterior; anterior = rand; rand = t;
    }
    return { latime, inaltime, date: rgba };
}

const TABEL_CRC = (() => {
    const t = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) { c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; }
        t[n] = c;
    }
    return t;
})();

function crc32(buf) {
    let c = -1;
    for (let i = 0; i < buf.length; i++) { c = TABEL_CRC[(c ^ buf[i]) & 0xff] ^ (c >>> 8); }
    return (c ^ -1) >>> 0;
}

function bucata(tip, date) {
    const lung = Buffer.alloc(4);
    lung.writeUInt32BE(date.length);
    const tipDate = Buffer.concat([Buffer.from(tip, 'ascii'), date]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(tipDate));
    return Buffer.concat([lung, tipDate, crc]);
}

function codeaza(img) {
    const { latime, inaltime, date } = img;
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(latime, 0);
    ihdr.writeUInt32BE(inaltime, 4);
    ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
    const brut = Buffer.alloc((latime * 4 + 1) * inaltime);
    for (let y = 0; y < inaltime; y++) {
        brut[y * (latime * 4 + 1)] = 0;
        date.copy(brut, y * (latime * 4 + 1) + 1, y * latime * 4, (y + 1) * latime * 4);
    }
    return Buffer.concat([SEMNATURA, bucata('IHDR', ihdr), bucata('IDAT', zlib.deflateSync(brut, { level: 6 })), bucata('IEND', Buffer.alloc(0))]);
}

// Culoarea peste alb (alfa amestecat), apoi YIQ.
function yiq(d, i) {
    const a = d[i + 3] / 255;
    const r = 255 + (d[i] - 255) * a;
    const g = 255 + (d[i + 1] - 255) * a;
    const b = 255 + (d[i + 2] - 255) * a;
    return [r * 0.29889531 + g * 0.58662247 + b * 0.11448223, r * 0.59597799 - g * 0.27417610 - b * 0.32180189, r * 0.21147017 - g * 0.52261711 + b * 0.31114694];
}

function delta(d1, i1, d2, i2, doarLuminozitate) {
    const [y1, a1, b1] = yiq(d1, i1);
    const [y2, a2, b2] = yiq(d2, i2);
    const y = y1 - y2;
    if (doarLuminozitate) { return y; }
    const a = a1 - a2;
    const b = b1 - b2;
    const v = 0.5053 * y * y + 0.299 * a * a + 0.1957 * b * b;
    return y1 > y2 ? -v : v;
}

// Câți vecini identici are pixelul (x, y) în imaginea img (pentru detecția netezirii).
function vecini(img, x, y) {
    const { latime, inaltime, date } = img;
    const i0 = (y * latime + x) * 4;
    let n = (x === 0 || x === latime - 1 || y === 0 || y === inaltime - 1) ? 1 : 0;
    for (let yy = Math.max(y - 1, 0); yy <= Math.min(y + 1, inaltime - 1); yy++) {
        for (let xx = Math.max(x - 1, 0); xx <= Math.min(x + 1, latime - 1); xx++) {
            if (xx === x && yy === y) { continue; }
            const i = (yy * latime + xx) * 4;
            if (date[i] === date[i0] && date[i + 1] === date[i0 + 1] && date[i + 2] === date[i0 + 2] && date[i + 3] === date[i0 + 3]) { n++; }
            if (n > 2) { return n; }
        }
    }
    return n;
}

// Pixelul (x, y) din a e pe o margine netezită? (vecinul cel mai închis și cel mai deschis au destui frați în ambele imagini)
function netezit(a, x, y, b) {
    const { latime, inaltime, date } = a;
    const i0 = (y * latime + x) * 4;
    let zero = (x === 0 || x === latime - 1 || y === 0 || y === inaltime - 1) ? 1 : 0;
    let min = 0;
    let max = 0;
    let minX = 0; let minY = 0; let maxX = 0; let maxY = 0;
    for (let yy = Math.max(y - 1, 0); yy <= Math.min(y + 1, inaltime - 1); yy++) {
        for (let xx = Math.max(x - 1, 0); xx <= Math.min(x + 1, latime - 1); xx++) {
            if (xx === x && yy === y) { continue; }
            const d = delta(date, i0, date, (yy * latime + xx) * 4, true);
            if (d === 0) {
                zero++;
                if (zero > 2) { return false; }
            } else if (d < min) { min = d; minX = xx; minY = yy; } else if (d > max) { max = d; maxX = xx; maxY = yy; }
        }
    }
    if (min === 0 || max === 0) { return false; }
    const frati = (img, xx, yy) => vecini(img, xx, yy) > 2;
    return (frati(a, minX, minY) && frati(b, minX, minY)) || (frati(a, maxX, maxY) && frati(b, maxX, maxY));
}

/**
 * Compară două imagini decodate. o: { prag (0,1), masti: [[x,y,l,i]…], netezire (true: pixelii netezirii nu se numără),
 * suprapunere (true: întoarce și imaginea diferenței) }. Zona din afara suprapunerii (mărimi diferite) se numără ca diferită.
 */
function compara(a, b, o) {
    const prag = o && o.prag !== undefined ? o.prag : 0.1;
    const maxDelta = 35215 * prag * prag;
    const masti = (o && o.masti) || [];
    const latime = Math.max(a.latime, b.latime);
    const inaltime = Math.max(a.inaltime, b.inaltime);
    const masca = new Uint8Array(latime * inaltime);
    let mascati = 0;
    for (const [mx, my, ml, mi] of masti) {
        const x0 = Math.max(0, Math.floor(mx));
        const y0 = Math.max(0, Math.floor(my));
        const x1 = Math.min(latime, Math.ceil(mx + ml));
        const y1 = Math.min(inaltime, Math.ceil(my + mi));
        for (let y = y0; y < y1; y++) { masca.fill(1, y * latime + x0, y * latime + Math.max(x0, x1)); }
    }
    for (let i = 0; i < masca.length; i++) { mascati += masca[i]; }
    const sup = o && o.suprapunere ? Buffer.alloc(latime * inaltime * 4) : null;
    let diferiti = 0;
    let netezite = 0;
    const blocuri = new Map();
    const BLOC = 32;
    for (let y = 0; y < inaltime; y++) {
        for (let x = 0; x < latime; x++) {
            const p = y * latime + x;
            const inAfara = x >= a.latime || y >= a.inaltime || x >= b.latime || y >= b.inaltime;
            let stare = 0; // 0 la fel, 1 diferit, 2 netezire, 3 mască
            if (masca[p]) {
                stare = 3;
            } else if (inAfara) {
                stare = 1;
            } else {
                const ia = (y * a.latime + x) * 4;
                const ib = (y * b.latime + x) * 4;
                const da = a.date;
                const db = b.date;
                if (da[ia] !== db[ib] || da[ia + 1] !== db[ib + 1] || da[ia + 2] !== db[ib + 2] || da[ia + 3] !== db[ib + 3]) {
                    const d = delta(da, ia, db, ib, false);
                    if (Math.abs(d) > maxDelta) {
                        stare = (o && o.netezire !== false && (netezit(a, x, y, b) || netezit(b, x, y, a))) ? 2 : 1;
                    }
                }
            }
            if (stare === 1) {
                diferiti++;
                const k = Math.floor(x / BLOC) + ',' + Math.floor(y / BLOC);
                const bl = blocuri.get(k);
                // numărul pixelilor diferiți și cutia lor exactă în bloc
                if (bl) { bl[0]++; if (x < bl[1]) { bl[1] = x; } if (y < bl[2]) { bl[2] = y; } if (x > bl[3]) { bl[3] = x; } if (y > bl[4]) { bl[4] = y; } } else { blocuri.set(k, [1, x, y, x, y]); }
            } else if (stare === 2) {
                netezite++;
            }
            if (sup) {
                const d = p * 4;
                if (stare === 1) { sup[d] = 255; sup[d + 1] = 0; sup[d + 2] = 0; sup[d + 3] = 255; } else if (stare === 2) { sup[d] = 255; sup[d + 1] = 200; sup[d + 2] = 0; sup[d + 3] = 255; } else {
                    const ia = (y * a.latime + x) * 4;
                    const g = (x < a.latime && y < a.inaltime) ? Math.round(255 - (255 - (a.date[ia] * 0.3 + a.date[ia + 1] * 0.59 + a.date[ia + 2] * 0.11)) * 0.15) : 255;
                    if (stare === 3) { sup[d] = 120; sup[d + 1] = 140; sup[d + 2] = 220; } else { sup[d] = g; sup[d + 1] = g; sup[d + 2] = g; }
                    sup[d + 3] = 255;
                }
            }
        }
    }
    // Zonele diferite: blocurile de 32 px unite în dreptunghiuri (pentru raport); `exacte` are, pe aceeași poziție, cutia exactă
    // a pixelilor diferiți din zonă [x, y, l, i].
    const zone = [];
    for (const [k, bl] of blocuri) {
        const [bx, by] = k.split(',').map(Number);
        zone.push([bx * BLOC, by * BLOC, BLOC, BLOC, bl[0], bl[1], bl[2], bl[3], bl[4]]);
    }
    zone.sort((p, q) => p[1] - q[1] || p[0] - q[0]);
    const unite = [];
    for (const z of zone) {
        const u = unite.find((w) => z[0] <= w[0] + w[2] + BLOC && z[0] + z[2] >= w[0] - BLOC && z[1] <= w[1] + w[3] + BLOC && z[1] + z[3] >= w[1] - BLOC);
        if (u) {
            const x0 = Math.min(u[0], z[0]); const y0 = Math.min(u[1], z[1]);
            const x1 = Math.max(u[0] + u[2], z[0] + z[2]); const y1 = Math.max(u[1] + u[3], z[1] + z[3]);
            u[0] = x0; u[1] = y0; u[2] = x1 - x0; u[3] = y1 - y0; u[4] += z[4];
            u[5] = Math.min(u[5], z[5]); u[6] = Math.min(u[6], z[6]); u[7] = Math.max(u[7], z[7]); u[8] = Math.max(u[8], z[8]);
        } else {
            unite.push(z.slice());
        }
    }
    const primele = unite.slice(0, 200);
    return {
        diferiti, netezite, mascati, total: latime * inaltime, latime, inaltime,
        marimi: [[a.latime, a.inaltime], [b.latime, b.inaltime]], zone: primele.map((z) => z.slice(0, 5)),
        exacte: primele.map((z) => [z[5], z[6], z[7] - z[5] + 1, z[8] - z[6] + 1]), zoneTotal: unite.length,
        suprapunere: sup ? { latime, inaltime, date: sup } : null,
    };
}

module.exports = { decodeaza, codeaza, compara };
