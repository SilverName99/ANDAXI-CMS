// Poarta G-compat (adoptarea unui site WordPress, pasul 8a; INSTRUCTIUNI-BUILDER-C2 §8): runtime-ul compat din
// public/assets/compat/ în Chromium (Playwright), pe pagini INVENTATE („Pensiunea Probă”: nicio pagină, niciun DOM și niciun CSS
// al unui client). O pornește tools/dev/compat.sh (poarta din tools/dev/porti.sh).
//
// Un server HTTP mic, pe 127.0.0.1 (CMS_DEV_COMPAT_PORT, implicit un port liber), servește fișierele runtime-ului din repo și
// paginile de probă, cu CSP-ul site-ului (C2 §6: script-src 'self' cu 'inline-speculation-rules', fără 'unsafe-inline'/'unsafe-eval',
// worker-src 'none'…) și nosniff pe tot. Sloturile {{ax:runtime:cap}} / {{ax:runtime:final}} se umplu din manifestul
// divi4/v1/sloturi.json, cum o face cadrul capturat (pasul 6, cu ?v=<12 hex din sha256>). Orice cerere spre altă gazdă e oprită în
// browser și pică verificarea. Verifică:
//  - manifestul și fișierele (fiecare cale există, ordinea pieselor, niciun fișier uitat, sintaxa, fără eval/innerHTML/HTML din
//    date, un singur apel de consolă — refuzul lui ax-date);
//  - incarcare: html.js din head înaintea corpului, momentele (imediat, DOM gata, load) în ordinea înregistrării, izolarea unui
//    modul care aruncă, copiile din date(), ax-date lipsă/invalid/dublu/listă/alt element/suprapus de un <style id="ax-date">
//    (înaintea blocului) sau dublat după slotul final: niciun modul, nicio excepție, un singur rând în consolă; cheile de poluare a
//    prototipului scoase; adresaSigura (https sau aceeași origine); accesul fără excepții la cookie-uri și Web Storage;
//  - tarziu: preload → stylesheet la load-ul foii, pe loc, și pentru foile venite înaintea scriptului (Resource Timing) sau încă
//    în drum; 404 rămâne preload; foaia late din `file` la locul ei, o dată, doar cu adrese sigure, înainte de DOMContentLoaded și
//    cel târziu la prima desenare (și fără requestAnimationFrame); ordinea CSSOM; browserul fără preload;
//  - clase-ua: tabelul regulii pe UA-uri (cele observate și implicitele OBS-03), clasele puse în pagină pe 7 profiluri, înainte
//    de DOMContentLoaded, fără reacție la resize; display:block pe <body> după load în Safari;
//  - momentele OBS-01: structura, fit-vids și containerul dg după DOMContentLoaded;
//  - structura (felia 1, D1, OBS-04): et_pb_column_single, et_pb_row_<mărimi> (rândurile interioare din conținut și rândul
//    „specialty” din antet), z-index-ul rândului din antet și al celui din subsol, lista et-last-child din ax-date (și refuzurile
//    ei), cu efectul în stilurile calculate; fit-vids-style cu textul observat, ultimul din head la momentul lui (un <style> pus
//    mai târziu vine după el); containerul .dg-carousel-lightbox la sfârșitul lui body, cu marcajul exact, ascuns, fără cereri;
//  - DOM-ul de după scripturi = DOM-ul fără JS plus exact mutațiile așteptate; nicio încălcare CSP (și doi martori: un script
//    inline e blocat; speculationrules fără 'inline-speculation-rules' e blocat); totul și în previzualizarea din admin (iframe
//    sandbox="allow-scripts", cu adresă și srcdoc, origine opacă);
//  - idempotența: fiecare fișier inclus de două ori și fiecare piesă rulată din nou nu schimbă nimic.
//
// Raport: $CMS_DEV_DIR/compat-raport.txt. Cod de ieșire 0 doar dacă trec toate verificările.
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const path = require('path');
const vm = require('vm');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  console.error('EROARE: nu găsesc modulul playwright (NODE_PATH): ' + e.message);
  process.exit(2);
}

const RADACINA = path.resolve(__dirname, '..', '..');
const COMPAT = path.join(RADACINA, 'public', 'assets', 'compat');
const FIXTURI = path.join(__dirname, 'compat-fixturi');
const MANIFEST = path.join(COMPAT, 'divi4', 'v1', 'sloturi.json');
const DOSAR_DEV = process.env.CMS_DEV_DIR || path.join(RADACINA, 'storage', 'logs', 'dev');
const RAPORT = path.join(DOSAR_DEV, 'compat-raport.txt');
const PORT = Number(process.env.CMS_DEV_COMPAT_PORT || 0);

// CSP-ul paginilor capturate (C2 §6), fără sursele trackerelor (nu există în fixture) și fără upgrade-insecure-requests
// (serverul de probă e http); frame-ancestors ca la previzualizare. 'inline-speculation-rules': blocul speculationrules rămâne
// în pagini (D3), iar Chromium îi aplică script-src (martorul de mai jos). Gazda previzualizării are politica ei; un cadru srcdoc
// moștenește politica gazdei, deci și ea are nevoie de cuvânt (previzualizarea planificată e cu adresă, C2 §6, cu politica site-ului).
const politica = (script) => ["default-src 'self'", script, "style-src 'self' 'unsafe-inline'", "img-src 'self' data:", "font-src 'self' data:",
  "connect-src 'self'", 'frame-src https://www.google.com', "object-src 'none'", "base-uri 'self'", "form-action 'self'",
  "worker-src 'none'", "frame-ancestors 'self'"].join('; ');
const CSP_SITE = politica("script-src 'self' 'inline-speculation-rules'");
const CSP_FARA_SPECULATII = politica("script-src 'self'");
const CSP_GAZDA = ["default-src 'self'", "script-src 'self' 'inline-speculation-rules'", "style-src 'self' 'unsafe-inline'", "frame-src 'self'",
  "object-src 'none'", "base-uri 'self'"].join('; ');

const UA = {
  chromeWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  chromeMac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
  android: 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  firefox: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
  safariMac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
  edgeChromium: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
  edgeVechi: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.102 Safari/537.36 Edge/18.19582',
  opera: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/116.0.0.0',
  operaPresto: 'Opera/9.80 (Windows NT 6.1) Presto/2.12.388 Version/12.18',
  ie11: 'Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko',
  ie10: 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)',
  ipadVechi: 'Mozilla/5.0 (iPad; CPU OS 12_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1.2 Mobile/15E148 Safari/604.1',
  crios: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1',
  webviewIos: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
};

// ---------------------------------------------------------------------------------------------------------------------
// Rezultatele
// ---------------------------------------------------------------------------------------------------------------------
const rezultate = [];
const iesire = [];
function scrie(rand) {
  iesire.push(rand);
  console.log(rand);
}
function verifica(eticheta, ok, detalii) {
  rezultate.push(!!ok);
  let rand = (ok ? '  ok    ' : '  EȘEC  ') + eticheta;
  if (!ok && detalii !== undefined) {
    rand += '\n          ' + (typeof detalii === 'string' ? detalii : JSON.stringify(detalii)).slice(0, 1500);
  }
  scrie(rand);
}
const egal = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// ---------------------------------------------------------------------------------------------------------------------
// Manifestul și paginile de probă (cadrul capturat simulat)
// ---------------------------------------------------------------------------------------------------------------------
let manifest = null;
try {
  manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'));
} catch (e) {
  manifest = { eroare: e.message };
}
// Ca adresele puse de cadru: /assets/compat/<cale>?v=<primele 12 caractere hex ale sha256-ului fișierului>.
const spreAdresa = (cale) => {
  const fisier = fisierSub(COMPAT, cale);
  const v = fisier ? crypto.createHash('sha256').update(fs.readFileSync(fisier)).digest('hex').slice(0, 12) : 'lipsa';
  return '/assets/compat/' + cale + '?v=' + v;
};
const capImplicit = () => (Array.isArray(manifest.cap) ? manifest.cap.map(spreAdresa) : []);
const finalImplicit = () => (Array.isArray(manifest.final) ? manifest.final.map(spreAdresa) : []).concat(['/fixturi/module-proba.js']);

const DATE_IMPLICITE = { file: ['/fixturi/css/late.css'], et_pb_custom: { page_id: '7', ignore_waypoints: 'no' },
  proba: { lista: [1, 2, 3], text: '<b> & </script>' }, ax_structura: { 'et-last-child': ['#sectiune-speciala > .et_pb_row'] } };

// Ca Divi4::json(): „<”, „>” și „&” scăpate, deci nimic nu poate închide blocul.
const jsonAxDate = (obiect) => JSON.stringify(obiect).replace(/</g, '\\u003C').replace(/>/g, '\\u003E').replace(/&/g, '\\u0026');
const atribut = (s) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
const BODY_SERVER = 'page-template-default page page-id-7 et_pb_pagebuilder_layout et_divi_theme';
const CLOBBER = '<style id="ax-date">.proba-clobber{color:rgb(9, 9, 9)}</style>';
const SPECULATII = '<script type="speculationrules">{"prefetch":[{"source":"document","where":{"and":[{"href_matches":"/*"},'
  + '{"not":{"href_matches":["/wp-*.php","/wp-admin/*"]}}]},"eagerness":"conservative"}]}</script>';
// Regulile foii fit-vids-style (ca pe live, OBS-R-extra), comparate pe reguli (tools/dev/paritate-wp/lib/reguli-css.cjs).
const REGULI_CSS = require('./paritate-wp/lib/reguli-css.cjs');
const FIT_VIDS_REGULI = ['.fluid-width-video-wrapper{padding:0;position:relative;width:100%}',
  ...['embed', 'iframe', 'object'].map((t) => '.fluid-width-video-wrapper ' + t + '{height:100%;left:0;position:absolute;top:0;width:100%}')].sort();
// Structura containerului dg (ca în modul: element cu element), serializată pentru comparație.
const DG_STRUCTURA = ['div', 'dg-carousel-lightbox', [['div', 'lightbox-header', [['button', 'close-btn', '\u2573']]],
  ['div', 'image-wrapper', [['span', null, [['img', null, null, { src: '' }]]]]]]];
const serializeaza = ([tag, clasa, copii, atr]) => '<' + tag + (clasa ? ' class="' + clasa + '"' : '')
  + Object.entries(atr || {}).map(([k, v]) => ' ' + k + '="' + v + '"').join('') + '>'
  + (tag === 'img' ? '' : (typeof copii === 'string' ? copii : (copii || []).map(serializeaza).join('')) + '</' + tag + '>');
const DG_MARCAJ = serializeaza(DG_STRUCTURA);

