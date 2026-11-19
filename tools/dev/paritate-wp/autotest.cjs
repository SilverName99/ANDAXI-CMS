// G-paritate: autotestul harness-ului de paritate pe fixture-ul inventat „Pensiunea Probă” (C2 §10; I-C §10, „Testele”).
//
//   node autotest.cjs [--iesire=<dosar>]      (implicit $CMS_DEV_DIR/paritate-wp-autotest; în afara repo-ului)
//
// Două servere locale (porturile 9770–9799, primele libere) servesc același fixture; referința se ia de pe primul, candidatul
// de pe al doilea, cu aceeași unealtă (captura.cjs), apoi compara.cjs și raport.cjs:
//   1. același fixture pe ambele → 0 diferențe pe toate sondele, iar raportul feliei 1 iese „trecut” (cu dovezile P1/P2
//      inventate și ce-se-schimba.md); ID-ul feliei 2 e „în așteptare”, P4 „amânat”; sha256 al raportului se verifică;
//   2. fără mască, ceasul fixture-ului (conținut diferit la fiecare încărcare) trebuie să apară ca diferență: măștile lucrează;
//   3. fiecare diferență plantată pe al doilea server trebuie prinsă de sonda ei: un pixel (pixeli), o clasă (mutatii), o
//      mutație (mutatii), o cerere terță înainte de acord (retea), o încălcare CSP (csp), o cerere spre un tracker fără fișier
//      de probă (retea);
//   4. raportul pică fără dovezile P1/P2 (un ID fără dovadă) și pe o diferență plantată; un raport modificat nu mai trece
//      verificarea sha256;
//   5. măștile și regiunile: masca ceasului apare în lista măștilor din raport („aplicată”); o mască cu aria pe un singur
//      profil nu lucrează pe celălalt; regiunea unui ID (.carduri) ține diferența pozei în afara lui, iar raportul o dă
//      „neatribuită” și pică; o regiune care nu prinde nimic lasă ID-ul „fără dovadă”, o mască fără țintă e „neaplicată”;
//      sondele înlocuite din config cer dovezile cu calificativ; o mască pe un atribut ascunde doar atributul acela;
//   6. zgomotul: trei încărcări ale aceluiași fixture, fără masca ceasului → zgomot.cjs propune o singură mască, a ceasului
//      (.ceas, „instabil pe referință (3/3 încărcări)”, propusă automat, pe cele două capturi în care s-a văzut) și nimic
//      altceva; cu ea, comparația dă 0 diferențe, iar raportul o listează și spune pe ce stare observată a căzut candidatul;
//      o stare neobservată e o diferență.
// Cod 0 = toate verificările trecute; 1 = cel puțin una picată; 2 = eroare.

'use strict';

const fs = require('fs');
const path = require('path');
const net = require('net');
const { spawn, spawnSync } = require('child_process');

const AICI = __dirname;
const FIXTURE = path.join(AICI, 'fixture');
const RADACINA = path.resolve(AICI, '..', '..', '..');

const arg = {};
for (const x of process.argv.slice(2)) {
    const m = /^--([a-z0-9-]+)=(.*)$/.exec(x);
    if (!m) { console.error('argument necunoscut: ' + x); process.exit(2); }
    arg[m[1]] = m[2];
}
const DEV = process.env.CMS_DEV_DIR || path.join(RADACINA, 'storage', 'logs', 'dev');
// În afara repo-ului (recenzia 2, R3-6): implicit dosarul temporar al sistemului (sau CMS_DEV_TMP).
const IESIRE = path.resolve(arg.iesire || path.join(process.env.CMS_DEV_TMP || require('os').tmpdir(), 'paritate-wp-autotest-' + process.pid));
if (!/paritate-wp-autotest/.test(IESIRE)) { console.error('dosarul de ieșire trebuie să conțină „paritate-wp-autotest”: ' + IESIRE); process.exit(2); }

const rezultate = [];
function verifica(nume, ok, detaliu) {
    rezultate.push({ nume, ok });
    console.log((ok ? 'OK    ' : 'EȘEC  ') + nume + (detaliu ? ' — ' + detaliu : ''));
}

function portLiber(p) {
    return new Promise((gata) => {
        const s = net.createServer();
        s.once('error', () => gata(false));
        s.listen(p, '127.0.0.1', () => s.close(() => gata(true)));
    });
}

