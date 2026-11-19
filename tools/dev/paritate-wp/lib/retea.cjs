// Rețeaua harness-ului de paritate (P3; DECIZIE §6.1, C2 §0.5 și §0.7): browserul nu vorbește niciodată singur cu rețeaua.
// Chromium pornește cu un proxy „gaură neagră” (127.0.0.1:9), iar fiecare cerere trece prin ruta contextului, care o
// servește din cache, o aduce prin Node (fetch, cu proxy-ul din mediu) sau o abortează, și o scrie în jurnal:
//
//  - **doar GET** spre site; orice altă metodă e abortată în browser (la candidat: în afară de rapoartele CSP, pe care
//    harness-ul le primește el, fără să le trimită mai departe);
//  - **trackerele** (TRACKERE): abortate la referință, fără nicio lovitură; la candidat primesc fișierele de probă din
//    configurație, iar o cerere de tracker fără fișier de probă e abortată și numărată ca diferență (P3-retea);
//  - **celelalte gazde terțe**: abortate și scrise în jurnal, în afară de gazdele de fonturi permise explicit (referința);
//  - **căile abortate** ale site-ului (implicit admin-ajax.php și /wp-json/complianz/): abortate și la GET;
//  - **ritmul**: cel mult `paralel` cereri în zbor spre site (implicit 2), reluare la 429 după Retry-After, bugetul total
//    numărat într-un fișier (se adună de la o rulare la alta) și oprirea la atingerea lui;
//  - **cache-ul persistent** (referința): corpurile după sha256, indexul în JSONL; documentele și foile Google Fonts au cheia
//    cu profilul (UA-ul schimbă răspunsul), restul doar adresa (afară de un răspuns cu `Vary: User-Agent`). Cererile Range
//    (video) primesc felia din corpul întreg, adus o singură dată;
//  - **sursa „doar-cache”**: doar ce e deja în cache-ul persistent, fără nicio cerere (analizele de după captură);
//  - **sursa „dosar”** (doar pentru dezvoltarea harness-ului): răspunsurile se citesc dintr-un dosar de cache al inventarului,
//    fără nicio cerere; o adresă lipsă e abortată.

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Sufixele gazdelor de trackere și reclame (și subdomeniile lor). Lista capturii (Cereri::TRACKERE) plus cele uzuale.
const TRACKERE = [
    'clarity.ms', 'google-analytics.com', 'analytics.google.com', 'googletagmanager.com', 'googletagservices.com',
    'doubleclick.net', 'googleadservices.com', 'googlesyndication.com', 'adservice.google.com', 'facebook.net', 'facebook.com',
    'hotjar.com', 'hotjar.io', 'bat.bing.com', 'linkedin.com', 'licdn.com', 'tiktok.com', 'yandex.ru', 'yandex.com',
    'criteo.com', 'criteo.net', 'taboola.com', 'outbrain.com', 'adnxs.com', 'scorecardresearch.com', 'quantserve.com',
    'newrelic.com', 'nr-data.net', 'segment.com', 'segment.io', 'mixpanel.com', 'amplitude.com', 'fullstory.com',
    'mouseflow.com', 'crazyegg.com', 'ct.pinterest.com', 'analytics.twitter.com', 'ads-twitter.com', 'stats.wp.com',
];

const ANTETE_FARA_FORWARD = new Set(['host', 'connection', 'content-length', 'cookie', 'keep-alive', 'transfer-encoding', 'upgrade',
    'accept-encoding', 'te', 'trailer', 'expect', 'proxy-authorization', 'proxy-connection', 'priority', 'range']);
const ANTETE_FARA_FULFILL = new Set(['content-encoding', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'alt-svc']);
// Media: cel mult 1 MiB pe cerere (metadatele video); un corp obișnuit: cel mult 64 MiB.
const PLAFON_INTERVAL = 1024 * 1024;
const CORP_MAXIM = 64 * 1024 * 1024;

function sha256(buf) {
    return crypto.createHash('sha256').update(buf).digest('hex');
}

function sufix(gazda, lista) {
    const g = String(gazda).toLowerCase().replace(/\.$/, '');
    return lista.some((s) => g === s || g.endsWith('.' + s));
}

function esteTracker(gazda) {
    return sufix(gazda, TRACKERE);
}