// Structura inventată „ca Divi”: antetul (un rând cu o coloană 1/4 lângă o coloană „specialty” 3/4, cu un rând interior 3/8 3/8),
// conținutul (o secțiune obișnuită, una „specialty” — rândul principal tot cu et_pb_gutters1, ca în antet — cu rânduri interioare
// de mărimi diferite, una cu un rând obișnuit cu două coloane) și subsolul (rândul cu meniul, un rând interior cu două coloane).
const CORP = '<div id="page-container">\n<div id="et-boc" class="et-boc">\n'
  + '<header class="et-l et-l--header"><div class="et_builder_inner_content et_pb_gutters3">'
  + '<div class="et_pb_section et_pb_section_0_tb_header et_section_specialty"><div class="et_pb_row et_pb_row_1_tb_header et_pb_gutters1">'
  + '<div class="et_pb_column et_pb_column_1_4 et_pb_column_0_tb_header"></div>'
  + '<div class="et_pb_column et_pb_column_3_4 et_pb_column_1_tb_header et_pb_specialty_column">'
  + '<div class="et_pb_row_inner et_pb_row_inner_0_tb_header">'
  + '<div class="et_pb_column et_pb_column_3_8 et_pb_column_inner et_pb_column_inner_0_tb_header"></div>'
  + '<div class="et_pb_column et_pb_column_3_8 et_pb_column_inner et_pb_column_inner_1_tb_header"></div>'
  + '</div></div></div></div></div></header>\n'
  + '<div id="et-main-area"><div class="et-l et-l--post"><div class="et_builder_inner_content et_pb_gutters3">\n'
  + '<div class="et_pb_section et_pb_section_0"><p class="proba-tarziu proba-tarziu-2 proba-ordine proba-amanat proba-dinamic proba-inainte">'
  + 'Camera dublă cu vedere spre livadă – Pensiunea Probă</p></div>\n'
  + '<div id="sectiune-speciala" class="et_pb_section et_pb_section_1 et_section_specialty"><div class="et_pb_row et_pb_gutters1">'
  + '<div class="et_pb_column et_pb_column_1_4 et_pb_column_2"></div>'
  + '<div class="et_pb_column et_pb_column_3_4 et_pb_column_3 et_pb_specialty_column">'
  + '<div class="et_pb_row_inner et_pb_row_inner_1">'
  + '<div class="et_pb_column et_pb_column_1_6 et_pb_column_inner et_pb_column_inner_0"></div>'
  + '<div class="et_pb_column et_pb_column_1_6 et_pb_column_inner et_pb_column_inner_1"></div>'
  + '<div class="et_pb_column et_pb_column_1_6 et_pb_column_inner et_pb_column_inner_2"></div></div>'
  + '<div class="et_pb_row_inner et_pb_row_inner_2">'
  + '<div class="et_pb_column et_pb_column_1_4 et_pb_column_inner et_pb_column_inner_3"></div>'
  + '<div class="et_pb_column et_pb_column_1_4 et_pb_column_inner et_pb_column_inner_4"></div></div>'
  + '<div class="et_pb_row_inner et_pb_row_inner_3"><div class="et_pb_column et_pb_column_4_4 et_pb_column_inner et_pb_column_inner_5"></div></div>'
  + '<div class="et_pb_row_inner et_pb_row_inner_4">'
  + '<div class="et_pb_column et_pb_column_1_2 et_pb_column_inner et_pb_column_inner_6"></div>'
  + '<div class="et_pb_column et_pb_column_inner et_pb_column_inner_7"></div></div>'
  + '</div></div></div>\n'
  + '<div class="et_pb_section et_pb_section_2"><div class="et_pb_row et_pb_row_0">'
  + '<div class="et_pb_column et_pb_column_1_2 et_pb_column_4"></div><div class="et_pb_column et_pb_column_1_2 et_pb_column_5"></div></div></div>\n'
  + '</div></div></div>\n'
  + '<footer class="et-l et-l--footer"><div class="et_builder_inner_content et_pb_gutters3"><div class="et_pb_section et_pb_section_0_tb_footer">'
  + '<div class="et_pb_row et_pb_row_0_tb_footer"><div class="et_pb_column et_pb_column_4_4 et_pb_column_0_tb_footer">Meniul subsolului</div></div>'
  + '<div class="et_pb_row et_pb_row_1_tb_footer"><div class="et_pb_column et_pb_column_4_4 et_pb_column_1_tb_footer">'
  + '<div class="et_pb_row_inner et_pb_row_inner_0_tb_footer">'
  + '<div class="et_pb_column et_pb_column_1_2 et_pb_column_inner et_pb_column_inner_0_tb_footer"></div>'
  + '<div class="et_pb_column et_pb_column_1_2 et_pb_column_inner et_pb_column_inner_1_tb_footer"></div></div></div></div>'
  + '</div></div></footer>\n</div>\n</div>\n';

// Două module meniu „ca Divi” (antetul și subsolul), inventate: lista desktop și containerul telefonului (meniu-mobil.md §1).
const meniuDivi = (n, curenta) => '<div class="et_pb_module et_pb_menu et_pb_menu_' + n + '"><div class="et_pb_menu_inner_container clearfix">'
  + '<div class="et_pb_menu__wrap"><div class="et_pb_menu__menu"><nav class="et-menu-nav"><ul id="menu-principal-' + n + '" class="et-menu nav">'
  + '<li class="menu-item menu-item-home menu-item-' + n + '1" data-ax-slot="meniu"><a href="/pagina/meniu#acasa">Acasă</a></li>\n'
  + '<li id="menu-item-' + n + '2" class="menu-item menu-item-' + n + '2' + (curenta ? ' current-menu-item' : '') + '" data-ax-slot="meniu">'
  + '<a href="/pagina/meniu#camere"' + (curenta ? ' aria-current="page"' : '') + '>Camere</a></li>\n'
  + '<li class="menu-item menu-item-' + n + '3" data-ax-slot="meniu"><a href="/pagina/meniu#contact">Contact</a></li>\n'
  + '</ul></nav></div>\n<div class="et_mobile_nav_menu">\n<div class="mobile_nav closed">\n<span class="mobile_menu_bar"></span>\n</div>\n</div></div>'
  + '</div></div>';
const CORP_MENIU = '<div id="page-container">\n<header class="et-l et-l--header">' + meniuDivi(1, true) + '</header>\n'
  + '<div id="et-main-area"><p class="proba-text">Pensiunea Probă – camere la munte</p>'
  + '<div class="et_mobile_nav_menu"><div class="mobile_nav closed"><span class="mobile_menu_bar"></span></div></div></div>\n'
  + '<footer class="et-l et-l--footer">' + meniuDivi(2, false) + '</footer>\n</div>\n';
// CSS-ul capturat al meniului (ca live, inventat ca valori): lista ascunsă, rândurile de 51 px.
const STIL_MENIU = '.et_mobile_menu{display:none;position:absolute;left:0;width:100%;padding:0;margin:0;list-style:none}'
  + '.mobile_nav{position:relative}.mobile_menu_bar{display:block;height:32px;width:32px}.et_mobile_menu li a{display:block;height:51px;line-height:51px}';

// Ce așteaptă structura pe acest corp (selectorul elementului → clasele adăugate, în ordine).
const STRUCTURA_ASTEPTATA = {
  '.et_pb_row_1_tb_header': ['et_pb_row_1-4_3-4'],
  '.et_pb_column_0_tb_header': ['et_pb_column_single'],
  '.et_pb_column_1_tb_header': [],
  '.et_pb_row_inner_0_tb_header': [],
  '.et_pb_column_2': ['et_pb_column_single'],
  '.et_pb_column_3': [],
  '.et_pb_row_inner_1': ['et_pb_row_1-6_1-6_1-6'],
  '.et_pb_row_inner_2': ['et_pb_row_1-4_1-4'],
  '.et_pb_row_inner_3': [],
  '.et_pb_row_inner_4': [],
  '#sectiune-speciala > .et_pb_row': ['et-last-child'],
  '.et_pb_row_0': [],
  '.et_pb_row_inner_0_tb_footer': [],
};

function pagina(o) {
  o = Object.assign({ date: DATE_IMPLICITE, cap: capImplicit(), final: finalImplicit(), repere: 'ambele', inainte: false,
    lipsa: false, inline: false, dateDuble: false, dateDiv: false, dateInainteDeCap: false, corpDevreme: false, clobber: null,
    dupaFinal: false, speculatii: true, corp: CORP, stil: '' }, o || {});
  const bloc = o.date === null ? '' : '<script type="application/json" id="ax-date">'
    + (typeof o.date === 'string' ? o.date : jsonAxDate(o.date)) + '</script>';
  const sloturi = (lista) => lista.map((a) => '<script src="' + a + '"></script>').join('\n');
  const html = '<!DOCTYPE html>\n<html lang="ro-RO">\n<head>\n<meta charset="UTF-8">\n'
    + '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n'
    + '<script src="/fixturi/observator.js"></script>\n'
    + (o.corpDevreme ? '<p hidden>Pensiunea Probă</p>\n' : '')
    + (o.clobber === 'cap' ? CLOBBER + '\n' : '')
    + (o.inainte ? '<link rel="preload" as="style" id="proba-inainte-css" href="/fixturi/css/inainte.css?ver=1" media="all" data-ax-tarziu>\n' : '')
    + (o.inainteLipsa ? '<link rel="preload" as="style" id="proba-inainte-lipsa-css" href="/fixturi/css/lipsa-inainte.css?ver=1" media="all" data-ax-tarziu>\n' : '')
    + (o.dateInainteDeCap ? bloc + '{{ax:runtime:cap}}\n' : '{{ax:runtime:cap}}' + (o.clobber === 'bloc' ? CLOBBER : '') + bloc + '\n')
    + '<title>Camere – Pensiunea Probă</title>\n'
    + '<link rel="stylesheet" id="proba-global-css" href="/fixturi/css/global.css?ver=1" media="all">\n'
    + '<style id="global-styles-inline-css">.proba-global-2{padding-top:1px}.et_pb_column_single{padding-top:7px}'
    + '.et_pb_row_1-6_1-6_1-6>.et_pb_column{padding-bottom:8px}.dg-carousel-lightbox{display:none}</style>\n'
    + (o.repere === 'ambele' ? '<style id="divi-style-inline-inline-css">.proba-tema{padding-top:2px}</style>\n' : '')
    + (o.repere !== 'niciunul' ? '<style id="divi-dynamic-critical-inline-css">.proba-critic{padding-top:3px}.proba-ordine{color:rgb(3, 3, 3)}</style>\n' : '')
    + '<link rel="preload" as="style" id="divi-dynamic-late-css" href="/fixturi/css/late.css?ver=4.27.4" media="all" data-ax-tarziu>\n'
    + '<link rel="preload" as="style" id="divi-dynamic-css" href="/fixturi/css/dinamic.css?ver=4.27.4" media="all" data-ax-tarziu>\n'
    + '<style id="et-critical-inline-css">.proba-critic-2{padding-top:4px}</style>\n'
    + '<link rel="preload" as="style" id="et-core-unified-deferred-7-cached-inline-styles" href="/fixturi/css/amanat.min.css?ver=1" data-ax-tarziu>\n'
    + (o.lipsa ? '<link rel="preload" as="style" id="proba-lipsa-css" href="/fixturi/css/lipsa.css?ver=1" media="all" data-ax-tarziu>\n' : '')
    + (o.speculatii ? SPECULATII + '\n' : '')
    + (o.stil ? '<style id="proba-pagina-css">' + o.stil + '</style>\n' : '')
    + '</head>\n<body class="' + BODY_SERVER + '">\n' + (o.dateDiv ? '<div id="ax-date"></div>\n' : '') + o.corp
    + (o.dateDuble ? bloc + '\n' : '')
    + (o.inline ? '<script>window.__axProba.inline = 1;</script>\n' : '')
    + '{{ax:runtime:final}}\n<span class="et_pb_scroll_top et-pb-icon"></span>\n'
    + (o.dupaFinal ? '<script type="application/json" id="ax-date">{}</script>\n' : '')
    + '</body>\n</html>\n';
  const rez = html.replace('{{ax:runtime:cap}}', sloturi(o.cap) + '\n').replace('{{ax:runtime:final}}', sloturi(o.final));
  if (rez.includes('{{ax:')) {
    throw new Error('un jeton {{ax: a rămas în pagina de probă (cadrul ar da 500)');
  }
  return rez;
}

// Previzualizarea din admin: aceeași pagină în două cadre izolate (cu adresă și srcdoc), fără allow-same-origin.
function gazda(nume, o) {
  return '<!DOCTYPE html>\n<html lang="ro-RO">\n<head>\n<meta charset="UTF-8">\n<title>Previzualizare – Pensiunea Probă</title>\n</head>\n<body>\n'
    + '<iframe name="cu-adresa" title="Previzualizare" sandbox="allow-scripts" src="/pagina/' + nume + '" width="1200" height="700"></iframe>\n'
    + '<iframe name="srcdoc" title="Previzualizare (srcdoc)" sandbox="allow-scripts" srcdoc="' + atribut(pagina(o)) + '" width="1200" height="700"></iframe>\n'
    + '</body>\n</html>\n';
}