async function porturi(n) {
    const l = [];
    for (let p = 9770; p <= 9799 && l.length < n; p++) {
        if (await portLiber(p)) { l.push(p); }
    }
    if (l.length < n) { throw new Error('nu găsesc ' + n + ' porturi libere în 9770–9799'); }
    return l;
}

async function porneste(port, varianta) {
    const proc = spawn(process.execPath, [path.join(FIXTURE, 'server.cjs'), '--port=' + port, '--varianta=' + varianta], { stdio: ['ignore', 'pipe', 'pipe'] });
    await new Promise((gata, esec) => {
        const t = setTimeout(() => esec(new Error('serverul fixture-ului nu a pornit pe ' + port)), 10000);
        proc.stdout.on('data', (d) => { if (/fixture/.test(String(d))) { clearTimeout(t); gata(); } });
        proc.on('exit', (c) => { clearTimeout(t); esec(new Error('serverul fixture-ului a ieșit cu ' + c)); });
    });
    proc.removeAllListeners('exit');
    return proc;
}

function opreste(proc) {
    return new Promise((gata) => {
        if (!proc || proc.exitCode !== null) { return gata(); }
        proc.once('exit', () => gata());
        proc.kill('SIGTERM');
        setTimeout(() => { try { proc.kill('SIGKILL'); } catch (e) { /* oprit */ } gata(); }, 3000);
    });
}

