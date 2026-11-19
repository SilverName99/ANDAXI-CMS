// Serverul fixture-ului inventat „Pensiunea Probă” pentru autotestul harness-ului de paritate (G-paritate). Servește
// fixture/site/ cu o politică CSP impusă și, la cerere, o diferență plantată (o singură variantă pe server):
//
//   node server.cjs --port=<p> [--varianta=nimic|pixel|clasa|mutatie|terta|csp|tracker|subarbore]
//
//   pixel   — poza are un pixel schimbat (doar pixelii și octeții pozei diferă);
//   clasa   — o clasă în plus în HTML-ul static (fără niciun efect vizual);
//   mutatie — scriptul paginii mai face o mutație la încărcare (un atribut, fără efect vizual);
//   terta   — o poză de pe o gazdă terță, cerută înainte de acord;
//   csp     — un script inline, blocat de politică (încălcare + raport pe /_andaxi/raport);
//   tracker — scriptul paginii cere un tracker (Google Tag Manager) fără acord și fără fișier de probă.
// Rapoartele CSP primite: 204 (harness-ul le interceptează oricum înaintea rețelei).

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const PNG = require('../lib/png.cjs');

const arg = {};
for (const x of process.argv.slice(2)) {
    const m = /^--([a-z]+)=(.*)$/.exec(x);
    if (m) { arg[m[1]] = m[2]; }
}
const port = Number(arg.port || 0);
const varianta = arg.varianta || 'nimic';
const VARIANTE = ['nimic', 'pixel', 'clasa', 'mutatie', 'terta', 'csp', 'tracker', 'subarbore'];
if (!port || !VARIANTE.includes(varianta)) {
    console.error('folosire: server.cjs --port=<p> [--varianta=' + VARIANTE.join('|') + ']');
    process.exit(2);
}
const SITE = path.join(__dirname, 'site');
const CSP = "default-src 'self'; script-src 'self' https://www.googletagmanager.com/gtag/js; style-src 'self' 'unsafe-inline'; "
    + "img-src 'self' data: https://cdn.terta.example; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; "
    + 'report-uri /_andaxi/raport';

function poza() {
    const latime = 320;
    const inaltime = 160;
    const date = Buffer.alloc(latime * inaltime * 4);
    const culori = [[47, 79, 58], [224, 176, 64], [120, 150, 190], [240, 235, 220]];
    for (let y = 0; y < inaltime; y++) {
        for (let x = 0; x < latime; x++) {
            const c = culori[(x < latime / 2 ? 0 : 1) + (y < inaltime / 2 ? 0 : 2)];
            const i = (y * latime + x) * 4;
            date[i] = c[0]; date[i + 1] = c[1]; date[i + 2] = c[2]; date[i + 3] = 255;
        }
    }
    if (varianta === 'pixel') {
        const i = (40 * latime + 80) * 4;
        date[i] = 255; date[i + 1] = 0; date[i + 2] = 0;
    }
    return PNG.codeaza({ latime, inaltime, date });
}

function html(fisier) {
    let s = fs.readFileSync(fisier, 'utf8');
    if (varianta === 'clasa') { s = s.replace('<div class="card card-2">', '<div class="card card-2 card-plantat">'); }
    if (varianta === 'terta') { s = s.replace('<!--PLANTAT-CORP-->', '<img class="terta" src="https://cdn.terta.example/p.gif" alt="" width="1" height="1">'); }
    // un subarbore în plus înaintea primei secțiuni: cheile poziționale ale secțiunilor de după se deplasează
    if (varianta === 'subarbore') { s = s.replace('<section class="erou">', '<section class="extra"><h2>Anunț inventat</h2><p>Text în plus.</p></section>\n  <section class="erou">'); }
    if (varianta === 'csp') { s = s.replace('<!--PLANTAT-CORP-->', '<script>document.body.setAttribute("data-inline", "1");</script>'); }
    return s;
}

function js() {
    let s = fs.readFileSync(path.join(SITE, 'app.js'), 'utf8');
    if (varianta === 'mutatie') { s = s.replace('/*PLANTAT-JS*/', "var c3 = document.querySelector('.card-3'); if (c3) { c3.setAttribute('aria-expanded', 'false'); }"); }
    if (varianta === 'tracker') {
        s = s.replace('/*PLANTAT-JS*/', "var t = document.createElement('script'); t.async = true; t.src = 'https://www.googletagmanager.com/gtag/js?id=G-PROBA0000'; document.head.appendChild(t);");
    }
    return s;
}

const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://127.0.0.1');
    const trimite = (status, tip, corp, extra) => {
        res.writeHead(status, { 'content-type': tip, 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...(extra || {}) });
        res.end(corp);
    };
    if (req.method === 'POST' && u.pathname === '/_andaxi/raport') {
        req.resume();
        return trimite(204, 'text/plain', '');
    }
    if (req.method !== 'GET' && req.method !== 'HEAD') { return trimite(405, 'text/plain', 'metodă nepermisă'); }
    const htmlCsp = { 'content-security-policy': CSP };
    if (u.pathname === '/') { return trimite(200, 'text/html; charset=UTF-8', html(path.join(SITE, 'index.html')), htmlCsp); }
    if (u.pathname === '/pagina-2/') { return trimite(200, 'text/html; charset=UTF-8', html(path.join(SITE, 'pagina-2', 'index.html')), htmlCsp); }
    if (u.pathname === '/stil.css' || u.pathname === '/tarziu.css') { return trimite(200, 'text/css; charset=UTF-8', fs.readFileSync(path.join(SITE, u.pathname.slice(1)))); }
    if (u.pathname === '/app.js') { return trimite(200, 'application/javascript; charset=UTF-8', js()); }
    if (u.pathname === '/img/poza.png') { return trimite(200, 'image/png', poza()); }
    return trimite(404, 'text/html; charset=UTF-8', '<!DOCTYPE html><title>404</title><p>Pagina nu există.</p>', htmlCsp);
});
server.listen(port, '127.0.0.1', () => { console.log('fixture ' + varianta + ' pe http://127.0.0.1:' + port + '/'); });
process.on('SIGTERM', () => server.close(() => process.exit(0)));