// Scenariile paginilor (opțiunile lui pagina()), după nume: /pagina/<nume> și /gazda/<nume>.
const PAGINI = {
  baza: {},
  'inainte-venita': { inainte: true },
  'inainte-in-curs': { inainte: true },
  'inainte-lipsa': { inainteLipsa: true },
  lipsa: { lipsa: true },
  'fara-date': { date: null },
  'json-invalid': { date: '{"file":["/fixturi/css/late.css"],' },
  'date-duble': { dateDuble: true },
  'date-lista': { date: '[{"file":["/fixturi/css/late.css"]}]' },
  'date-div': { date: null, dateDiv: true },
  'date-clobber': { clobber: 'bloc' },
  'date-clobber-cap': { clobber: 'cap' },
  'date-dupa-final': { dupaFinal: true },
  proto: { date: '{"file":["/fixturi/css/late.css"],"proba":{"lista":[1,2,3],"text":"x"},"__proto__":{"poluat":1},'
    + '"rau":{"constructor":{"prototype":{"poluat":2}},"bun":1,"adanc":{"__proto__":{"poluat":3},"ok":true}}}' },
  dublu: { cap: capImplicit().concat(capImplicit(), ['/fixturi/modul-cap.js']), final: finalImplicit().concat(finalImplicit()) },
  'file-multe': { date: { file: ['/fixturi/css/late.css', '/fixturi/css/late-2.css', 'javascript:alert(1)', 7, '/fixturi/css/late.css',
    'http://alt.example/x.css', '//alt.example/x.css', '/\\alt.example/x.css', ' java\tscript:alert(1)'], proba: { lista: [1, 2, 3] } } },
  'file-text': { date: { file: '/fixturi/css/late.css', proba: { lista: [1, 2, 3] } } },
  'doar-critic': { repere: 'critic' },
  'fara-repere': { repere: 'niciunul' },
  'fara-preload': {},
  'fara-cadru': {},
  'structura-rea': { date: { file: ['/fixturi/css/late.css'], ax_structura: { 'et-last-child': ['#sectiune-speciala > .et_pb_row',
    'div[onclick]', 'p:has(b)', '#x, #y', 7, '>>'], 'alta-clasa': ['.et_pb_row_0'], 'et-fara-lista': '#y' } } },
  'structura-fara-lista': { date: { file: ['/fixturi/css/late.css'] } },
  'date-inainte-de-cap': { dateInainteDeCap: true, cap: capImplicit().concat(['/fixturi/modul-cap.js']) },
  'corp-devreme': { corpDevreme: true, cap: capImplicit().concat(['/fixturi/modul-cap.js']) },
  'csp-martor': { inline: true },
  'speculatii-fara-cuvant': { csp: CSP_FARA_SPECULATII },
  sandbox: { inainte: true },
  meniu: { corp: CORP_MENIU, stil: STIL_MENIU },
};

// ---------------------------------------------------------------------------------------------------------------------
// Serverul de probă
// ---------------------------------------------------------------------------------------------------------------------
const server = { intarzieri: {} };
const TIPURI = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json' };

function trimite(res, cod, tip, corp, csp) {
  const antete = { 'Content-Type': tip, 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'max-age=600' };
  if (csp) {
    antete['Content-Security-Policy'] = csp;
  }
  res.writeHead(cod, antete);
  res.end(corp);
}

function fisierSub(radacina, rel) {
  const cale = path.resolve(radacina, '.' + path.sep + rel);
  if (!cale.startsWith(radacina + path.sep) || !fs.existsSync(cale) || !fs.statSync(cale).isFile()) {
    return null;
  }
  return cale;
}

function raspunde(u, res) {
  let m = u.pathname.match(/^\/(pagina|gazda)\/([a-z0-9-]+)$/);
  if (m && PAGINI[m[2]]) {
    const html = m[1] === 'pagina' ? pagina(PAGINI[m[2]]) : gazda(m[2], PAGINI[m[2]]);
    return trimite(res, 200, 'text/html; charset=utf-8', html, m[1] === 'pagina' ? (PAGINI[m[2]].csp || CSP_SITE) : CSP_GAZDA);
  }
  m = u.pathname.match(/^\/(assets\/compat|fixturi)\/([A-Za-z0-9/._-]+)$/);
  if (m) {
    const cale = fisierSub(m[1] === 'fixturi' ? FIXTURI : COMPAT, m[2]);
    const tip = TIPURI[path.extname(m[2])];
    if (cale && tip) {
      return trimite(res, 200, tip, fs.readFileSync(cale), CSP_SITE);
    }
  }
  return trimite(res, 404, 'text/plain; charset=utf-8', 'nu există', CSP_SITE);
}

const srv = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://127.0.0.1');
  if (req.method !== 'GET') {
    return trimite(res, 405, 'text/plain; charset=utf-8', 'doar GET');
  }
  setTimeout(() => raspunde(u, res), server.intarzieri[u.pathname] || 0);
});

// ---------------------------------------------------------------------------------------------------------------------
// Browserul
// ---------------------------------------------------------------------------------------------------------------------
let BAZA = '';
// Un 404 pe o foaie: cu nosniff (ca pe site), Chromium scrie refuzul tipului MIME în locul rândului despre 404.
const ERORI_ASTEPTATE = /Failed to load resource: the server responded with a status of 404|Refused to apply style from .* because its MIME type/;
// Singurul rând de consolă al runtime-ului: refuzul lui ax-date.
const REFUZ_AX_DATE = /^axCompat: ax-date /;

async function deschide(browser, o) {
  o = o || {};
  server.intarzieri = o.intarzieri || {};
  const context = await browser.newContext({ userAgent: o.ua || UA.chromeWin, viewport: o.viewport || { width: 1440, height: 900 },
    isMobile: !!o.isMobile, hasTouch: !!o.hasTouch, javaScriptEnabled: o.js !== false, deviceScaleFactor: 1 });
  const ev = { erori: [], consola: [], refuzuri: [], straine: [], cereri: [] };
  await context.route('**/*', (ruta) => {
    const url = ruta.request().url();
    if (url.startsWith(BAZA + '/')) {
      ev.cereri.push(url.slice(BAZA.length));
      return ruta.continue();
    }
    ev.straine.push(url);
    return ruta.abort();
  });
  if (o.init) {
    await context.addInitScript(o.init);
  }
  const page = await context.newPage();
  page.on('pageerror', (e) => ev.erori.push(String(e && e.message ? e.message : e)));
  page.on('console', (msg) => {
    if (msg.type() === 'warning' && REFUZ_AX_DATE.test(msg.text())) {
      ev.refuzuri.push(msg.text());
    } else if ((msg.type() === 'error' || msg.type() === 'warning') && !(o.permise && o.permise.test(msg.text()))) {
      ev.consola.push(msg.type() + ': ' + msg.text());
    }
  });
  await page.goto(BAZA + o.cale, { waitUntil: 'load', timeout: 20000 });
  for (const cadru of o.js === false ? [] : o.cadre || [null]) {
    const f = cadru ? page.frame({ name: cadru }) : page.mainFrame();
    if (f) {
      await f.waitForFunction(() => window.__axProba && window.__axProba.load >= 0, null, { timeout: 20000 }).catch(() => {});
      if (o.conditie) {
        await f.waitForFunction(o.conditie, null, { timeout: 20000 }).catch(() => {});
      }
    }
  }
  await page.waitForTimeout(o.asteptare || 250);
  return { context, page, ev };
}

const cadru = (page, nume) => (nume ? page.frame({ name: nume }) : page.mainFrame());

/** Starea paginii, citită în cadrul ei (după scripturi). */
async function stare(f) {
  return f.evaluate((asteptata) => {
    const ax = window.axCompat || null;
    const P = window.__axProba || null;
    const p = document.querySelector('.proba-tarziu');
    const cs = p ? getComputedStyle(p) : null;
    const clase = (el) => (el ? Array.from(el.classList) : null);
    const structura = {};
    Object.keys(asteptata).forEach((sel) => {
      structura[sel] = clase(document.querySelector(sel));
    });
    const subsol = document.querySelector('.et_pb_row_0_tb_footer');
    const antet = document.querySelector('.et_pb_row_1_tb_header');
    const pictura = performance.getEntriesByName('first-contentful-paint')[0];
    const fit = document.querySelectorAll('[id="fit-vids-style"]');
    const dg = document.querySelectorAll('.dg-carousel-lightbox');
    const single = document.querySelector('.et_pb_column_2');
    const interior = document.querySelector('.et_pb_column_inner_0');
    return {
      origine: self.origin,
      htmlClass: document.documentElement.getAttribute('class'),
      bodyClass: document.body.getAttribute('class'),
      bodyStyle: document.body.getAttribute('style'),
      fcp: pictura ? Math.round(pictura.startTime * 10) / 10 : null,
      ax: ax ? { versiune: ax.versiune, dateValide: ax.dateValide(), diagnostic: ax.diagnostic(), tarziu: ax.tarziu ? ax.tarziu.stare() : null,
        clase: ax.claseUa ? ax.claseUa.clase : 'lipsa' } : null,
      P: P ? JSON.parse(JSON.stringify(P)) : null,
      linkuri: Array.prototype.map.call(document.querySelectorAll('link'), (l) => ({ id: l.id, rel: l.getAttribute('rel'), href: l.getAttribute('href'),
        tarziu: l.hasAttribute('data-ax-tarziu'), inainte: l.previousElementSibling ? l.previousElementSibling.id : null,
        dupa: l.nextElementSibling ? l.nextElementSibling.id : null, inHead: l.parentNode === document.head })),
      foi: Array.prototype.map.call(document.styleSheets, (s) => (s.ownerNode && s.ownerNode.id) || '?'),
      hrefFoi: Array.prototype.map.call(document.styleSheets, (s) => (s.href ? s.href.replace(/^[a-z]+:\/\/[^/]+/, '') : null)),
      stil: cs ? { tarziu: cs.marginLeft, tarziu2: cs.paddingRight, dinamic: cs.paddingLeft, amanat: cs.marginRight, inainte: cs.marginBottom,
        ordine: cs.color } : null,
      structura,
      subsolStyle: subsol ? subsol.getAttribute('style') : 'lipsa',
      antetStyle: antet ? antet.getAttribute('style') : 'lipsa',
      stilStructura: { single: single ? getComputedStyle(single).paddingTop : null, interior: interior ? getComputedStyle(interior).paddingBottom : null },
      fitVids: { numar: fit.length, ultimulDinHead: !!document.head.lastElementChild && document.head.lastElementChild.id === 'fit-vids-style',
        nod: fit.length ? fit[0].nodeName : null, text: fit.length ? fit[0].textContent : null },
      dg: { numar: dg.length, ultimulDinCorp: !!document.body.lastElementChild && document.body.lastElementChild.classList.contains('dg-carousel-lightbox'),
        marcaj: dg.length ? dg[0].outerHTML : null, afisare: dg.length ? getComputedStyle(dg[0]).display : null },
      rt: performance.getEntriesByType('resource').map((e) => ({ nume: e.name.replace(/^[a-z]+:\/\/[^/]+/, ''), sfarsit: e.responseEnd })),
    };
  }, STRUCTURA_ASTEPTATA);
}

/** DOM-ul de după scripturi, cu mutațiile așteptate ale pieselor întoarse (ca să-l comparăm cu DOM-ul fără JS). */
async function domIntors(f) {
  return f.evaluate(() => {
    const c = document.documentElement.cloneNode(true);
    const scoateClasa = (el, k) => {
      el.classList.remove(k);
      if (el.getAttribute('class') === '') {
        el.removeAttribute('class');
      }
    };
    scoateClasa(c, 'js');
    c.querySelectorAll('link[data-ax-tarziu]').forEach((l) => l.setAttribute('rel', 'preload'));
    c.querySelectorAll('link[id="et-dynamic-late-css"], style[id="fit-vids-style"], .dg-carousel-lightbox').forEach((n) => n.remove());
    const corp = c.querySelector('body');
    const clase = window.axCompat && window.axCompat.claseUa && window.axCompat.claseUa.clase ? window.axCompat.claseUa.clase : [];
    clase.forEach((k) => corp.classList.remove(k));
    c.querySelectorAll('.et_pb_column_single').forEach((el) => scoateClasa(el, 'et_pb_column_single'));
    c.querySelectorAll('.et-last-child').forEach((el) => scoateClasa(el, 'et-last-child'));
    c.querySelectorAll('.et_pb_row_inner, .et_pb_row').forEach((el) => Array.from(el.classList).filter((k) => /^et_pb_row_[0-9]+-[0-9]+(_[0-9]+-[0-9]+)+$/.test(k))
      .forEach((k) => scoateClasa(el, k)));
    c.querySelectorAll('.et_pb_row_0_tb_footer, .et_pb_row_1_tb_header, body').forEach((el) => {
      el.style.removeProperty('display');
      el.style.removeProperty('z-index');
      if (el.getAttribute('style') === '') {
        el.removeAttribute('style');
      }
    });
    return c.outerHTML;
  });
}

const curate = (ev, s, refuzuri) => ev.erori.length === 0 && ev.consola.length === 0 && ev.straine.length === 0 && s.P && s.P.erori.length === 0
  && s.P.csp.length === 0 && ev.refuzuri.length === (refuzuri || 0);