function node(script, args, eticheta) {
    const r = spawnSync(process.execPath, [path.join(AICI, script), ...args], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    fs.appendFileSync(path.join(IESIRE, 'autotest.log'), '\n== ' + eticheta + ' (cod ' + r.status + ')\n' + (r.stdout || '') + (r.stderr || ''));
    return r;
}

function captura(rol, port, nume) {
    const dosar = path.join(IESIRE, nume);
    const config = {
        din_paritate: path.join(FIXTURE, 'paritate.json'), rol, site: 'http://127.0.0.1:' + port, iesire: dosar, cache: null,
        jurnal: path.join(dosar, 'jurnal-retea.jsonl'), sursa: 'retea', lucratori: 1,
    };
    const f = path.join(IESIRE, nume + '.config.json');
    fs.writeFileSync(f, JSON.stringify(config, null, 1));
    const r = node('captura.cjs', [f], 'captura ' + nume);
    if (r.status !== 0) { throw new Error('captura ' + nume + ' a ieșit cu ' + r.status + ': ' + (r.stderr || r.stdout).slice(-600)); }
    return dosar;
}

function compara(ref, cand, nume, config) {
    const out = path.join(IESIRE, 'cmp-' + nume);
    const r = node('compara.cjs', ['--referinta=' + ref, '--candidat=' + cand, '--config=' + (config || path.join(FIXTURE, 'paritate.json')), '--iesire=' + out, '--felie=1'], 'compara ' + nume);
    if (r.status === 2) { throw new Error('compara ' + nume + ': ' + (r.stderr || r.stdout).slice(-600)); }
    return { cod: r.status, dosar: out, c: JSON.parse(fs.readFileSync(path.join(out, 'comparatii.json'), 'utf8')) };
}

function raport(cmp, nume, extra) {
    const out = path.join(IESIRE, 'raport-' + nume);
    const r = node('raport.cjs', ['scrie', '--comparatii=' + path.join(cmp.dosar, 'comparatii.json'), '--verificare=' + path.join(FIXTURE, 'verificare-proba.json'),
        '--config=' + path.join(FIXTURE, 'paritate.json'), '--iesire=' + out, '--felie=1', '--commit=0000000', '--amprenta-baza=proba', ...(extra || [])], 'raport ' + nume);
    // (un --config= din extra îl înlocuiește pe cel implicit: argumentele se citesc în ordine, ultimul câștigă)
    if (r.status === 2) { throw new Error('raport ' + nume + ': ' + (r.stderr || r.stdout).slice(-600)); }
    return { cod: r.status, dosar: out, r: JSON.parse(fs.readFileSync(path.join(out, 'raport.json'), 'utf8')) };
}

const picate = (c, sonda, pagina) => c.rezultate.filter((r) => (!pagina || r.pagina === pagina) && r.sonde && r.sonde[sonda] && r.sonde[sonda].stare === 'picat');
const detalii = (c, sonda, pagina) => picate(c, sonda, pagina).flatMap((r) => r.sonde[sonda].detalii || []);

async function main() {
    if (fs.existsSync(IESIRE)) { fs.rmSync(IESIRE, { recursive: true, force: true }); }
    fs.mkdirSync(IESIRE, { recursive: true });
    const [pa, pb] = await porturi(2);
    console.log('fixture pe porturile ' + pa + ' și ' + pb + '; ieșirea în ' + IESIRE);
    let a = null;
    let b = null;
    try {
        a = await porneste(pa, 'nimic');
        b = await porneste(pb, 'nimic');
        const ref = captura('referinta', pa, 'referinta');
        const cand0 = captura('candidat', pb, 'candidat-nimic');
        const c0 = compara(ref, cand0, 'nimic');
        const toateSondele = c0.c.rezultate.flatMap((r) => Object.entries(r.sonde || {}).map(([k, s]) => k + ':' + s.stare));
        const rele = c0.c.rezultate.filter((r) => r.eroare || Object.values(r.sonde || {}).some((s) => s.stare !== 'trecut'));
        verifica('1. același fixture pe două servere: 0 diferențe (' + c0.c.rezultate.length + ' capturi: 4 × ' + (c0.c.sonde || []).length + ' sonde + baleiajul antetului × 3)',
            c0.cod === 0 && rele.length === 0 && c0.c.rezultate.length === 5 && toateSondele.length === 47,
            rele.length ? JSON.stringify(rele.map((r) => [r.captura, r.eroare || Object.entries(r.sonde).filter(([, s]) => s.stare !== 'trecut').map(([k, s]) => k + ' ' + JSON.stringify(s.detalii).slice(0, 300))])).slice(0, 1500) : '');
        const r0 = raport(c0, 'nimic', ['--dovezi=' + path.join(FIXTURE, 'dovezi-proba.json'), '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md')]);
        const st = Object.fromEntries(r0.r.puncte.map((p) => [p.id, p.stare]));
        verifica('1. raportul feliei 1 pe același fixture: „trecut”, felia 2 „în așteptare”, P4 amânat, baleiajul antetului pe ID-ul lui',
            r0.cod === 0 && r0.r.verdict === 'trecut' && st['PP-ACASA-010'] === 'în așteptare' && st['PP-ACASA-001'] === 'trecut' && st['PP-GLOBAL-003'] === 'trecut'
            && (r0.r.puncte.find((p) => p.id === 'PP-GLOBAL-003').sonde['P3-geometrie'] || {}).capturi === 1
            && (r0.r.puncte.find((p) => p.id === 'PP-ACASA-002').sonde['P4-dispozitiv'] || {}).stare === 'amânat', JSON.stringify(r0.r.sumar));
        const v0 = node('raport.cjs', ['verifica', path.join(r0.dosar, 'raport.json'), '--sha256=' + r0.r.sha256], 'verifica nimic');
        verifica('1. sha256 al raportului se verifică (commit, amprenta bazei în raport)', v0.status === 0 && r0.r.commit === '0000000' && r0.r.amprenta_baza === 'proba' && /^[0-9a-f]{64}$/.test(r0.r.sha256));
        // 2. fără mască, ceasul trebuie să fie o diferență
        const faraMasca = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        faraMasca.masti = [];
        const fm = path.join(IESIRE, 'paritate-fara-masti.json');
        fs.writeFileSync(fm, JSON.stringify(faraMasca));
        const c1 = compara(ref, cand0, 'fara-masti', fm);
        verifica('2. fără măști, ceasul (diferit la fiecare încărcare) e prins: text și mutatii pe acasa',
            c1.cod === 1 && picate(c1.c, 'text', 'acasa').length === 2 && picate(c1.c, 'mutatii', 'acasa').length === 2 && picate(c1.c, 'text', 'pagina-2').length === 0);
        // 3. diferențele plantate
        const asteptari = {
            pixel: (c) => picate(c, 'pixeli', 'acasa').length === 2 && picate(c, 'pixeli', 'pagina-2').length === 0,
            clasa: (c) => picate(c, 'mutatii', 'acasa').length === 2 && detalii(c, 'mutatii', 'acasa').some((d) => d.tip === 'clase' && (d.candidat_plus || []).includes('card-plantat'))
                && picate(c, 'mutatii', 'pagina-2').length === 0,
            mutatie: (c) => picate(c, 'mutatii', 'acasa').length === 2 && detalii(c, 'mutatii', 'acasa').some((d) => d.tip === 'atribut' && d.atribut === 'aria-expanded'),
            terta: (c) => picate(c, 'retea', 'acasa').length === 2 && detalii(c, 'retea', 'acasa').some((d) => d.tip === 'cerere terță înainte de acord' && /cdn\.terta\.example/.test(d.url)),
            csp: (c) => picate(c, 'csp', 'acasa').length === 2 && detalii(c, 'csp', 'acasa').some((d) => /script-src/.test(d.efectiva || d.directiva || '')) && picate(c, 'csp', 'pagina-2').length === 0,
            tracker: (c) => picate(c, 'retea').length === 4 && detalii(c, 'retea').some((d) => d.tip === 'tracker fără fișier de probă' && /googletagmanager/.test(d.url))
                && detalii(c, 'retea').some((d) => d.tip === 'cerere terță înainte de acord' && /googletagmanager/.test(d.url)),
        };
        let cPixel = null;
        for (const [varianta, ok] of Object.entries(asteptari)) {
            await opreste(b);
            b = await porneste(pb, varianta);
            const cand = captura('candidat', pb, 'candidat-' + varianta);
            const c = compara(ref, cand, varianta);
            if (varianta === 'pixel') { cPixel = c; }
            const sumar = c.c.rezultate.map((r) => r.captura + ' ' + Object.entries(r.sonde || {}).filter(([, s]) => s.stare === 'picat').map(([k, s]) => k + '(' + s.diferente + ')').join(','));
            verifica('3. diferența plantată „' + varianta + '” e prinsă', c.cod === 1 && ok(c.c), sumar.join(' | '));
        }
        // 4. raportul pică fără dovezi și pe o diferență; modificat, nu mai trece verificarea
        const r1 = raport(c0, 'fara-dovezi', ['--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md')]);
        const fd = r1.r.puncte.filter((p) => p.stare === 'fără dovadă').map((p) => p.id).sort();
        verifica('4. un ID fără dovadă face raportul să pice', r1.cod === 1 && r1.r.verdict === 'picat' && fd.join(',') === 'PP-ACASA-001,PP-GLOBAL-002', fd.join(','));
        const r2 = raport(cPixel, 'pixel', ['--dovezi=' + path.join(FIXTURE, 'dovezi-proba.json'), '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md')]);
        verifica('4. o diferență plantată face raportul să pice (PP-ACASA-001 picat)', r2.cod === 1 && r2.r.puncte.find((p) => p.id === 'PP-ACASA-001').stare === 'picat');
        const mod = JSON.parse(fs.readFileSync(path.join(r0.dosar, 'raport.json'), 'utf8'));
        mod.sumar.blocante = 0;
        mod.puncte[0].stare = 'trecut';
        mod.commit = '1111111';
        const fmod = path.join(IESIRE, 'raport-modificat.json');
        fs.writeFileSync(fmod, JSON.stringify(mod, null, 1));
        const v1 = node('raport.cjs', ['verifica', fmod], 'verifica modificat');
        const v2 = node('raport.cjs', ['verifica', path.join(r0.dosar, 'raport.json'), '--sha256=' + '0'.repeat(64)], 'verifica alt sha');
        verifica('4. un raport modificat sau alt sha256 așteptat nu trec verificarea', v1.status === 1 && v2.status === 1);
        // 5. măștile și regiunile
        const mc = (r0.r.masti || []).find((m) => m.selector === '.ceas');
        const p3 = r0.r.puncte.find((p) => p.id === 'PP-ACASA-003');
        const p2 = r0.r.puncte.find((p) => p.id === 'PP-GLOBAL-002');
        verifica('5. raportul listează masca ceasului „aplicată” (2/2 capturi), regiunea lui PP-ACASA-003 și sondele înlocuite ale lui PP-GLOBAL-002',
            !!mc && mc.stare === 'aplicată' && mc.folosire.capturi_prinse === 2 && mc.motiv && (r0.r.neatribuite || []).length === 0
            && p3 && p3.regiune && p3.stare === 'trecut' && Object.values(p3.sonde).every((x) => x.capturi_cu_regiune === 2)
            && p2 && p2.sonde_inlocuite && p2.sonde['P2-http(feed înghețat)'].stare === 'trecut' && p2.sonde['P2-http(404 pentru restul)'].stare === 'trecut',
            JSON.stringify({ masca: mc, p3: p3 && p3.sonde, p2: p2 && p2.sonde }).slice(0, 900));
        const unProfil = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        unProfil.masti[0].profiluri = ['safari-iphone'];
        const fup = path.join(IESIRE, 'paritate-un-profil.json');
        fs.writeFileSync(fup, JSON.stringify(unProfil));
        const c5 = compara(ref, cand0, 'un-profil', fup);
        verifica('5. o mască pe un singur profil (safari-iphone) nu lucrează pe chrome-win: ceasul diferă doar acolo',
            c5.cod === 1 && picate(c5.c, 'text', 'acasa').length === 1 && picate(c5.c, 'text', 'acasa')[0].profil === 'chrome-win');
        // (fără P3-scenariu pe PP-ACASA-002, care ar acoperi toate sondele paginii)
        const faraScenariu = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        faraScenariu.harta.id['PP-ACASA-002'] = { sonde: ['P3-mutatii', 'P4-dispozitiv'], motiv_sonde: 'autotest: fără scenariu' };
        const ffs = path.join(IESIRE, 'paritate-fara-scenariu.json');
        fs.writeFileSync(ffs, JSON.stringify(faraScenariu));
        const c5b = compara(ref, cPixel.c.candidat, 'pixel-regiune', ffs);
        const r5 = raport(c5b, 'pixel-regiune', ['--dovezi=' + path.join(FIXTURE, 'dovezi-proba.json'), '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md'), '--config=' + ffs]);
        const p35 = r5.r.puncte.find((p) => p.id === 'PP-ACASA-003');
        const nea = (r5.r.neatribuite || []).filter((n) => n.sonda === 'imagini');
        verifica('5. poza schimbată e în afara regiunii lui PP-ACASA-003 (.carduri): ID-ul trece, diferența imaginii e „neatribuită” și raportul pică',
            r5.cod === 1 && r5.r.verdict === 'picat' && p35.sonde['P3-imagini'].stare === 'trecut' && nea.length === 2 && (r5.r.neatribuite || []).every((n) => n.sonda === 'imagini'),
            JSON.stringify(r5.r.neatribuite).slice(0, 600));
        const gol = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        gol.harta.id['PP-ACASA-003'].regiune = '.nu-exista';
        gol.masti.push({ nume: 'mască fără țintă (test)', selector: '.nu-exista-nici', motiv: 'autotest: nu prinde nimic' });
        const fgol = path.join(IESIRE, 'paritate-regiune-goala.json');
        fs.writeFileSync(fgol, JSON.stringify(gol));
        const c6 = compara(ref, cand0, 'regiune-goala', fgol);
        const r6 = raport(c6, 'regiune-goala', ['--dovezi=' + path.join(FIXTURE, 'dovezi-proba.json'), '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md'), '--config=' + fgol]);
        const mg = r6.r.masti.find((m) => m.selector === '.nu-exista-nici');
        verifica('5. o regiune care nu prinde nimic lasă ID-ul „fără dovadă”; o mască fără țintă e „neaplicată”',
            r6.cod === 1 && r6.r.puncte.find((p) => p.id === 'PP-ACASA-003').stare === 'fără dovadă' && mg && mg.stare === 'neaplicată', JSON.stringify(r6.r.sumar));
        const doarGol = path.join(IESIRE, 'dovezi-fara-calificativ.json');
        fs.writeFileSync(doarGol, JSON.stringify({ dovezi: [{ id: 'PP-ACASA-001', sonda: 'P1-octeti', stare: 'trecut' }, { id: '*', sonda: 'P2-http', stare: 'trecut' }] }));
        const r7 = raport(c0, 'fara-calificativ', ['--dovezi=' + doarGol, '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md')]);
        verifica('5. două sonde P2-http cu calificativ nu se mulțumesc cu o dovadă P2-http fără calificativ',
            r7.cod === 1 && r7.r.puncte.find((p) => p.id === 'PP-GLOBAL-002').stare === 'fără dovadă');
        // 5. o mască pe un atribut: ascunde atributul plantat pe .card-3, nu și clasa plantată pe .card-2
        const peAtribut = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        peAtribut.masti.push({ nume: 'atributul aria-expanded al cardului 3 (test)', selector: '.card-3', atribute: ['aria-expanded'], sonde: ['mutatii'], motiv: 'autotest' },
            { nume: 'o clasă a cardului 2 care nu e cea plantată (test)', selector: '.card-2', clase: ['card-alta'], sonde: ['mutatii'], motiv: 'autotest' });
        const fpa = path.join(IESIRE, 'paritate-pe-atribut.json');
        fs.writeFileSync(fpa, JSON.stringify(peAtribut));
        const c9 = compara(ref, path.join(IESIRE, 'candidat-mutatie'), 'pe-atribut-mutatie', fpa);
        const c10 = compara(ref, path.join(IESIRE, 'candidat-clasa'), 'pe-atribut-clasa', fpa);
        verifica('5. o mască pe atribut ascunde doar atributul ei (aria-expanded pe .card-3); clasa plantată pe .card-2 rămâne prinsă',
            picate(c9.c, 'mutatii', 'acasa').length === 0 && picate(c10.c, 'mutatii', 'acasa').length === 2
            && detalii(c10.c, 'mutatii', 'acasa').some((d) => d.tip === 'clase' && (d.candidat_plus || []).includes('card-plantat')));
        // 6. zgomotul: a treia încărcare a referinței, fără masca ceasului
        const ref2 = captura('referinta', pa, 'referinta-2');
        const faraCeas = JSON.parse(fs.readFileSync(path.join(FIXTURE, 'paritate.json'), 'utf8'));
        faraCeas.masti = [];
        const fz = path.join(IESIRE, 'paritate-zgomot.json');
        fs.writeFileSync(fz, JSON.stringify(faraCeas, null, 1));
        const z = node('zgomot.cjs', ['--incarcari=' + [ref, cand0, ref2].join(','), '--config=' + fz, '--iesire=' + path.join(IESIRE, 'zgomot'), '--scrie=1'], 'zgomot');
        const prop = z.status === 0 ? JSON.parse(fs.readFileSync(path.join(IESIRE, 'zgomot', 'propuneri.json'), 'utf8')) : { masti: [] };
        const scrise = JSON.parse(fs.readFileSync(fz, 'utf8')).masti || [];
        const toateCeas = prop.masti.length === 1 && prop.masti.every((m) => m.selector === '.ceas' && m.propus_automat && m.motiv === 'instabil pe referință (3/3 încărcări)'
            && m.pagini.join() === 'acasa' && m.capturi.join() === 'acasa/chrome-win/1440x900/incarcare,acasa/safari-iphone/390x844/incarcare' && m.sonde.includes('text') && m.sonde.includes('mutatii'));
        verifica('6. zgomot.cjs propune o singură mască, a ceasului (.ceas, „instabil pe referință (3/3 încărcări)”, pe cele 2 capturi ale lui), și nimic altceva; o scrie în config',
            z.status === 0 && toateCeas && scrise.length === prop.masti.length && scrise.every((m) => m.propus_automat) && prop.de_decis.length === 0,
            JSON.stringify(prop.masti.map((m) => [m.nume, m.selector || m.dreptunghiuri, m.motiv, m.sonde])).slice(0, 900) + ' de_decis=' + JSON.stringify(prop.de_decis).slice(0, 300)
            + ' doar_in_captura=' + JSON.stringify(prop.doar_in_captura).slice(0, 300));
        const c8 = compara(ref, cand0, 'cu-zgomot', fz);
        const r8 = raport(c8, 'cu-zgomot', ['--dovezi=' + path.join(FIXTURE, 'dovezi-proba.json'), '--declaratii=' + path.join(FIXTURE, 'ce-se-schimba.md'), '--config=' + fz]);
        verifica('6. cu măștile propuse: 0 diferențe, raportul „trecut” și lista lui are măștile propuse automat, aplicate',
            c8.cod === 0 && r8.cod === 0 && r8.r.masti.filter((m) => m.propus_automat && m.stare === 'aplicată').length === prop.masti.length, JSON.stringify(r8.r.sumar));
        // stările observate: candidatul (a doua încărcare) cade pe starea lui; fără ea, culoarea ceasului e o diferență
        const mz = r8.r.masti.find((m) => m.propus_automat);
        const faraStare = JSON.parse(fs.readFileSync(fz, 'utf8'));
        for (const m of faraStare.masti) {
            for (const pe of Object.values(m.stari_observate || {})) { for (const x of Object.values(pe)) { x.stari = x.stari.filter((st) => !st.incarcari.includes('candidat-nimic')); } }
        }
        const ffs2 = path.join(IESIRE, 'paritate-fara-stare.json');
        fs.writeFileSync(ffs2, JSON.stringify(faraStare));
        const c11 = compara(ref, cand0, 'fara-stare', ffs2);
        verifica('6. masca ceasului ține stările observate: raportul spune că candidatul a căzut pe starea lui (2 capturi); o stare neobservată e o diferență de stil',
            !!mz && mz.stari_candidat && mz.stari_candidat['starea încărcărilor candidat-nimic'] === 2 && picate(c11.c, 'stil', 'acasa').length === 2
            && detalii(c11.c, 'stil', 'acasa').some((d) => /în afara stărilor observate/.test(d.tip || '')), JSON.stringify(mz && mz.stari_candidat));
    } finally {
        await opreste(a);
        await opreste(b);
    }
    // Recenzia 2, R3-6: o ieșire în repo și configurația din pachetul public al unui site sunt refuzate (codul 2), nimic scris.
    {
        const RAD = path.resolve(AICI, '../../..');
        const inRepo = path.join(RAD, 'storage', 'logs', 'dev', 'paritate-in-repo-' + process.pid);
        const r1 = node('compara.cjs', ['--referinta=' + IESIRE, '--candidat=' + IESIRE, '--config=' + path.join(FIXTURE, 'paritate.json'), '--iesire=' + inRepo], 'ieșire în repo');
        const r2 = node('compara.cjs', ['--referinta=' + IESIRE, '--candidat=' + IESIRE, '--config=' + path.join(RAD, 'site-uri', 'proba', 'paritate.json'), '--iesire=' + path.join(IESIRE, 'cfg')], 'config publică');
        verifica('ieșirea în repo și paritate.json din pachetul public: refuzate (cod 2), nimic scris', r1.status === 2 && !fs.existsSync(inRepo) && r2.status === 2,
            r1.status + ' ' + r2.status);
        // Recenzia 3, R3-6: fiecare program al harness-ului care scrie refuză o ieșire în repo (codul 2), nimic scris.
        const gol = path.join(IESIRE, 'gol.jsonl');
        fs.writeFileSync(gol, '');
        fs.mkdirSync(path.join(IESIRE, 'capturi-goale'), { recursive: true });
        const cfgCaptura = path.join(IESIRE, 'config-in-repo.json');
        fs.writeFileSync(cfgCaptura, JSON.stringify({ rol: 'candidat', site: 'http://127.0.0.1:1', iesire: inRepo + '-c', pagini: [], matrice: [] }));
        const programe = {
            'captura.cjs': [cfgCaptura],
            'jurnal.cjs': ['--jurnal=' + gol, '--iesire=' + inRepo + '-j/jurnal-resurse.json'],
            'mutatii.cjs': ['--capturi=' + path.join(IESIRE, 'capturi-goale'), '--iesire=' + inRepo + '-m/mutatii.json'],
            'zgomot.cjs': ['--incarcari=' + IESIRE + ',' + IESIRE, '--config=' + path.join(FIXTURE, 'paritate.json'), '--iesire=' + inRepo + '-z'],
            'raport.cjs': ['scrie', '--comparatii=' + gol, '--verificare=' + gol, '--config=' + path.join(FIXTURE, 'paritate.json'), '--iesire=' + inRepo + '-r'],
        };
        const rele = [];
        for (const [prog, args] of Object.entries(programe)) {
            const r = node(prog, args, 'în repo: ' + prog);
            const scris = ['-c', '-j', '-m', '-z', '-r'].some((x) => fs.existsSync(inRepo + x));
            if (r.status !== 2 || scris) { rele.push(prog + ' → ' + r.status + (scris ? ' (a scris)' : '')); }
        }
        verifica('fiecare program care scrie (captura, jurnal, mutatii, zgomot, raport; compara mai sus) refuză o ieșire în repo, cod 2, nimic scris',
            rele.length === 0, rele.join('; '));
    }
    const ok = rezultate.filter((r) => r.ok).length;
    console.log('G-paritate: ' + ok + '/' + rezultate.length + ' verificări trecute' + (ok === rezultate.length ? '' : ' — EȘEC'));
    return ok === rezultate.length ? 0 : 1;
}

main().then((c) => { process.exitCode = c; }).catch((e) => { console.error('EROARE ' + (e && e.stack || e)); process.exit(2); });
