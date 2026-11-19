// Depozitul artefactelor unei capturi (referința sau candidatul): fișiere după sha256 (deduplicate: aceeași captură de ecran
// sau același DOM pe două profiluri se scrie o dată), JSON-urile comprimate cu gzip. Totul în dosarul de ieșire, în afara
// repo-ului.

'use strict';

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');

class Depozit {
    constructor(dosar) {
        this.dosar = dosar;
        this.obiecte = path.join(dosar, '_obiecte');
        fs.mkdirSync(this.obiecte, { recursive: true });
    }
    cale(sha, ext) { return path.join(this.obiecte, sha.slice(0, 2), sha + ext); }
    puneOcteti(buf, ext) {
        const sha = crypto.createHash('sha256').update(buf).digest('hex');
        const c = this.cale(sha, ext);
        if (!fs.existsSync(c)) {
            fs.mkdirSync(path.dirname(c), { recursive: true });
            fs.writeFileSync(c + '.tmp', buf);
            fs.renameSync(c + '.tmp', c);
        }
        return sha;
    }
    // JSON: sha256 al textului necomprimat (stabil), fișierul .json.gz.
    puneJson(obiect) {
        const text = Buffer.from(JSON.stringify(obiect));
        const sha = crypto.createHash('sha256').update(text).digest('hex');
        const c = this.cale(sha, '.json.gz');
        if (!fs.existsSync(c)) {
            fs.mkdirSync(path.dirname(c), { recursive: true });
            fs.writeFileSync(c + '.tmp', zlib.gzipSync(text, { level: 9 }));
            fs.renameSync(c + '.tmp', c);
        }
        return sha;
    }
    puneText(text, ext) {
        const buf = Buffer.from(text);
        const sha = crypto.createHash('sha256').update(buf).digest('hex');
        const c = this.cale(sha, ext + '.gz');
        if (!fs.existsSync(c)) {
            fs.mkdirSync(path.dirname(c), { recursive: true });
            fs.writeFileSync(c + '.tmp', zlib.gzipSync(buf, { level: 9 }));
            fs.renameSync(c + '.tmp', c);
        }
        return sha;
    }
    citesteJson(sha) { return JSON.parse(zlib.gunzipSync(fs.readFileSync(this.cale(sha, '.json.gz'))).toString('utf8')); }
    citesteText(sha, ext) { return zlib.gunzipSync(fs.readFileSync(this.cale(sha, ext + '.gz'))).toString('utf8'); }
    citesteOcteti(sha, ext) { return fs.readFileSync(this.cale(sha, ext)); }
    exista(sha, ext) { return fs.existsSync(this.cale(sha, ext)); }
}

module.exports = { Depozit };