const detaliiCurate = (ev, s) => ({ erori: ev.erori, consola: ev.consola, refuzuri: ev.refuzuri, straine: ev.straine, eroriPagina: s.P && s.P.erori,
  csp: s.P && s.P.csp });
const link = (s, id) => s.linkuri.find((l) => l.id === id) || null;
const linkuriTarziu = (s) => s.linkuri.filter((l) => l.tarziu);
const late = (s) => s.linkuri.filter((l) => l.id === 'et-dynamic-late-css');
const mutatie = (s, tinta, atr) => s.P.jurnal.filter((j) => j.fel === 'atribut' && j.tinta === tinta && j.atribut === atr);
const ORDINE_BAZA = ['f:loading', 'a:true:true', 'b', 'd', 'e', 'c:complete'];
const FOI_BAZA = ['proba-global-css', 'global-styles-inline-css', 'divi-style-inline-inline-css', 'et-dynamic-late-css',
  'divi-dynamic-critical-inline-css', 'divi-dynamic-late-css', 'divi-dynamic-css', 'et-critical-inline-css',
  'et-core-unified-deferred-7-cached-inline-styles', 'fit-vids-style'];
const DIAG_BAZA = ['modul refuzat: Proba Rea', 'modul refuzat: proba-g', 'modul refuzat: proba-h', 'modulul proba-b: proba: modul care aruncă'];

/** Nicio mutație a modulelor de după slotul cap: nici clasele UA, nici structura, nici fit-vids, nici containerul dg, nici foaia late. */
function faraModule(s, lateMaxim) {
  return s.P.ordine.length === 0 && s.bodyClass === BODY_SERVER && late(s).length <= (lateMaxim || 0) && s.fitVids.numar === 0 && s.dg.numar === 0
    && s.subsolStyle === null && s.antetStyle === null && s.bodyStyle === null && Object.keys(STRUCTURA_ASTEPTATA).every((sel) => s.structura[sel] && !s.structura[sel].some((k) => /^et_pb_column_single$|^et-last-child$|^et_pb_row_[0-9]+-/.test(k)));
}

function primaDiferenta(a, b) {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) {
    i++;
  }
  return { la: i, dupaScripturi: a.slice(Math.max(0, i - 80), i + 120), faraJs: b.slice(Math.max(0, i - 80), i + 120) };
}