// Un semafor mic: cel mult n promisiuni în lucru odată.
class Semafor {
    constructor(n) { this.n = n; this.activi = 0; this.coada = []; this.maxim = 0; }
    async ia() {
        if (this.activi < this.n) {
            this.activi++;
            this.maxim = Math.max(this.maxim, this.activi);
            return;
        }
        await new Promise((gata) => this.coada.push(gata));
        this.activi++;
        this.maxim = Math.max(this.maxim, this.activi);
    }
    da() {
        this.activi--;
        const urm = this.coada.shift();
        if (urm) { urm(); }
    }
}

const somn = (ms) => new Promise((gata) => setTimeout(gata, ms));

// Cache-ul: corpurile după sha256 (deduplicate), indexul în JSONL (se adaugă, nu se rescrie). `dosar` null = doar în memorie.
class Cache {
    constructor(dosar) {
        this.dosar = dosar;
        this.index = new Map();
        this.corpuriMemorie = new Map();
        if (dosar) {
            fs.mkdirSync(path.join(dosar, 'obiecte'), { recursive: true });
            this.fisierIndex = path.join(dosar, 'index.jsonl');
            if (fs.existsSync(this.fisierIndex)) {
                for (const linie of fs.readFileSync(this.fisierIndex, 'utf8').split('\n')) {
                    if (linie.trim() === '') { continue; }
                    try {
                        const e = JSON.parse(linie);
                        this.index.set(e.cheie, e);
                    } catch (err) { /* un rând tăiat la o oprire: ignorat */ }
                }
            }
        }
    }
    caleCorp(sha) { return path.join(this.dosar, 'obiecte', sha.slice(0, 2), sha); }
    corp(intrare) {
        if (!this.dosar) { return this.corpuriMemorie.get(intrare.sha256); }
        return fs.readFileSync(this.caleCorp(intrare.sha256));
    }
    cauta(cheie) { return this.index.get(cheie) || null; }
    pune(intrare, corp) {
        if (this.dosar) {
            const cale = this.caleCorp(intrare.sha256);
            if (!fs.existsSync(cale)) {
                fs.mkdirSync(path.dirname(cale), { recursive: true });
                fs.writeFileSync(cale + '.tmp', corp);
                fs.renameSync(cale + '.tmp', cale);
            }
            fs.appendFileSync(this.fisierIndex, JSON.stringify(intrare) + '\n');
        } else {
            this.corpuriMemorie.set(intrare.sha256, corp);
        }
        this.index.set(intrare.cheie, intrare);
    }
}

// Bugetul de cereri spre site, numărat într-un fișier comun tuturor rulărilor (C2: un singur buget pentru captură și referință).
class Buget {
    constructor(fisier, limite) {
        this.fisier = fisier;
        this.limite = limite || {};
        this.stare = { propriu: 0, fonturi: 0, candidat: 0 };
        if (fisier && fs.existsSync(fisier)) {
            try { Object.assign(this.stare, JSON.parse(fs.readFileSync(fisier, 'utf8'))); } catch (e) { /* refăcut mai jos */ }
        }
    }
    poate(clasa) {
        const lim = this.limite[clasa];
        return lim === undefined || lim === null || (this.stare[clasa] || 0) < lim;
    }
    numara(clasa) {
        this.stare[clasa] = (this.stare[clasa] || 0) + 1;
        this.stare.ultima = new Date().toISOString();
        if (this.fisier) {
            fs.writeFileSync(this.fisier + '.tmp', JSON.stringify(this.stare, null, 1));
            fs.renameSync(this.fisier + '.tmp', this.fisier);
        }
    }
}

// Lacătul rețelei vii: o singură unealtă a pistei care cere de pe site odată (ritmul și cele 2 cereri în zbor sunt globale).
function iaLacat(fisier) {
    for (let incercare = 0; incercare < 2; incercare++) {
        try {
            const fd = fs.openSync(fisier, 'wx');
            fs.writeSync(fd, String(process.pid));
            fs.closeSync(fd);
            const elibereaza = () => { try { if (fs.readFileSync(fisier, 'utf8') === String(process.pid)) { fs.unlinkSync(fisier); } } catch (e) { /* deja scos */ } };
            process.on('exit', elibereaza);
            return elibereaza;
        } catch (e) {
            if (e.code !== 'EEXIST') { throw e; }
            const pid = Number(fs.readFileSync(fisier, 'utf8')) || 0;
            let viu = false;
            try { process.kill(pid, 0); viu = pid > 0; } catch (err) { viu = false; }
            if (viu) {
                throw new Error('altă unealtă a pistei (pid ' + pid + ') folosește rețeaua vie (' + fisier + '); aștept-o, nu rulez în paralel');
            }
            fs.unlinkSync(fisier);
        }
    }
    throw new Error('nu pot lua lacătul ' + fisier);
}

