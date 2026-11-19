'use strict';
// Unde scrie harness-ul (recenzia 2, R3-6): niciodată în repo (capturile, comparațiile și rapoartele au DOM-ul și pixelii unui
// site real), iar configurația unui site real se citește doar din pachetul lui privat, site-uri/<site>/privat/paritate.json
// (ignorat de git), niciodată din pachetul public. Orice abatere: mesajul pe stderr și codul 2, înaintea oricărei scrieri.
const fs = require('fs');
const path = require('path');

const RADACINA = fs.realpathSync(path.resolve(__dirname, '../../../..'));

/** Calea reală (și pentru o cale care încă nu există: cel mai apropiat strămoș existent, cu realpath-ul lui). */
function caleReala(p) {
    let cur = path.resolve(p);
    const rest = [];
    while (!fs.existsSync(cur)) {
        rest.unshift(path.basename(cur));
        cur = path.dirname(cur);
    }
    return path.join(fs.realpathSync(cur), ...rest);
}

function inRepo(p) {
    const r = caleReala(p);
    return r === RADACINA || r.startsWith(RADACINA + path.sep);
}

/** Un dosar sau un fișier de ieșire: în afara repo-ului, altfel codul 2. Întoarce calea reală. */
function verificaIesirea(p, ce) {
    if (typeof p !== 'string' || p === '') {
        return p;
    }
    if (inRepo(p)) {
        process.stderr.write(`NU: ${ce || 'ieșirea'} (${caleReala(p)}) e în repo; dă un dosar în afara lui\n`);
        process.exit(2);
    }
    return caleReala(p);
}

/** O configurație paritate.json: niciodată cea din pachetul public al unui site (site-uri/<site>/paritate.json). */
function verificaConfig(p) {
    if (typeof p !== 'string' || p === '') {
        return p;
    }
    const rel = path.relative(RADACINA, caleReala(p)).split(path.sep).join('/');
    if (/^site-uri\/[^/]+\/paritate\.json$/.test(rel)) {
        process.stderr.write('NU: configurația unui site stă în site-uri/<site>/privat/paritate.json (ignorat de git), nu în pachetul public\n');
        process.exit(2);
    }
    return p;
}

/**
 * Paza tuturor scrierilor unui proces al harness-ului (recenzia 3, R3-6): orice scriere de fișier sau de dosar în repo, prin
 * oricare dintre funcțiile fs, oprește procesul cu codul 2 înaintea scrierii. Se cheamă o dată, la începutul fiecărui program.
 */
function pazesteScrierile() {
    if (fs.__pazitDeHarness) {
        return;
    }
    const opreste = (p, ce) => {
        if (typeof p === 'string' || Buffer.isBuffer(p) || p instanceof URL) {
            const cale = p instanceof URL ? p.pathname : String(p);
            if (inRepo(cale)) {
                process.stderr.write(`NU: ${ce} (${caleReala(cale)}) e în repo; harness-ul nu scrie în repo\n`);
                process.exit(2);
            }
        }
    };
    for (const [nume, arg] of [['writeFileSync', 0], ['appendFileSync', 0], ['mkdirSync', 0], ['createWriteStream', 0], ['copyFileSync', 1],
        ['renameSync', 1], ['openSync', 0], ['writeFile', 0], ['appendFile', 0], ['mkdir', 0], ['copyFile', 1], ['rename', 1], ['symlinkSync', 1]]) {
        const orig = fs[nume];
        if (typeof orig !== 'function') { continue; }
        fs[nume] = function (...a) {
            if (nume === 'openSync' && !(typeof a[1] === 'string' ? /[wa+]/.test(a[1]) : typeof a[1] === 'number' && (a[1] & (fs.constants.O_WRONLY | fs.constants.O_RDWR)))) {
                return orig.apply(this, a);
            }
            opreste(a[arg], 'scrierea ' + nume);
            return orig.apply(this, a);
        };
    }
    for (const nume of ['writeFile', 'appendFile', 'mkdir', 'copyFile', 'rename']) {
        const orig = fs.promises[nume];
        const arg = ['copyFile', 'rename'].includes(nume) ? 1 : 0;
        fs.promises[nume] = function (...a) { opreste(a[arg], 'scrierea ' + nume); return orig.apply(this, a); };
    }
    fs.__pazitDeHarness = true;
}

module.exports = { verificaIesirea, verificaConfig, inRepo, caleReala, RADACINA, pazesteScrierile };