// ---------------------------------------------------------------------------------------------------------------------
// Verificările
// ---------------------------------------------------------------------------------------------------------------------
function verificaManifestul() {
  scrie('== Manifestul sloturilor (divi4/v1/sloturi.json) și fișierele runtime-ului');
  const cai = [].concat(manifest.cap || [], manifest.final || []);
  verifica('sloturi.json: format 1, runtime divi4@1, cap și final liste nevide de căi', manifest.format === 1 && manifest.runtime === 'divi4@1'
    && Array.isArray(manifest.cap) && Array.isArray(manifest.final) && manifest.cap.length > 0 && cai.every((c) => typeof c === 'string'), manifest);
  const rele = cai.filter((c) => !/^(comun|divi4)\/v[0-9]+\/[a-z0-9-]+\.js$/.test(c) || !fisierSub(COMPAT, c));
  verifica('fiecare cale e un fișier .js existent din public/assets/compat/<comun|divi4>/vN/ (fără „..”)', rele.length === 0, rele);
  verifica('nicio cale de două ori', new Set(cai).size === cai.length, cai);
  verifica('cap începe cu comun/v1/incarcare.js, urmat de comun/v1/tarziu.js; în final: clase-ua, apoi fit-vids, structura, dg-lightbox (ordinea OBS-01)',
    manifest.cap[0] === 'comun/v1/incarcare.js' && manifest.cap[1] === 'comun/v1/tarziu.js'
    && egal(manifest.final.slice(0, 4), ['comun/v1/clase-ua.js', 'divi4/v1/fit-vids.js', 'divi4/v1/structura.js', 'divi4/v1/dg-lightbox.js']), manifest);
  const toate = [];
  for (const dosar of fs.readdirSync(COMPAT)) {
    for (const v of fs.existsSync(path.join(COMPAT, dosar)) ? fs.readdirSync(path.join(COMPAT, dosar)) : []) {
      const d = path.join(COMPAT, dosar, v);
      if (fs.statSync(d).isDirectory()) {
        fs.readdirSync(d).filter((x) => x.endsWith('.js')).forEach((x) => toate.push(dosar + '/' + v + '/' + x));
      }
    }
  }
  const uitate = toate.filter((c) => !cai.includes(c));
  verifica('fiecare .js din public/assets/compat/*/vN/ e în manifest (niciun modul uitat)', uitate.length === 0, uitate);
  const sintaxa = [];
  const interzise = [];
  const consola = {};
  const INTERZIS = new RegExp(['\\beval\\s*\\(', '\\bnew\\s+Function\\b', '\\bFunction\\s*\\(', '\\.innerHTML\\b', '\\.outerHTML\\b',
    'insertAdjacentHTML', 'createContextualFragment', 'DOMParser', 'document\\.write', '\\.srcdoc\\b', 'setAttribute\\(\\s*[\'"]on',
    '\\bimport\\s*\\(', 'document\\.domain', '\\bsetTimeout\\s*\\(\\s*[\'"]', '\\bsetInterval\\s*\\(\\s*[\'"]'].join('|'));
  for (const c of toate) {
    const cod = fs.readFileSync(path.join(COMPAT, c), 'utf8');
    try {
      new vm.Script(cod, { filename: c });
    } catch (e) {
      sintaxa.push(c + ': ' + e.message);
    }
    const faraComentarii = cod.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const g = faraComentarii.match(INTERZIS);
    if (g) {
      interzise.push(c + ': ' + g[0]);
    }
    consola[c] = (faraComentarii.match(/\bconsole\s*\.\s*[a-z]+\s*\(/g) || []).length;
  }
  verifica('fiecare fișier al runtime-ului se parsează', sintaxa.length === 0, sintaxa);
  verifica('fără eval/Function, fără HTML construit (innerHTML/outerHTML/insertAdjacentHTML/DOMParser/document.write/srcdoc), fără '
    + 'handlere puse din cod, fără import dinamic, fără timere din text', interzise.length === 0, interzise);
  verifica('consola: un singur apel, în incarcare.js (refuzul lui ax-date); niciunul în celelalte',
    Object.keys(consola).every((c) => consola[c] === (c === 'comun/v1/incarcare.js' ? 1 : 0)), consola);
}

async function verificaBaza(browser) {
  scrie('== incarcare + tarziu + clase-ua + structura + fit-vids + dg-lightbox pe pagina de bază (Chrome desktop, 1440): late.css întârziat 400 ms');
  const { context, page, ev } = await deschide(browser, { cale: '/pagina/baza', intarzieri: { '/fixturi/css/late.css': 400 } });
  const s = await stare(page.mainFrame());
  verifica('fără erori, fără consolă, fără încălcări CSP (blocul speculationrules inclus), nicio cerere spre altă gazdă', curate(ev, s), detaliiCurate(ev, s));
  const iJs = s.P.jurnal.findIndex((j) => j.fel === 'atribut' && j.tinta === 'html' && j.atribut === 'class' && /(^| )js( |$)/.test(j.nou || ''));
  const iBody = s.P.jurnal.findIndex((j) => j.fel === 'nod' && j.tinta === 'body');
  verifica('<html class="js"> pus din head, înaintea parsării lui <body>', s.htmlClass === 'js' && iJs >= 0 && iBody > iJs, { htmlClass: s.htmlClass, iJs, iBody });
  verifica('axCompat: versiunea comun/v1, ax-date valid', s.ax && s.ax.versiune === 'comun/v1' && s.ax.dateValide === true, s.ax);
  verifica('diagnosticul: doar cele trei înregistrări refuzate (nume, moment, funcție) și modulul care aruncă', s.ax && egal(s.ax.diagnostic, DIAG_BAZA), s.ax && s.ax.diagnostic);
  verifica('modulele: imediat la înregistrare, apoi la DOM gata în ordinea înregistrării (clase-ua înaintea modulelor din final, '
    + 'unul înregistrat dintr-altul pornește pe loc, cel care aruncă nu le oprește pe celelalte), apoi la load; dublura ignorată',
  egal(s.P.ordine, ORDINE_BAZA) && s.P.dublu === false && egal(s.P.refuzate, [false, false, false]), { ordine: s.P.ordine, dublu: s.P.dublu, refuzate: s.P.refuzate });
  verifica('date(): o copie (modificarea ei nu ajunge la următoarea), textul scăpat întors exact', s.P.copie && egal(s.P.copie.lista, [1, 2, 3])
    && s.P.copie.text === '<b> & </script>', s.P.copie);
  const lt = linkuriTarziu(s);
  verifica('cele trei link[data-ax-tarziu]: rel=stylesheet, la locul lor, cu atributul păstrat', lt.length === 3 && lt.every((l) => l.rel === 'stylesheet' && l.inHead)
    && egal(lt.map((l) => l.id), ['divi-dynamic-late-css', 'divi-dynamic-css', 'et-core-unified-deferred-7-cached-inline-styles']), lt);
  verifica('foaia întârziată era încă preload la DOMContentLoaded (trecerea așteaptă load-ul foii, ca pe live)',
    Array.isArray(s.P.laDcl) && s.P.laDcl.includes('divi-dynamic-late-css=preload'), s.P.laDcl);
  const incarcari = s.P.linkuri.filter((l) => l.id === 'divi-dynamic-late-css' && l.fel === 'load');
  const trecere = mutatie(s, 'link#divi-dynamic-late-css', 'rel').find((j) => j.nou === 'stylesheet');
  const rtLate = s.rt.find((r) => r.nume === '/fixturi/css/late.css?ver=4.27.4');
  verifica('trecerea pe stylesheet vine la load-ul foii (după sfârșitul cererii ei), nu înainte', incarcari.length >= 1 && incarcari[0].rel === 'preload'
    && trecere && rtLate && trecere.t >= incarcari[0].t && trecere.t + 1 >= rtLate.sfarsit, { incarcari, trecere, rtLate });
  const l = late(s);
  verifica('link#et-dynamic-late-css din `file`: unul, rel=stylesheet, href exact, imediat după divi-style-inline-inline-css și înaintea '
    + 'lui divi-dynamic-critical-inline-css', l.length === 1 && l[0].rel === 'stylesheet' && l[0].href === '/fixturi/css/late.css'
    && l[0].inainte === 'divi-style-inline-inline-css' && l[0].dupa === 'divi-dynamic-critical-inline-css', l);
  const nodLate = s.P.jurnal.find((j) => j.fel === 'nod' && j.tinta === 'link#et-dynamic-late-css');
  verifica('foaia late se inserează înainte de DOMContentLoaded și cel târziu la prima desenare (OBS-01), nu din slotul cap',
    nodLate && s.P.lateLaDcl === true && s.fcp !== null && nodLate.t <= s.fcp && s.P.axLaDcl === true, { nodLate, laDcl: s.P.lateLaDcl, fcp: s.fcp });
  verifica('ordinea CSSOM (cascada): ca în document, cu foaia late de două ori (cu și fără ?ver) și fit-vids-style ultima', egal(s.foi, FOI_BAZA)
    && s.hrefFoi.filter((h) => h && h.startsWith('/fixturi/css/late.css')).length === 2, { foi: s.foi, href: s.hrefFoi });
  verifica('stilurile calculate: foile târzii aplicate, cascada cu late.css după critic', s.stil && s.stil.tarziu === '11px' && s.stil.dinamic === '22px'
    && s.stil.amanat === '33px' && s.stil.ordine === 'rgb(1, 1, 1)', s.stil);
  verifica('foaia fără ?ver cerută o dată, ca pe live (aceeași foaie sub două adrese)', ev.cereri.filter((c) => c === '/fixturi/css/late.css').length === 1
    && ev.cereri.filter((c) => c === '/fixturi/css/late.css?ver=4.27.4').length === 1, ev.cereri);
  verifica('axCompat.tarziu.stare(): 3 aplicate, 0 așteptate, 0 eșuate, foaia inserată', s.ax && s.ax.tarziu && s.ax.tarziu.total === 3 && s.ax.tarziu.aplicate === 3
    && s.ax.tarziu.asteptate === 0 && s.ax.tarziu.esuate === 0 && egal(s.ax.tarziu.inserate, ['/fixturi/css/late.css']) && s.ax.tarziu.preload === true, s.ax && s.ax.tarziu);
  const claseUa = mutatie(s, 'body', 'class');
  verifica('<body>: clasele serverului, apoi `chrome` (UA de Chrome desktop, observat), puse înainte de DOMContentLoaded; fără style pe <body>',
    s.bodyClass === BODY_SERVER + ' chrome' && s.ax && egal(s.ax.clase, ['chrome']) && claseUa.length >= 1 && claseUa.every((j) => j.t < s.P.dcl)
    && s.bodyStyle === null, { body: s.bodyClass, claseUa, dcl: s.P.dcl, style: s.bodyStyle });
  const nodFit = s.P.jurnal.find((j) => j.fel === 'nod' && j.tinta === 'style#fit-vids-style');
  const nodDg = s.P.jurnal.find((j) => j.fel === 'nod' && j.parinte === 'body' && j.tinta === 'div');
  const claseStructura = s.P.jurnal.filter((j) => j.fel === 'atribut' && j.atribut === 'class' && /et_pb_column_single|et_pb_row_[0-9]+-|et-last-child/.test(j.nou || ''));
  const stilStructura = s.P.jurnal.filter((j) => j.fel === 'atribut' && j.atribut === 'style' && /z-index/.test(j.nou || ''));
  verifica('momentele OBS-01: fit-vids, structura (clasele și z-index-urile) și containerul dg într-o sarcină după DOMContentLoaded (nu în '
    + 'handlerele lui), fit-vids primul',
    nodFit && nodDg && claseStructura.length === 6 && stilStructura.length === 2 && [nodFit, nodDg].concat(claseStructura, stilStructura).every((j) => j.t >= s.P.dcl)
    && claseStructura.every((j) => j.t >= nodFit.t) && s.P.moduleLaDcl === false, { dcl: s.P.dcl, laDcl: s.P.moduleLaDcl, nodFit, nodDg, claseStructura, stilStructura });
  // structura (clase-structura.md)
  const struct = Object.keys(STRUCTURA_ASTEPTATA).filter((sel) => {
    const el = s.structura[sel];
    if (!el) {
      return true;
    }
    const adaugate = STRUCTURA_ASTEPTATA[sel];
    const coada = el.slice(el.length - adaugate.length);
    return !egal(coada, adaugate) || el.some((k, i) => i < el.length - adaugate.length && /^et_pb_column_single$|^et-last-child$|^et_pb_row_[0-9]+-/.test(k));
  });
  verifica('structura: et_pb_column_single pe coloanele de lângă o coloană „specialty” (antet și conținut), et_pb_row_<mărimi> pe '
    + 'rândurile interioare din conținut cu ≥ 2 coloane cu mărime (nu în antet, nu în subsol, nu cu o coloană fără mărime) și pe rândul '
    + '„specialty” din antet (et_pb_row_1-4_3-4), dar nu pe cel din conținut (OBS-04, și cu et_pb_gutters1), et-last-child din lista '
    + 'ax_structura; fiecare la sfârșitul atributului class', struct.length === 0, { gresite: struct, structura: s.structura });
  verifica('structura: rândul „specialty” din antet și rândul subsolului cu style="z-index: 3;"; efectul în stilurile calculate (umplutura 7px, 8px)',
    s.subsolStyle === 'z-index: 3;' && s.antetStyle === 'z-index: 3;' && s.stilStructura.single === '7px' && s.stilStructura.interior === '8px',
    { subsol: s.subsolStyle, antet: s.antetStyle, stil: s.stilStructura });
  verifica('fit-vids: un <style id="fit-vids-style">, doar cu id, ultimul din head, cu regulile observate (comparat pe reguli, nu pe text)',
    s.fitVids.numar === 1 && s.fitVids.nod === 'STYLE' && s.fitVids.ultimulDinHead && typeof s.fitVids.text === 'string'
    && JSON.stringify(REGULI_CSS.formaReguli(s.fitVids.text)) === JSON.stringify(FIT_VIDS_REGULI), s.fitVids);
  // Testul formei pe reguli (decizia coordonatorului): aceleași reguli scrise altfel trec, o declarație schimbată pică.
  verifica('comparația pe reguli: spațiile, ordinea, „;” final, #abc/#aabbcc și 0px/0 nu contează; o declarație schimbată, da',
    REGULI_CSS.egalePeReguli('.a{color:#ABC;margin:0px;padding:1px 0;}.b,.c{top:0}', '.c, .b { top: 0px }\n.a { padding: 1px 0px; margin: 0; color: #aabbcc }')
    && !REGULI_CSS.egalePeReguli('.a{color:#abc;margin:0}', '.a{color:#abd;margin:0}') && !REGULI_CSS.egalePeReguli('.a{top:0}', '.a{top:0;left:0}')
    && REGULI_CSS.formaReguli('@media print{.a{top:0}}') === null);
  verifica('dg-lightbox: un singur container, ultimul copil al lui <body>, cu exact marcajul observat, ascuns de CSS', s.dg.numar === 1
    && s.dg.ultimulDinCorp && s.dg.marcaj === DG_MARCAJ && s.dg.afisare === 'none', s.dg);
  verifica('fără cereri în plus: pagina o dată, poza cu src gol a containerului nu cere nimic', ev.cereri.filter((c) => c === '/pagina/baza').length === 1
    && ev.cereri.every((c) => c !== '' && c !== '/'), ev.cereri);
  const util = await page.evaluate(() => {
    const ax = window.axCompat;
    const loc = ax.stocare('local');
    const ses = ax.stocare('sesiune');
    return { scrisCookie: ax.cookie.scrie('axproba=1; path=/'), cookie: ax.cookie.citeste(), disponibila: loc.disponibila,
      scris: loc.scrie('axproba', 'v'), citit: loc.citeste('axproba'), sters: loc.sterge('axproba'), dupa: loc.citeste('axproba'),
      sesiune: ses.disponibila && ses.scrie('k', 1) && ses.citeste('k') === '1' && ses.sterge('k') };
  });
  verifica('cookie și stocare pe o pagină obișnuită: scriu, citesc, șterg', util.scrisCookie === true && /(^|; )axproba=1(;|$)/.test(util.cookie)
    && util.disponibila === true && util.scris === true && util.citit === 'v' && util.sters === true && util.dupa === null && util.sesiune === true, util);
  const adrese = [['https://cdn.example/x.css', true], ['HTTPS://cdn.example/x.css', true], ['/fixturi/css/late.css', true], ['late.css', true],
    ['?v=1', true], ['#sus', true], [BAZA + '/x.css', true], ['http://alt.example/x.css', false], ['//alt.example/x.css', false],
    ['/\\alt.example/x.css', false], ['\\\\alt.example/x.css', false], ['javascript:alert(1)', false], [' javascript:alert(1)', false],
    ['java\tscript:alert(1)', false], ['JAVASCRIPT:alert(1)', false], ['data:text/css,a{}', false], ['blob:' + BAZA + '/x', false],
    ['ftp://alt.example/x', false], ['http://127.0.0.1:1/x.css', false], ['', false], [7, false], [null, false], [{}, false]];
  const rez = await page.evaluate((a) => a.map(([x]) => window.axCompat.adresaSigura(x)), adrese);
  const gresite = adrese.filter(([x, astept], i) => rez[i] !== astept).map(([x]) => x);
  verifica('adresaSigura: https sau aceeași origine (relative, absolute pe aceeași gazdă și port); refuzate javascript:/data:/blob:/ftp:, '
    + '//altă-gazdă, /\\altă-gazdă, http spre altă gazdă sau alt port, ce nu e text', gresite.length === 0, gresite);
  // DOM-ul de după scripturi față de DOM-ul fără JS.
  const intors = await domIntors(page.mainFrame());
  const fara = await deschide(browser, { cale: '/pagina/baza', js: false });
  const domFara = await fara.page.evaluate(() => document.documentElement.outerHTML).catch((e) => 'EROARE: ' + e.message);
  verifica('DOM-ul după scripturi = DOM-ul fără JS + exact mutațiile așteptate (html.js, rel pe preload-uri, foaia late, clasele UA, '
    + 'structura, fit-vids-style, containerul dg)', intors === domFara, primaDiferenta(intors, domFara));
  await fara.context.close();
  const dupa = await page.evaluate(() => {
    const st = document.createElement('style');
    st.id = 'proba-mai-tarziu';
    document.head.appendChild(st);
    window.axCompat.fitVids.aplica();
    return Array.from(document.head.children).slice(-2).map((n) => n.id).concat([document.querySelectorAll('[id="fit-vids-style"]').length]);
  });
  verifica('un <style> pus în head după fit-vids (ca foile consimțământului) vine după el; fit-vids nu se mută și nu se dublează',
    egal(dupa, ['fit-vids-style', 'proba-mai-tarziu', 1]), dupa);
  await context.close();
  return s;
}

async function verificaMomenteleFoilor(browser) {
  scrie('== tarziu: foile dinaintea scriptului, 404, browserul fără preload');
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/inainte-venita', intarzieri: { '/assets/compat/comun/v1/incarcare.js': 500 } });
    const s = await stare(page.mainFrame());
    const inc = s.P.linkuri.find((l) => l.id === 'proba-inainte-css' && l.fel === 'load');
    const tr = mutatie(s, 'link#proba-inainte-css', 'rel').find((j) => j.nou === 'stylesheet');
    verifica('o foaie dinaintea slotului cap, venită înaintea runtime-ului (incarcare.js întârziat 500 ms): trecută pe stylesheet când '
      + 'rulează tarziu (din Resource Timing), înainte de DOM gata', curate(ev, s) && inc && inc.axGata === false && inc.rel === 'preload' && tr
      && tr.t < s.P.dcl && link(s, 'proba-inainte-css').rel === 'stylesheet' && s.foi[0] === 'proba-inainte-css' && s.stil.inainte === '44px',
    { inc, tr, dcl: s.P.dcl, foi: s.foi, ...detaliiCurate(ev, s) });
    await context.close();
  }
  {
    // Preload-urile nu țin pe loc load-ul ferestrei: se așteaptă foaia însăși.
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/inainte-in-curs', intarzieri: { '/fixturi/css/inainte.css': 900 },
      conditie: () => document.getElementById('proba-inainte-css').getAttribute('rel') === 'stylesheet' });
    const s = await stare(page.mainFrame());
    const inc = s.P.linkuri.find((l) => l.id === 'proba-inainte-css' && l.fel === 'load');
    const tr = mutatie(s, 'link#proba-inainte-css', 'rel').find((j) => j.nou === 'stylesheet');
    verifica('o foaie dinaintea slotului cap, încă în drum când rulează tarziu (întârziată 900 ms): trecută la load-ul ei, după DOM gata',
      curate(ev, s) && inc && inc.axGata === true && inc.rel === 'preload' && tr && tr.t >= inc.t && s.P.laDcl.includes('proba-inainte-css=preload')
      && link(s, 'proba-inainte-css').rel === 'stylesheet', { inc, tr, laDcl: s.P.laDcl, ...detaliiCurate(ev, s) });
    await context.close();
  }
  {
    // 404 cu text/plain și nosniff: Resource Timing dă status 0 (ca în previzualizarea cu origine opacă), deci tarziu o trece
    // pe încredere; foaia dă eroare ca stylesheet, iar linkul se întoarce pe preload, cum rămânea pe live.
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/inainte-lipsa', intarzieri: { '/assets/compat/comun/v1/incarcare.js': 400 },
      permise: ERORI_ASTEPTATE, asteptare: 500 });
    const s = await stare(page.mainFrame());
    const l = link(s, 'proba-inainte-lipsa-css');
    const mut = mutatie(s, 'link#proba-inainte-lipsa-css', 'rel').map((j) => j.nou);
    verifica('o foaie dinaintea slotului cap care a dat 404 înaintea runtime-ului (status ascuns): rămâne la final preload, eșuată; celelalte trec',
      curate(ev, s) && l && l.rel === 'preload' && s.ax.tarziu.esuate === 1 && s.ax.tarziu.aplicate === 3 && s.ax.tarziu.asteptate === 0
      && !s.foi.includes('proba-inainte-lipsa-css') && (egal(mut, ['stylesheet', 'preload']) || egal(mut, [])), { l, mut, tarziu: s.ax.tarziu, ...detaliiCurate(ev, s) });
    scrie('        (drumul: ' + (mut.length ? 'trecută după Resource Timing, apoi întoarsă pe preload la eroare' : 'statusul 404 văzut, netrecută') + ')');
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/lipsa', permise: ERORI_ASTEPTATE, asteptare: 600 });
    const s = await stare(page.mainFrame());
    verifica('o foaie care dă 404 rămâne preload (evenimentul error, ca pe live); celelalte trec; stare(): 1 eșuată',
      curate(ev, s) && link(s, 'proba-lipsa-css').rel === 'preload' && s.P.linkuri.some((l) => l.id === 'proba-lipsa-css' && l.fel === 'error')
      && s.ax.tarziu.esuate === 1 && s.ax.tarziu.aplicate === 3 && s.ax.tarziu.asteptate === 0, { lipsa: link(s, 'proba-lipsa-css'), tarziu: s.ax.tarziu, ...detaliiCurate(ev, s) });
    await context.close();
  }
  {
    const init = "(function(){var s=DOMTokenList.prototype.supports;DOMTokenList.prototype.supports=function(t){return String(t).toLowerCase()==='preload'?false:s.call(this,t);};})();";
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/fara-preload', init, intarzieri: { '/fixturi/css/late.css': 800 } });
    const s = await stare(page.mainFrame());
    const inc = s.P.linkuri.filter((l) => l.id === 'divi-dynamic-late-css' && l.fel === 'load');
    verifica('browserul fără preload (relList.supports dă false): trecerea se face fără să aștepte load-ul (altfel foaia n-ar veni niciodată)',
      curate(ev, s) && s.ax.tarziu.preload === false && s.ax.tarziu.aplicate === 3 && inc.length >= 1 && inc[0].rel === 'stylesheet' && s.stil.tarziu === '11px',
    { inc, tarziu: s.ax.tarziu, ...detaliiCurate(ev, s) });
    await context.close();
  }
}