class Retea {
    /**
     * @param {object} o
     *   mod: 'referinta' | 'candidat'
     *   site: originea site-ului (referința: site-ul live; candidatul: serverul candidatului)
     *   gazdeFonturi: gazdele terțe aduse (referința: fonts.googleapis.com, fonts.gstatic.com)
     *   caiAbortate: expresii pe cale+query, abortate și la GET
     *   cache: dosarul cache-ului persistent sau null
     *   sursa: 'retea' | { dosar }
     *   paralel, buget: { fisier, limite: { propriu, fonturi, candidat } }
     *   probeTrackere: { '<expresie pe adresă>': { fisier | corp, tip } } (candidatul)
     *   jurnal: fișierul JSONL al tuturor cererilor
     *   lacat: fișierul lacătului (rețeaua vie)
     *   autentificare: { utilizator, parola } (HTTP Basic al porții, doar pe originea candidatului)
     *   postPermise: expresii pe cale, acceptate și răspunse local cu 204 (rapoartele CSP ale candidatului)
     */
    constructor(o) {
        this.o = o;
        this.origine = new URL(o.site).origin;
        this.gazda = new URL(o.site).host;
        this.gazdeFonturi = (o.gazdeFonturi || []).map((g) => g.toLowerCase());
        this.caiAbortate = (o.caiAbortate || []).map((r) => new RegExp(r));
        this.postPermise = (o.postPermise || []).map((r) => new RegExp(r));
        this.probeTrackere = Object.entries(o.probeTrackere || {}).map(([re, p]) => ({ re: new RegExp(re), ...p }));
        this.cache = new Cache(o.cache || null);
        this.semafor = new Semafor(o.paralel || 2);
        this.semaforFonturi = new Semafor(o.paralel || 2);
        this.buget = new Buget(o.buget && o.buget.fisier, o.buget && o.buget.limite);
        this.nr = 0;
        this.start = Date.now();
        this.limitariLaRand = 0;
        this.inCurs = new Map();
        this.proaspete = new Map();
        this.oprita = null;
        this.rapoarteCsp = [];
        this.sumar = { propriu: { cache: 0, descarcat: 0 }, fonturi: { cache: 0, descarcat: 0 }, abortate: {}, terte: {}, trackere: {} };
        if (o.jurnal) {
            fs.mkdirSync(path.dirname(o.jurnal), { recursive: true });
        }
        this.elibereazaLacat = (o.sursa === 'retea' && o.lacat) ? iaLacat(o.lacat) : () => {};
        if (o.sursa === 'retea' && (process.env.HTTPS_PROXY || process.env.https_proxy) && process.env.NODE_USE_ENV_PROXY !== '1'
            && !/^https?:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/.test(o.site)) {
            throw new Error('fetch-ul din Node trece prin proxy doar cu NODE_USE_ENV_PROXY=1 (îl pune paritate-wp.sh)');
        }
    }

    clasifica(u) {
        if (u.protocol !== 'http:' && u.protocol !== 'https:') { return 'local'; }
        if (u.host === this.gazda) { return 'propriu'; }
        if (esteTracker(u.hostname)) { return 'tracker'; }
        if (this.gazdeFonturi.includes(u.hostname.toLowerCase())) { return 'fonturi'; }
        return 'terta';
    }

    scrie(intrare) {
        this.nr++;
        const e = { nr: this.nr, t: Date.now() - this.start, ...intrare };
        if (this.o.jurnal) {
            fs.appendFileSync(this.o.jurnal, JSON.stringify(e) + '\n');
        }
        if (this.ascultator) { this.ascultator(e); }
        return e;
    }

    // Ruta unui context: `eticheta()` dă, la fiecare cerere, locul ei (pagina, profilul, fereastra, starea, acordul).
    async ruta(route, eticheta) {
        const req = route.request();
        const url = req.url();
        let u;
        try { u = new URL(url); } catch (e) { return route.abort('failed'); }
        const loc = eticheta();
        const baza = { ...loc, metoda: req.method(), tip: req.resourceType(), url: url.length > 2000 ? url.slice(0, 2000) + '…' : url };
        const clasa = this.clasifica(u);
        baza.clasa = clasa;
        const abort = async (motiv, cod) => {
            this.sumar.abortate[motiv] = (this.sumar.abortate[motiv] || 0) + 1;
            if (clasa === 'terta') { this.sumar.terte[u.host] = (this.sumar.terte[u.host] || 0) + 1; }
            if (clasa === 'tracker') { this.sumar.trackere[u.host] = (this.sumar.trackere[u.host] || 0) + 1; }
            this.scrie({ ...baza, actiune: 'abortat', motiv });
            return route.abort(cod || 'blockedbyclient').catch(() => {});
        };
        if (this.oprita) { return abort('oprit: ' + this.oprita, 'failed'); }
        if (clasa === 'local') { return route.continue().catch(() => {}); }
        if (req.method() !== 'GET') {
            if (this.o.mod === 'candidat' && clasa === 'propriu' && this.postPermise.some((re) => re.test(u.pathname))) {
                const corp = req.postDataBuffer();
                this.rapoarteCsp.push({ ...loc, url, tip_continut: (await req.allHeaders())['content-type'] || '', corp: corp ? corp.toString('utf8').slice(0, 8192) : '' });
                this.scrie({ ...baza, actiune: 'primit-local', motiv: 'raport CSP' });
                return route.fulfill({ status: 204, body: '' }).catch(() => {});
            }
            // corpul cererii abortate rămâne în jurnal (cererea nu pleacă din browser): forma exactă a unui POST de modul
            const corpPost = req.postDataBuffer();
            baza.corp_abortat = corpPost ? corpPost.toString('utf8').slice(0, 4096) : null;
            baza.tip_corp = (await req.allHeaders())['content-type'] || null;
            return abort('metodă ' + req.method());
        }
        if (clasa === 'tracker') {
            const proba = this.o.mod === 'candidat' ? this.probeTrackere.find((p) => p.re.test(url)) : null;
            if (proba) {
                const corp = proba.fisier ? fs.readFileSync(proba.fisier) : Buffer.from(proba.corp || '');
                this.sumar.trackere[u.host] = (this.sumar.trackere[u.host] || 0) + 1;
                this.scrie({ ...baza, actiune: 'proba', motiv: 'fișier de probă', status: 200, octeti: corp.length });
                return route.fulfill({ status: 200, headers: { 'content-type': proba.tip || 'application/javascript' }, body: corp }).catch(() => {});
            }
            return abort(this.o.mod === 'candidat' ? 'tracker fără fișier de probă' : 'tracker');
        }
        if (clasa === 'terta') { return abort('gazdă terță'); }
        if (clasa === 'fonturi' && this.o.mod === 'candidat') { return abort('gazdă de fonturi la candidat'); }
        if (clasa === 'propriu' && this.caiAbortate.some((re) => re.test(u.pathname + u.search))) { return abort('cale abortată'); }
        let raspuns;
        try {
            raspuns = await this.obtine(req, u, clasa, loc);
        } catch (e) {
            this.scrie({ ...baza, actiune: 'eroare', motiv: String(e && e.message || e).slice(0, 300) });
            return route.abort('failed').catch(() => {});
        }
        const { intrare, corp, deUnde } = raspuns;
        this.sumar[clasa][deUnde === 'cache' ? 'cache' : 'descarcat']++;
        let status = intrare.status;
        let corpTrimis = corp;
        const antete = {};
        for (const [n, v] of Object.entries(intrare.antete || {})) {
            if (!ANTETE_FARA_FULFILL.has(n)) { antete[n] = v; }
        }
        if (intrare.setCookie && intrare.setCookie.length) { antete['set-cookie'] = intrare.setCookie.join('\n'); }
        const range = (await req.allHeaders()).range;
        if (range && status === 200 && corp) {
            // Un corp întreg din cache, cerut pe interval (media): browserul primește doar intervalul, tăiat la PLAFON_INTERVAL
            // (ca la rețea), ca un video mare să nu treacă întreg prin protocolul Playwright.
            const m = /^bytes=(\d*)-(\d*)$/.exec(range.trim());
            if (m) {
                const total = corp.length;
                let a = m[1] === '' ? total - Number(m[2]) : Number(m[1]);
                let b = m[1] === '' ? total - 1 : (m[2] === '' ? total - 1 : Math.min(Number(m[2]), total - 1));
                if (a < 0) { a = 0; }
                b = Math.min(b, a + PLAFON_INTERVAL - 1);
                if (a <= b && a < total) {
                    corpTrimis = corp.subarray(a, b + 1);
                    status = 206;
                    antete['content-range'] = 'bytes ' + a + '-' + b + '/' + total;
                    antete['accept-ranges'] = 'bytes';
                }
            }
        }
        this.scrie({ ...baza, actiune: deUnde, status, octeti: corp ? corp.length : 0, sha256: intrare.sha256, tip_continut: (intrare.antete || {})['content-type'] || '' });
        if (status >= 300 && status < 400) {
            return route.fulfill({ status, headers: antete, body: '' }).catch(() => {});
        }
        return route.fulfill({ status, headers: antete, body: corpTrimis || Buffer.alloc(0) }).catch(() => {});
    }