async function verificaFaraCadru(browser) {
  scrie('== tarziu: foaia late fără requestAnimationFrame (cadru care nu vine: fila în fundal, un mediu fără desenare)');
  const init = 'window.requestAnimationFrame = function () { return 0; };';
  const { context, page, ev } = await deschide(browser, { cale: '/pagina/fara-cadru', init });
  const s = await stare(page.mainFrame());
  const nodLate = s.P.jurnal.find((j) => j.fel === 'nod' && j.tinta === 'link#et-dynamic-late-css');
  verifica('fără niciun cadru: foaia late inserată la readyState „interactive”, tot înainte de DOMContentLoaded, o dată, la locul ei',
    curate(ev, s) && late(s).length === 1 && late(s)[0].inainte === 'divi-style-inline-inline-css' && nodLate && nodLate.t >= s.P.interactiv
    && s.P.lateLaDcl === true && s.stil.tarziu === '11px', { nodLate, interactiv: s.P.interactiv, laDcl: s.P.lateLaDcl, late: late(s), ...detaliiCurate(ev, s) });
  await context.close();
}

async function verificaDateleRele(browser) {
  scrie('== incarcare: ax-date lipsă, invalid, dublu, listă, alt element, suprapus, dublat după final; cheile de poluare a prototipului');
  const cazuri = [
    ['fara-date', 'lipsește'],
    ['json-invalid', 'JSON invalid'],
    ['date-duble', 'apare de 2 ori'],
    ['date-lista', 'nu e un obiect JSON'],
    ['date-div', 'nu e un <script type="application/json">'],
    ['date-clobber', 'apare de 2 ori'],
    ['date-clobber-cap', 'apare de 2 ori'],
  ];
  for (const [nume, motiv] of cazuri) {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/' + nume });
    const s = await stare(page.mainFrame());
    verifica(nume + ': niciun modul (nici clasele UA, nici structura, fit-vids, containerul dg, foaia late, modulele de probă), nimic aruncat, '
      + 'un singur rând în consolă; html.js și preload-urile trecute (din head, ca live-ul fără configurații)', curate(ev, s, 1) && s.ax
      && s.ax.dateValide === false && faraModule(s, nume === 'date-duble' ? 1 : 0) && s.htmlClass === 'js' && s.ax.tarziu.aplicate === 3
      && s.ax.diagnostic.some((d) => d === 'ax-date: ' + motiv + '; niciun modul nu pornește') && ev.refuzuri[0].includes(motiv),
    { ordine: s.P.ordine, body: s.bodyClass, diagnostic: s.ax && s.ax.diagnostic, tarziu: s.ax && s.ax.tarziu, fit: s.fitVids.numar, dg: s.dg.numar,
      ...detaliiCurate(ev, s) });
    if (nume === 'date-duble') {
      // Al doilea bloc e în corp: foaia late (la primul cadru, înaintea parsării lui) poate să fi intrat deja, ca modulele „imediat”.
      scrie('        (foaia late: ' + (late(s).length ? 'inserată înaintea parsării celui de-al doilea bloc' : 'neinserată') + ')');
    }
    await context.close();
  }
  {
    // Un al doilea bloc după slotul final: modulele „imediat” din final (clase-ua, proba-f) porniseră deja (blocul era singur atunci);
    // înaintea modulelor de la DOM gata, numărarea refăcută îl găsește și nu mai pornește nimic.
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/date-dupa-final' });
    const s = await stare(page.mainFrame());
    verifica('date-dupa-final: al doilea element ax-date, după slotul final, oprește modulele de la DOM gata și load (cele „imediat”, cu '
      + 'clasele UA, porniseră), un singur rând în consolă', curate(ev, s, 1) && egal(s.P.ordine, ['f:loading']) && s.bodyClass === BODY_SERVER + ' chrome'
      && s.antetStyle === null && late(s).length <= 1
      && s.fitVids.numar === 0 && s.dg.numar === 0 && s.subsolStyle === null && s.ax.dateValide === false
      && s.ax.diagnostic.includes('ax-date: apare de 2 ori; niciun modul nu pornește'), { ordine: s.P.ordine, diagnostic: s.ax.diagnostic, ...detaliiCurate(ev, s) });
    scrie('        (foaia late: ' + (late(s).length ? 'inserată înaintea parsării celui de-al doilea bloc' : 'neinserată, al doilea bloc era deja parsat') + ')');
    await context.close();
  }
  const { context, page, ev } = await deschide(browser, { cale: '/pagina/proto' });
  const s = await stare(page.mainFrame());
  const r = await page.evaluate(() => {
    const ax = window.axCompat;
    const rau = ax.date('rau');
    return { poluat: ({}).poluat, rau, chei: Object.keys(rau), adanc: Object.keys(rau.adanc), proto: ax.date('__proto__'),
      protoRau: Object.getPrototypeOf(rau) === Object.prototype };
  });
  verifica('__proto__/constructor/prototype scoase la citire (oricât de adânc), prototipul neatins, restul datelor și modulele merg',
    curate(ev, s) && r.poluat === undefined && egal(r.chei, ['bun', 'adanc']) && egal(r.adanc, ['ok']) && r.proto === undefined && r.protoRau
    && s.ax.dateValide === true && egal(s.P.ordine, ORDINE_BAZA), { r, ordine: s.P.ordine, diagnostic: s.ax.diagnostic });
  await context.close();
}

async function verificaAsezarea(browser) {
  scrie('== incarcare: alte așezări ale sloturilor (ax-date înaintea slotului cap; un head închis devreme de parser)');
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/date-inainte-de-cap' });
    const s = await stare(page.mainFrame());
    verifica('ax-date înaintea scripturilor cap: configurația se citește deja din head, un modul „imediat” din head pornește primul, restul ca de obicei',
      curate(ev, s) && s.P.capDateValide === true && egal(s.P.ordine, ['cap'].concat(ORDINE_BAZA)) && s.bodyClass === BODY_SERVER + ' chrome'
      && late(s).length === 1 && egal(s.foi, FOI_BAZA), { ordine: s.P.ordine, cap: s.P.capDateValide, foi: s.foi, ...detaliiCurate(ev, s) });
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/corp-devreme' });
    const s = await stare(page.mainFrame());
    verifica('un element de corp în head (parserul deschide <body> înaintea slotului cap și a lui ax-date): o citire din slotul cap nu decide '
      + '„lipsă”, ax-date e găsit după aceea, modulele pornesc', curate(ev, s) && s.P.capDateValide === null && s.ax.dateValide === true
      && egal(s.P.ordine, ORDINE_BAZA) && s.bodyClass === BODY_SERVER + ' chrome' && late(s).length === 1
      && s.htmlClass === 'js' && linkuriTarziu(s).every((l) => l.rel === 'stylesheet'), { ordine: s.P.ordine, diagnostic: s.ax && s.ax.diagnostic, ...detaliiCurate(ev, s) });
    await context.close();
  }
}

async function verificaIdempotenta(browser, baza) {
  scrie('== Idempotența: fiecare fișier inclus de două ori, fiecare piesă rulată din nou');
  const { context, page, ev } = await deschide(browser, { cale: '/pagina/dublu' });
  const s = await stare(page.mainFrame());
  verifica('toate fișierele runtime-ului și modulele de probă incluse de două ori: aceeași stare ca o singură dată',
    curate(ev, s) && s.htmlClass === baza.htmlClass && s.bodyClass === baza.bodyClass && egal(s.foi, baza.foi) && egal(s.P.ordine, ORDINE_BAZA)
    && late(s).length === 1 && egal(linkuriTarziu(s).map((l) => l.rel), linkuriTarziu(baza).map((l) => l.rel)) && egal(s.structura, baza.structura)
    && s.subsolStyle === baza.subsolStyle && s.antetStyle === baza.antetStyle && s.fitVids.numar === 1 && s.dg.numar === 1 && s.dg.marcaj === DG_MARCAJ,
  { body: s.bodyClass, foi: s.foi, ordine: s.P.ordine, late: late(s), structura: s.structura, fit: s.fitVids, dg: s.dg, ...detaliiCurate(ev, s) });
  verifica('un modul „imediat” înregistrat din head nu pornește (ax-date nu e încă parsat) și o spune în diagnostic',
    s.P.capInregistrat === true && s.P.capDateValide === null && !s.P.ordine.includes('cap')
    && s.ax.diagnostic.includes('modulul proba-cap: „imediat” înaintea blocului ax-date; nu pornește'), { cap: s.P.capInregistrat, diagnostic: s.ax.diagnostic });
  const r = await page.evaluate(() => {
    const ax = window.axCompat;
    const inainte = document.documentElement.outerHTML;
    const ordine = JSON.stringify(window.__axProba.ordine);
    ax.porneste('imediat');
    ax.porneste('dom');
    ax.porneste('load');
    ax.tarziu.aplica();
    ax.claseUa.aplica();
    ax.structura.aplica();
    ax.fitVids.aplica();
    ax.dgLightbox.aplica();
    ax.meniuMobil.aplica();
    return { la: inainte === document.documentElement.outerHTML, ordine: ordine === JSON.stringify(window.__axProba.ordine) };
  });
  await page.waitForTimeout(200);
  const dupa = await stare(page.mainFrame());
  verifica('porneste(imediat/dom/load) și aplica() pe fiecare piesă din nou: DOM-ul, CSSOM-ul și ordinea modulelor neschimbate',
    r.la && r.ordine && egal(dupa.foi, s.foi) && dupa.bodyClass === s.bodyClass && egal(dupa.P.ordine, s.P.ordine), { r, foi: dupa.foi });
  await context.close();
}