    // Cheia pe UA: după textul UA-ului (două profiluri cu același UA, ca Safari pe Mac și pe iPad, primesc aceleași răspunsuri).
    static ua(textUa) {
        return 'ua:' + sha256(String(textUa || '-')).slice(0, 12) + '|';
    }

    cheie(u, clasa, tip, textUa, intrare) {
        const peProfil = tip === 'document' || (clasa === 'fonturi' && u.hostname === 'fonts.googleapis.com')
            || (intrare && /user-agent/i.test(intrare.vary || ''));
        return (peProfil ? Retea.ua(textUa) : '') + u.href;
    }

    // Răspunsul unei cereri: din cache sau adus (o singură dată, chiar dacă browserul îl cere de mai multe ori deodată).
    async obtine(req, u, clasa, loc) {
        const tip = req.resourceType();
        const profil = loc.profil || '-';
        const toate = await req.allHeaders();
        const textUa = toate['user-agent'] || '';
        const range = toate.range || null;
        const cheieUa = this.cheie(u, clasa, 'document', textUa);
        // documentele proaspete (trecerile de zgomot): documentul paginii se cere de fiecare dată de pe site, fără cache și fără
        // să fie împărțit cu altă încărcare; nu intră în cache-ul persistent (referința rămâne neatinsă)
        if (this.o.documenteProaspete && tip === 'document' && clasa === 'propriu') {
            const r = await this.aduce(req, u, clasa, tip, profil, textUa, toate, null, cheieUa, true);
            // ținute în memorie cât le citesc capturile (două încărcări în paralel pot primi aceiași octeți): ultimele 64
            this.proaspete.delete(r.intrare.sha256);
            this.proaspete.set(r.intrare.sha256, r.corp);
            while (this.proaspete.size > 64) { this.proaspete.delete(this.proaspete.keys().next().value); }
            return r;
        }
        const peUa = tip === 'document' || (clasa === 'fonturi' && u.hostname === 'fonts.googleapis.com');
        const faraVaryUa = (e) => (e && !/user-agent/i.test(e.vary || '') ? e : null);
        const intreg = peUa ? this.cache.cauta(cheieUa) : (this.cache.cauta(cheieUa) || faraVaryUa(this.cache.cauta(u.href)));
        if (intreg && intreg.status !== 206) {
            return { intrare: intreg, corp: this.cache.corp(intreg), deUnde: 'cache' };
        }
        // Media (Range): se cere doar intervalul, tăiat la PLAFON_INTERVAL; cheia cache-ului e adresa plus intervalul.
        let interval = null;
        if (range) {
            const m = /^bytes=(\d+)-(\d*)$/.exec(range.trim());
            if (m) {
                const a = Number(m[1]);
                const b = m[2] === '' ? a + PLAFON_INTERVAL - 1 : Math.min(Number(m[2]), a + PLAFON_INTERVAL - 1);
                interval = a + '-' + b;
            }
        }
        const cheie = interval ? u.href + '|interval:' + interval : (peUa ? cheieUa : u.href);
        if (interval) {
            const g = this.cache.cauta(cheie);
            if (g) { return { intrare: g, corp: this.cache.corp(g), deUnde: 'cache' }; }
        }
        if (this.inCurs.has(cheie)) {
            const r = await this.inCurs.get(cheie);
            return { ...r, deUnde: 'cache' };
        }
        const p = this.aduce(req, u, clasa, tip, profil, textUa, toate, interval, cheie);
        this.inCurs.set(cheie, p);
        try {
            return await p;
        } finally {
            this.inCurs.delete(cheie);
        }
    }