async function verificaFile(browser) {
  scrie('== tarziu: lista `file` și reperele foii late');
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/file-multe' });
    const s = await stare(page.mainFrame());
    const l = late(s);
    const refuzate = [2, 3, 5, 6, 7, 8].map((i) => 'tarziu: adresă refuzată în „file” (poziția ' + i + ')');
    verifica('mai multe adrese: câte un link, în ordinea listei, după divi-style-inline-inline-css; o adresă repetată o singură dată; refuzate '
      + 'și necerute: javascript: (și ascuns cu spațiu și tab), un număr, http spre altă gazdă, //altă-gazdă, /\\altă-gazdă',
    curate(ev, s) && egal(l.map((x) => x.href), ['/fixturi/css/late.css', '/fixturi/css/late-2.css'])
      && l[0].inainte === 'divi-style-inline-inline-css' && l[1].inainte === 'et-dynamic-late-css' && l[1].dupa === 'divi-dynamic-critical-inline-css'
      && s.stil.tarziu2 === '12px' && refuzate.every((d) => s.ax.diagnostic.includes(d)), { late: l, diagnostic: s.ax.diagnostic, ...detaliiCurate(ev, s) });
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/file-text' });
    const s = await stare(page.mainFrame());
    verifica('`file` care nu e o listă: nimic inserat, un rând în diagnostic, restul merge', curate(ev, s) && late(s).length === 0
      && s.ax.diagnostic.includes('tarziu: „file” nu e o listă') && s.bodyClass === BODY_SERVER + ' chrome', { diagnostic: s.ax.diagnostic });
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/doar-critic' });
    const s = await stare(page.mainFrame());
    const l = late(s);
    verifica('fără divi-style-inline-inline-css: foaia late imediat înaintea lui divi-dynamic-critical-inline-css', curate(ev, s) && l.length === 1
      && l[0].dupa === 'divi-dynamic-critical-inline-css' && l[0].inainte === 'global-styles-inline-css', l);
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/fara-repere' });
    const s = await stare(page.mainFrame());
    verifica('fără niciun reper: nimic inserat (nicio poziție nouă în cascadă), un rând în diagnostic', curate(ev, s) && late(s).length === 0
      && s.ax.diagnostic.includes('tarziu: lipsesc divi-style-inline-inline-css și divi-dynamic-critical-inline-css; foile „file” nu se inserează'), s.ax.diagnostic);
    await context.close();
  }
}

async function verificaStructura(browser) {
  scrie('== structura: lista ax_structura (refuzurile) și pagina fără listă');
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/structura-rea' });
    const s = await stare(page.mainFrame());
    const asteptate = ['structura: selector refuzat pentru et-last-child (poziția 1)', 'structura: selector refuzat pentru et-last-child (poziția 2)',
      'structura: selector refuzat pentru et-last-child (poziția 3)', 'structura: selector refuzat pentru et-last-child (poziția 4)',
      'structura: selector invalid pentru et-last-child (poziția 5)', 'structura: intrare refuzată în „ax_structura” (alta-clasa)',
      'structura: intrare refuzată în „ax_structura” (et-fara-lista)'];
    verifica('selectorii cu atribute, pseudo-clase, virgule, un număr și unul invalid refuzați; o clasă din afara listei închise și o valoare '
      + 'care nu e listă refuzate; selectorul bun aplicat, restul structurii neatins', curate(ev, s) && egal(s.structura['#sectiune-speciala > .et_pb_row'].slice(-1), ['et-last-child'])
      && !s.structura['.et_pb_row_0'].includes('alta-clasa') && asteptate.every((d) => s.ax.diagnostic.includes(d))
      && s.structura['.et_pb_column_2'].includes('et_pb_column_single'), { diagnostic: s.ax.diagnostic, structura: s.structura });
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/structura-fara-lista' });
    const s = await stare(page.mainFrame());
    verifica('fără ax_structura: nicio clasă din listă, restul structurii (reguli din DOM, z-index) pus ca de obicei', curate(ev, s)
      && !s.structura['#sectiune-speciala > .et_pb_row'].includes('et-last-child') && s.structura['.et_pb_column_2'].includes('et_pb_column_single')
      && s.structura['.et_pb_row_inner_1'].includes('et_pb_row_1-6_1-6_1-6') && s.subsolStyle === 'z-index: 3;' && s.antetStyle === 'z-index: 3;', { structura: s.structura, ...detaliiCurate(ev, s) });
    await context.close();
  }
}

// Regula claselor UA (clase-ua.md §2 ca fapt, plus implicitele OBS-03), pe tabel.
const STANDALONE_FALS = "Object.defineProperty(Navigator.prototype,'standalone',{configurable:true,get:function(){return false;}});";
const TABEL_UA = [
  ['Chrome Windows (observat)', 'chromeWin', 'gol', ['chrome']],
  ['Chrome Mac', 'chromeMac', 'gol', ['chrome']],
  ['Safari iPhone, emulat în Chromium, fără navigator.standalone (observat)', 'iphone', 'gol', ['et_mobile_device', 'et_mobile_device_not_ipad', 'safari', 'iphone']],
  ['Safari iPhone real (navigator.standalone === false)', 'iphone', 'fals', ['et_mobile_device', 'et_mobile_device_not_ipad', 'uiwebview', 'iphone']],
  ['Safari iPhone, aplicație de pe ecranul principal (standalone === true)', 'iphone', 'adevarat', ['et_mobile_device', 'et_mobile_device_not_ipad', 'safari', 'iphone']],
  ['Chrome iOS (CriOS), real', 'crios', 'fals', ['et_mobile_device', 'et_mobile_device_not_ipad', 'uiwebview', 'iphone']],
  ['WebView iOS fără „Safari” în UA', 'webviewIos', 'fals', ['et_mobile_device', 'et_mobile_device_not_ipad', 'uiwebview', 'iphone']],
  ['Chrome Android (implicit OBS-03)', 'android', 'gol', ['et_mobile_device', 'et_mobile_device_not_ipad', 'chrome']],
  ['iPad vechi, real (implicit OBS-03)', 'ipadVechi', 'fals', ['et_mobile_device', 'uiwebview']],
  ['Firefox', 'firefox', 'gol', ['gecko']],
  ['Safari Mac', 'safariMac', 'gol', ['safari']],
  ['iPad cu iPadOS (UA de Safari Mac, navigator.standalone === false; observat)', 'safariMac', 'fals', ['safari']],
  ['Edge Chromium („Edg/”)', 'edgeChromium', 'gol', ['chrome']],
  ['Edge vechi („Edge/”)', 'edgeVechi', 'gol', ['edge']],
  ['Opera („OPR/”)', 'opera', 'gol', ['opera']],
  ['Opera Presto', 'operaPresto', 'gol', ['opera']],
  ['IE 11 („Trident”)', 'ie11', 'gol', ['ie']],
  ['IE 10 („MSIE”, „Trident”)', 'ie10', 'gol', ['ie']],
  ['UA necunoscut', null, 'gol', []],
  ['UA gol', '', 'gol', []],
  ['navigator.standalone care aruncă', 'iphone', 'arunca', ['et_mobile_device', 'et_mobile_device_not_ipad', 'safari', 'iphone']],
];

async function verificaMeniulMobil(browser) {
  scrie('== meniu-mobil (felia 2, meniu-mobil.md, OBS-06): listele telefonului, comutarea, animația de 500 ms, tapul dublu');
  const { context, page, ev } = await deschide(browser, { cale: '/pagina/meniu', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
    ua: UA.iphone });
  const f = page.mainFrame();
  const s = await stare(f);
  verifica('fără erori, fără consolă, fără încălcări CSP, nicio cerere spre altă gazdă', curate(ev, s), detaliiCurate(ev, s));
  const liste = await f.evaluate(() => Array.from(document.querySelectorAll('div.mobile_nav')).map((nav) => {
    const ul = nav.querySelector(':scope > ul.et_mobile_menu');
    const modul = nav.closest('.et_pb_menu');
    const desk = modul ? modul.querySelector('nav.et-menu-nav > ul') : null;
    return { ul: !!ul, id: ul ? ul.id : null, ultimul: !!ul && nav.lastElementChild === ul, dupaBara: !!ul && !!ul.previousElementSibling
      && ul.previousElementSibling.classList.contains('mobile_menu_bar'),
      clase: ul ? Array.from(ul.children).map((li) => li.getAttribute('class')) : null,
      claseDesk: desk ? Array.from(desk.children).map((li) => li.getAttribute('class')) : null,
      href: ul ? Array.from(ul.querySelectorAll('a')).map((a) => a.getAttribute('href') + '|' + a.getAttribute('aria-current') + '|' + a.textContent) : null,
      hrefDesk: desk ? Array.from(desk.querySelectorAll('a')).map((a) => a.getAttribute('href') + '|' + a.getAttribute('aria-current') + '|' + a.textContent) : null,
      noduri: ul ? ul.childNodes.length : null, noduriDesk: desk ? desk.childNodes.length : null,
      atributeStraine: ul ? Array.from(ul.querySelectorAll('*')).filter((e) => e.hasAttribute('id') || Array.from(e.attributes).some((a) => a.name.startsWith('data-ax-'))).length : null,
      afisare: ul ? getComputedStyle(ul).display : null, clasaNav: nav.getAttribute('class'), stil: ul ? ul.getAttribute('style') : null };
  }));
  const [a, b, c] = liste;
  verifica('fiecare .mobile_nav al unui modul meniu primește ul#mobile_menuN.et_mobile_menu (N în ordinea documentului), ultimul copil, după bară; '
    + 'cel fără modul meniu, nimic', liste.length === 3 && a.ul && a.id === 'mobile_menu1' && a.ultimul && a.dupaBara && c.ul && c.id === 'mobile_menu2'
    && b.ul === false, liste);
  verifica('copia listei desktop: aceleași li (clasele, cu et_first_mobile_item la sfârșit pe primul), aceleași legături, aria-current și texte, '
    + 'aceleași spații; fără id-uri și fără data-ax-*', [a, c].every((x) => egal(x.clase, x.claseDesk.map((k, i) => (i === 0 ? k + ' et_first_mobile_item' : k)))
    && egal(x.href, x.hrefDesk) && x.noduri === x.noduriDesk && x.atributeStraine === 0) && a.href[1].includes('|page|'), liste);
  verifica('închis la încărcare: .mobile_nav closed, lista ascunsă de CSS, fără style', [a, c].every((x) => x.clasaNav === 'mobile_nav closed'
    && x.afisare === 'none' && x.stil === null), liste);
  const urma = (sel, actiuni) => f.evaluate(async ([sel, actiuni]) => {
    const nav = document.querySelectorAll('div.mobile_nav')[0];
    const ul = nav.querySelector('ul.et_mobile_menu');
    const tinta = sel === 'bara' ? nav.querySelector('.mobile_menu_bar') : document.querySelector(sel);
    const rez = [];
    const t0 = performance.now();
    let gata = false;
    const pas = () => {
      rez.push([Math.round(performance.now() - t0), Math.round(ul.getBoundingClientRect().height * 10) / 10]);
      if (!gata) {
        requestAnimationFrame(pas);
      }
    };
    requestAnimationFrame(pas);
    for (const [dupa, unde] of actiuni) {
      await new Promise((r) => setTimeout(r, dupa));
      (unde === 'bara' ? nav.querySelector('.mobile_menu_bar') : document.querySelector(unde)).dispatchEvent(new MouseEvent('click', { bubbles: true }));
    }
    await new Promise((r) => setTimeout(r, 700));
    gata = true;
    return { urma: rez, clasa: nav.getAttribute('class'), stil: ul.getAttribute('style'), afisare: getComputedStyle(ul).display,
      inaltime: ul.getBoundingClientRect().height, totala: ul.scrollHeight, tinta: !!tinta };
  }, [sel, actiuni]);
  const deschis = await urma('bara', [[0, 'bara']]);
  const la = (u, t) => { const x = u.filter((p) => p[0] <= t); return x.length ? x[x.length - 1][1] : null; };
  const H = 3 * 51;
  verifica('tap pe bară: opened, display:block, înălțimea de la 0 la toată (3 × 51 px) în ~500 ms pe curba swing (≈50% la jumătate), '
    + 'apoi fără height/overflow în style', deschis.clasa === 'mobile_nav opened' && deschis.afisare === 'block' && Math.abs(deschis.inaltime - H) < 1
    && deschis.stil === 'display: block;' && la(deschis.urma, 60) < H * 0.15 && la(deschis.urma, 270) > H * 0.3 && la(deschis.urma, 270) < H * 0.75
    && la(deschis.urma, 450) < H, deschis);
  const afara = await urma('.proba-text', [[0, '.proba-text']]);
  verifica('tap în afara meniului: rămâne deschis', afara.clasa === 'mobile_nav opened' && Math.abs(afara.inaltime - H) < 1, afara);
  const inchis = await urma('bara', [[0, 'bara']]);
  verifica('al doilea tap: closed, înălțimea scade la 0 în ~500 ms, apoi display:none rămâne în style', inchis.clasa === 'mobile_nav closed'
    && inchis.afisare === 'none' && inchis.stil === 'display: none;' && la(inchis.urma, 270) > H * 0.25 && la(inchis.urma, 270) < H * 0.7, inchis);
  const dublu = await urma('bara', [[0, 'bara'], [90, 'bara']]);
  const maxim = Math.max.apply(null, dublu.urma.map((p) => p[1]));
  verifica('tap dublu la 90 ms: se întoarce din punctul atins (sub un sfert), închis la capăt, display:none', dublu.clasa === 'mobile_nav closed'
    && dublu.afisare === 'none' && maxim > 0 && maxim < H * 0.25, { maxim, dublu });
  const legatura = await f.evaluate(async () => {
    const nav = document.querySelectorAll('div.mobile_nav')[0];
    nav.querySelector('.mobile_menu_bar').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 650));
    const a = nav.querySelector('ul.et_mobile_menu a[href$="#contact"]');
    a.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 100));
    return { clasa: nav.getAttribute('class'), hash: location.hash };
  });
  verifica('clic pe o legătură din listă: navighează (aici, la ancoră), lista nu se comută', legatura.clasa === 'mobile_nav opened' && legatura.hash === '#contact', legatura);
  const idem = await f.evaluate(() => {
    const inainte = document.querySelectorAll('ul.et_mobile_menu').length;
    window.axCompat.meniuMobil.aplica();
    return [inainte, document.querySelectorAll('ul.et_mobile_menu').length];
  });
  verifica('aplica() din nou: nicio listă în plus', egal(idem, [2, 2]), idem);
  const fara = await deschide(browser, { cale: '/pagina/baza' });
  const nr = await fara.page.evaluate(() => document.querySelectorAll('ul.et_mobile_menu').length);
  verifica('pagina fără meniuri: nimic adăugat', nr === 0, nr);
  await fara.context.close();
  await context.close();
}