    async aduce(req, u, clasa, tip, profil, textUa, toate, interval, cheieFixa, faraCache) {
        const antete = {};
        for (const [n, v] of Object.entries(toate)) {
            if (!ANTETE_FARA_FORWARD.has(n.toLowerCase()) && !n.startsWith(':')) { antete[n] = v; }
        }
        antete['accept-encoding'] = interval ? 'identity' : 'gzip, deflate, br';
        if (interval) { antete.range = 'bytes=' + interval; }
        if (/^(firefox|safari-)/.test(profil)) {
            for (const n of Object.keys(antete)) { if (n.startsWith('sec-ch-ua')) { delete antete[n]; } }
        }
        if (this.o.autentificare && clasa === 'propriu') {
            antete.authorization = 'Basic ' + Buffer.from(this.o.autentificare.utilizator + ':' + this.o.autentificare.parola).toString('base64');
        }
        const limita = interval ? PLAFON_INTERVAL : CORP_MAXIM;
        const d = await this.descarca(u, clasa, antete, limita);
        let { status, anteteRasp, corp } = d;
        if (interval && status === 200) {
            // Serverul a ignorat Range: corpul a fost tăiat la plafon; îl dăm browserului ca 206 cu totalul din Content-Length.
            const total = Number(anteteRasp['content-length']) || '*';
            const [a] = interval.split('-').map(Number);
            if (a === 0) {
                status = 206;
                anteteRasp = { ...anteteRasp, 'content-range': 'bytes 0-' + (corp.length - 1) + '/' + total, 'accept-ranges': 'bytes' };
            }
        } else if (d.taiat) {
            throw new Error('răspunsul depășește ' + (limita >> 20) + ' MB: ' + u.href);
        }
        const intrare = {
            cheie: cheieFixa, url: u.href, profil: null, status, antete: anteteRasp, setCookie: d.setCookie, sha256: sha256(corp), octeti: corp.length,
            vary: anteteRasp.vary || '', descarcat_la: new Date().toISOString(), durata_ms: d.durata, incercari: d.incercari, tip,
            interval: interval || undefined,
        };
        if (!interval) { intrare.cheie = this.cheie(u, clasa, tip, textUa, intrare); }
        intrare.profil = intrare.cheie.startsWith('ua:') ? profil : null;
        // Un 5xx sau o eroare de rețea nu intră în cache: o rulare următoare o cere din nou (și o numără).
        if (status < 500 && !faraCache) { this.cache.pune(intrare, corp); }
        return { intrare, corp, deUnde: 'descarcat' };
    }

    // Corpul unui răspuns după sha256 (documentele proaspete țin minte până le citește captura; apoi din cache-ul persistent).
    corpDupaSha(sha) {
        if (this.proaspete.has(sha)) { return this.proaspete.get(sha); }
        if (this.cache.dosar) {
            const c = this.cache.caleCorp(sha);
            if (fs.existsSync(c)) { return fs.readFileSync(c); }
        }
        return this.cache.corpuriMemorie.get(sha) || null;
    }