async function verificaClaseleUa(browser) {
  scrie('== clase-ua: regula pe tabel și clasele puse în pagină');
  {
    const { context, page } = await deschide(browser, { cale: '/pagina/baza' });
    const tabel = TABEL_UA.map(([e, cheie, nav, asteptat]) => [e, cheie === null ? 'ProbaBot/1.0' : (cheie === '' ? '' : UA[cheie]), nav, asteptat]);
    const rez = await page.evaluate((t) => t.map(([, ua, nav]) => {
      let n = {};
      if (nav === 'fals') {
        n = { standalone: false };
      } else if (nav === 'adevarat') {
        n = { standalone: true };
      } else if (nav === 'arunca') {
        n = Object.defineProperty({}, 'standalone', { get() { throw new Error('nu'); } });
      }
      return window.axCompat.claseUa.calculeaza(ua, n);
    }), tabel);
    tabel.forEach(([e, , , asteptat], i) => verifica('regula: ' + e + ' → ' + (asteptat.join(' ') || '(nimic)'), egal(rez[i], asteptat), rez[i]));
    await context.close();
  }
  const pagini = [
    ['Chrome desktop', { ua: UA.chromeWin }, ['chrome']],
    ['iPhone emulat (UA iPhone, isMobile, hasTouch, 390 px; observat)', { ua: UA.iphone, isMobile: true, hasTouch: true, viewport: { width: 390, height: 844 } },
      ['et_mobile_device', 'et_mobile_device_not_ipad', 'safari', 'iphone'], 'display: block;'],
    ['iPhone cu navigator.standalone === false (ca pe dispozitivul real)', { ua: UA.iphone, isMobile: true, hasTouch: true, viewport: { width: 390, height: 844 },
      init: STANDALONE_FALS },
    ['et_mobile_device', 'et_mobile_device_not_ipad', 'uiwebview', 'iphone']],
    ['iPad emulat (UA de Safari Mac, isMobile, hasTouch, standalone === false, 820 px; observat)', { ua: UA.safariMac, isMobile: true, hasTouch: true,
      viewport: { width: 820, height: 1180 }, init: STANDALONE_FALS }, ['safari'], 'display: block;'],
    ['Safari Mac (observat)', { ua: UA.safariMac }, ['safari'], 'display: block;'],
    ['Firefox', { ua: UA.firefox }, ['gecko']],
    ['Chrome Android (implicit OBS-03)', { ua: UA.android, isMobile: true, hasTouch: true, viewport: { width: 412, height: 915 } },
      ['et_mobile_device', 'et_mobile_device_not_ipad', 'chrome']],
  ];
  for (const [eticheta, o, asteptat, stilCorp] of pagini) {
    const { context, page, ev } = await deschide(browser, Object.assign({ cale: '/pagina/baza' }, o));
    const s = await stare(page.mainFrame());
    await page.setViewportSize({ width: o.viewport && o.viewport.width < 800 ? 1024 : 390, height: 800 });
    await page.waitForTimeout(150);
    const dupaResize = await page.evaluate(() => [document.body.getAttribute('class'),
      Array.from(document.querySelectorAll('.et_pb_column_single, .et-last-child, [class*="et_pb_row_1-"]')).length]);
    const claseUa = mutatie(s, 'body', 'class');
    verifica('pagina, ' + eticheta + ': <body class> = clasele serverului + ' + asteptat.join(' ') + ', o dată, înainte de DOMContentLoaded; '
      + (stilCorp ? '<body style="' + stilCorp + '"> după load; ' : 'fără style pe <body>; ') + 'clasele UA și structura neschimbate după resize',
      curate(ev, s) && s.bodyClass === BODY_SERVER + ' ' + asteptat.join(' ') && claseUa.length >= 1 && claseUa.every((j) => j.t < s.P.dcl)
      && s.bodyStyle === (stilCorp || null) && dupaResize[0] === s.bodyClass && dupaResize[1] === 6
      && mutatie(s, 'body', 'style').every((j) => j.t >= s.P.load),
    { body: s.bodyClass, style: s.bodyStyle, claseUa, dcl: s.P.dcl, dupaResize, ...detaliiCurate(ev, s) });
    await context.close();
  }
}

async function verificaSandbox(browser, baza) {
  scrie('== Previzualizarea din admin: iframe sandbox="allow-scripts" fără allow-same-origin (cu adresă și srcdoc)');
  const { context, page, ev } = await deschide(browser, { cale: '/gazda/sandbox', cadre: ['cu-adresa', 'srcdoc'],
    intarzieri: { '/assets/compat/comun/v1/incarcare.js': 400 } });
  for (const nume of ['cu-adresa', 'srcdoc']) {
    const f = cadru(page, nume);
    if (!f) {
      verifica(nume + ': cadrul există', false);
      continue;
    }
    const s = await stare(f);
    const direct = await f.evaluate(() => {
      const r = {};
      try {
        r.cookie = typeof document.cookie;
      } catch (e) {
        r.cookie = e.name;
      }
      try {
        r.local = typeof window.localStorage;
      } catch (e) {
        r.local = e.name;
      }
      return r;
    });
    const util = await f.evaluate(() => {
      const ax = window.axCompat;
      const loc = ax.stocare('local');
      const ses = ax.stocare('sesiune');
      return { citit: ax.cookie.citeste(), scris: ax.cookie.scrie('axproba=1'), loc: [loc.disponibila, loc.scrie('k', 'v'), loc.citeste('k'), loc.sterge('k')],
        ses: [ses.disponibila, ses.scrie('k', 'v'), ses.citeste('k'), ses.sterge('k')], adresa: [ax.adresaSigura('/x.css'), ax.adresaSigura('//alt.example/x.css')] };
    });
    verifica(nume + ': origine opacă (self.origin "null"); accesul direct la cookie și localStorage aruncă SecurityError',
      s.origine === 'null' && direct.cookie === 'SecurityError' && direct.local === 'SecurityError', { origine: s.origine, direct });
    verifica(nume + ': axCompat.cookie și axCompat.stocare nu aruncă („”, false, indisponibilă); adresaSigura merge pe baza documentului',
      util.citit === '' && util.scris === false && egal(util.loc, [false, false, null, false]) && egal(util.ses, [false, false, null, false])
      && egal(util.adresa, [true, false]), util);
    const inainte = link(s, 'proba-inainte-css');
    verifica(nume + ': fără erori și fără încălcări CSP; html.js, preload-urile trecute (și cea venită înaintea runtime-ului), foaia late, '
      + 'clasa chrome, structura, fit-vids, containerul dg, modulele în ordine, CSSOM-ul ca pe pagina obișnuită', s.P && s.P.erori.length === 0
      && s.P.csp.length === 0 && s.htmlClass === 'js' && linkuriTarziu(s).every((l) => l.rel === 'stylesheet') && inainte && inainte.rel === 'stylesheet'
      && late(s).length === 1 && s.bodyClass === BODY_SERVER + ' chrome' && egal(s.P.ordine, ORDINE_BAZA) && egal(s.foi, ['proba-inainte-css'].concat(baza.foi))
      && s.stil.tarziu === '11px' && egal(s.structura, baza.structura) && s.subsolStyle === 'z-index: 3;' && s.antetStyle === 'z-index: 3;' && s.fitVids.numar === 1 && s.dg.marcaj === DG_MARCAJ,
    { P: s.P && { erori: s.P.erori, csp: s.P.csp, ordine: s.P.ordine }, body: s.bodyClass, foi: s.foi, linkuri: linkuriTarziu(s) });
  }
  verifica('pagina gazdă: fără erori, fără consolă, nicio cerere spre altă gazdă', ev.erori.length === 0 && ev.consola.length === 0 && ev.straine.length === 0
    && ev.refuzuri.length === 0, ev);
  await context.close();
}

async function verificaMartorii(browser) {
  scrie('== Martorii CSP: un script inline (în afara runtime-ului) și speculationrules fără \'inline-speculation-rules\' sunt blocate');
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/csp-martor', permise: /Content Security Policy|inline script/ });
    const s = await stare(page.mainFrame());
    verifica('scriptul inline nu rulează, încălcarea script-src e prinsă de observator; runtime-ul merge mai departe', s.P.inline === 0
      && s.P.csp.length === 1 && /^script-src/.test(s.P.csp[0].directiva) && egal(s.P.ordine, ORDINE_BAZA) && ev.erori.length === 0, { csp: s.P.csp, inline: s.P.inline });
    await context.close();
  }
  {
    const { context, page, ev } = await deschide(browser, { cale: '/pagina/speculatii-fara-cuvant', permise: /speculation rules violates/ });
    const s = await stare(page.mainFrame());
    verifica('blocul speculationrules (D3: rămâne ca date) sub script-src \'self\' fără \'inline-speculation-rules\': încălcare CSP pe fiecare '
      + 'pagină, regulile nu se aplică — politica site-ului are nevoie de cuvânt (sau de hash-ul blocului)', s.P.csp.length === 1
      && s.P.csp[0].blocat === 'inline' && egal(s.P.ordine, ORDINE_BAZA) && ev.erori.length === 0, { csp: s.P.csp });
    await context.close();
  }
}

// ---------------------------------------------------------------------------------------------------------------------
async function main() {
  fs.mkdirSync(DOSAR_DEV, { recursive: true });
  scrie('Poarta G-compat — runtime-ul compat (public/assets/compat/) pe pagini inventate, ' + new Date().toISOString());
  verificaManifestul();
  await new Promise((gata, esec) => {
    srv.once('error', esec);
    srv.listen(PORT, '127.0.0.1', gata);
  });
  BAZA = 'http://127.0.0.1:' + srv.address().port;
  scrie('Serverul de probă: ' + BAZA);
  const browser = await chromium.launch();
  try {
    const baza = await verificaBaza(browser);
    await verificaMomenteleFoilor(browser);
    await verificaFaraCadru(browser);
    await verificaDateleRele(browser);
    await verificaAsezarea(browser);
    await verificaIdempotenta(browser, baza);
    await verificaFile(browser);
    await verificaStructura(browser);
    await verificaMeniulMobil(browser);
    await verificaClaseleUa(browser);
    await verificaSandbox(browser, baza);
    await verificaMartorii(browser);
  } finally {
    await browser.close();
    srv.close();
  }
  const trecute = rezultate.filter(Boolean).length;
  const rezumat = trecute === rezultate.length
    ? 'compat: ' + trecute + '/' + rezultate.length + ' verificări trecute (Chromium, pagini inventate, CSP fără inline, sandbox)'
    : 'compat: EȘEC — ' + (rezultate.length - trecute) + ' din ' + rezultate.length + ' verificări au picat';
  scrie('');
  scrie(rezumat);
  fs.writeFileSync(RAPORT, iesire.join('\n') + '\n');
  process.exitCode = trecute === rezultate.length ? 0 : 1;
}

main().catch((e) => {
  console.error('EROARE: ' + (e && e.stack ? e.stack : e));
  try {
    srv.close();
  } catch (x) {
    // serverul poate să nu fi pornit
  }
  process.exitCode = 1;
});