    async descarca(u, clasa, antete, limita) {
        if (this.o.sursa && this.o.sursa.dosar) {
            return this.dinDosar(u);
        }
        // „doar-cache”: nicio cerere în rețea (analize ulterioare pe ce s-a adus deja); ce lipsește e abortat.
        if (this.o.sursa === 'doar-cache') {
            throw new Error('lipsește din cache (sursa doar-cache): ' + u.href);
        }
        const clasaBuget = this.o.mod === 'candidat' ? 'candidat' : clasa;
        const semafor = clasa === 'fonturi' ? this.semaforFonturi : this.semafor;
        let incercari = 0;
        for (;;) {
            if (!this.buget.poate(clasaBuget)) {
                this.oprita = 'bugetul de cereri (' + clasaBuget + ') atins: ' + JSON.stringify(this.buget.stare);
                throw new Error(this.oprita);
            }
            incercari++;
            await semafor.ia();
            const t0 = Date.now();
            let r;
            let corp;
            let taiat = false;
            try {
                this.buget.numara(clasaBuget);
                const ac = new AbortController();
                const ceas = setTimeout(() => ac.abort(), 120000);
                try {
                    r = await fetch(u.href, { method: 'GET', headers: antete, redirect: 'manual', signal: ac.signal });
                    const bucati = [];
                    let n = 0;
                    if (r.body) {
                        const cititor = r.body.getReader();
                        for (;;) {
                            const { done, value } = await cititor.read();
                            if (done) { break; }
                            bucati.push(Buffer.from(value));
                            n += value.length;
                            if (n >= limita) {
                                taiat = true;
                                await cititor.cancel().catch(() => {});
                                ac.abort();
                                break;
                            }
                        }
                    }
                    corp = Buffer.concat(bucati);
                    if (taiat) { corp = corp.subarray(0, limita); }
                } finally {
                    clearTimeout(ceas);
                }
            } catch (e) {
                semafor.da();
                if (incercari < 2) { await somn(5000); continue; }
                throw e;
            }
            semafor.da();
            const durata = Date.now() - t0;
            if (r.status === 429) {
                this.limitariLaRand++;
                if (this.limitariLaRand >= 6) {
                    this.oprita = 'site-ul a răspuns 429 de 6 ori la rând: oprit';
                    throw new Error(this.oprita);
                }
                const dupa = Number(r.headers.get('retry-after')) || [10, 30, 60][Math.min(incercari - 1, 2)];
                if (incercari <= 3) { await somn(Math.min(dupa, 600) * 1000); continue; }
            } else {
                this.limitariLaRand = 0;
            }
            const anteteRasp = {};
            r.headers.forEach((v, n) => { if (n !== 'set-cookie') { anteteRasp[n] = v; } });
            const setCookie = typeof r.headers.getSetCookie === 'function' ? r.headers.getSetCookie() : [];
            return { status: r.status, anteteRasp, setCookie, corp, durata, incercari, taiat };
        }
    }

    // Sursa „dosar”: numele fișierelor ca în cache-ul inventarului (documentele: gazdă+cale cu „_”, .html și .headers.txt;
    // resursele: assets/<nume>[__q<md5 al query-ului>] cu .meta.json).
    dinDosar(u) {
        const d = this.o.sursa.dosar;
        const md5 = (s) => crypto.createHash('md5').update(s).digest('hex');
        let fisier;
        let meta = null;
        if (u.host === this.gazda && !u.search && u.pathname.endsWith('/')) {
            fisier = path.join(d, (u.hostname + u.pathname).replace(/\//g, '_') + '.html');
        } else {
            let b = (u.hostname + u.pathname).replace(/[^A-Za-z0-9._-]/g, '_');
            if (u.search) { b += '__q' + md5(u.search).slice(0, 8); }
            if (b.length > 200) { b = b.slice(0, 180) + '_' + md5(u.href).slice(0, 10); }
            fisier = path.join(d, 'assets', b);
            meta = fisier + '.meta.json';
        }
        if (!fs.existsSync(fisier) || fs.statSync(fisier).size === 0) {
            throw new Error('lipsește din dosar: ' + u.href);
        }
        let anteteRasp = { 'content-type': /\.html$/.test(fisier) ? 'text/html; charset=UTF-8' : 'application/octet-stream' };
        let status = 200;
        if (meta && fs.existsSync(meta)) {
            const m = JSON.parse(fs.readFileSync(meta, 'utf8'));
            anteteRasp = m.headers || anteteRasp;
            status = m.status || 200;
        }
        return { status, anteteRasp, setCookie: [], corp: fs.readFileSync(fisier), durata: 0, incercari: 1 };
    }

    // Corpul unei adrese din cache (pentru sondele care cer octeții primiți: imaginile, foile terțe).
    corpDin(url, textUa) {
        let u;
        try { u = new URL(url); } catch (e) { return null; }
        const e = this.cache.cauta(Retea.ua(textUa) + u.href) || this.cache.cauta(u.href);
        return e ? { intrare: e, corp: this.cache.corp(e) } : null;
    }

    inchide() {
        this.elibereazaLacat();
        return { cereri: this.nr, buget: this.buget.stare, maxim_in_zbor: this.semafor.maxim, sumar: this.sumar };
    }
}

module.exports = { Retea, TRACKERE, esteTracker, sha256, somn };
