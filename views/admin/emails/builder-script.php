<?php
/**
 * Scriptul builderului de emailuri (views/admin/emails/builder.php), pus în pagină ca până acum (inline, după datele din
 * window.EB_DATE). Pânza desenează fiecare bloc cu ACELAȘI HTML ca NewsletterService::renderBlock (varianta de calculator),
 * deci emailul din editor e emailul trimis: blocurile lipite, culorile, marginile, o linie albă din poză. Desktop / Mobil cer
 * emailul de la server (/admin/emails/builder/preview), într-un cadru izolat nou la fiecare clic (sandbox="allow-scripts").
 */
?>
<script>
(function() {
'use strict';

const D = window.EB_DATE || {};
const C = Object.assign({principala:'#0f766e', inchisa:'#134e4a', fundal_deschis:'#f0fdfa', text:'#1f2937', text_secundar:'#64748b', linie:'#e2e8f0'}, D.culori || {});

/* ═══════════════════════════════════════════════════════════
   STAREA
═══════════════════════════════════════════════════════════ */
let blocks  = Array.isArray(D.blocuri) ? D.blocuri : [];
let sel     = null;      // null | {i} | {i, ci, cbi} | {subsol:true}
let tab     = 'continut';
let history = [];
let future  = [];
let dirty   = false;
let previewMode = 'edit';
let dragState   = null;
let editare     = null;  // {el, cale} cât se scrie pe pânză

/* ═══════════════════════════════════════════════════════════
   TIPURILE DE BLOC
═══════════════════════════════════════════════════════════ */
const BLOCK_TYPES = {
  logo:           {label:'Logo'},
  menu:           {label:'Meniu'},
  products:       {label:'Produse'},
  coupon:         {label:'Cod reducere'},
  header:         {label:'Titlu'},
  text:           {label:'Text'},
  image:          {label:'Imagine'},
  video:          {label:'Video'},
  button:         {label:'Buton'},
  quote:          {label:'Citat'},
  social:         {label:'Social media'},
  divider:        {label:'Separator'},
  spacer:         {label:'Spațiu'},
  columns_2:      {label:'2 coloane'},
  columns_2_6040: {label:'Coloane 60 / 40'},
  columns_2_4060: {label:'Coloane 40 / 60'},
  columns_2_7030: {label:'Coloane 70 / 30'},
  columns_2_3070: {label:'Coloane 30 / 70'},
  columns_3:      {label:'3 coloane'},
};
const eticheta = t => (BLOCK_TYPES[t] || {label: t || 'Bloc'}).label;
const colWidths = type => {
  if(type==='columns_3')      return [33,34,33];
  if(type==='columns_2_6040') return [60,40];
  if(type==='columns_2_4060') return [40,60];
  if(type==='columns_2_7030') return [70,30];
  if(type==='columns_2_3070') return [30,70];
  return [50,50];
};
const eColoane = t => String(t||'').startsWith('columns_');
// Ce se poate pune într-o coloană (restul nu are sens la o treime de email: meniul, produsele, logo-ul, alte coloane).
const IN_COLOANA = ['header','text','image','button','divider','spacer','quote','coupon','video'];
const SCRIS_PE_PANZA = ['header','text','quote'];

function categoriiImplicite() {
  const cat = (D.categorii || []).slice(0, 4).map(c => ({label:c.name, url:c.url, category_id:c.id}));
  return cat.length ? cat : [{label:'Magazin', url:'/magazin', category_id:0}, {label:'Oferte', url:'/', category_id:0}];
}

function blockDefault(type) {
  const d = {
    logo:      {type:'logo', image_url:'', width:180, align:'center', link_url:'/', background:'#ffffff', text_color:C.principala, font_size:26, padding_top:20, padding_bottom:16},
    menu:      {type:'menu', items:categoriiImplicite(), align:'center', background:C.principala, text_color:'#ffffff', font_size:14, padding_top:8, padding_bottom:8},
    products:  {type:'products', mod:'auto', sursa:'vandute', zile:30, categorie_id:0, numar:3, pe_rand:3, produse:[], title:'Recomandate pentru tine',
                title_color:C.inchisa, background:C.fundal_deschis, card_background:'#ffffff', text_color:C.text, price_color:C.inchisa,
                button_color:C.principala, button_text_color:'#ffffff', button_label:D.etichetaButonProdus || 'Vezi', show_price:1, show_button:1,
                padding_top:18, padding_bottom:12, padding_left:8, padding_right:8},
    coupon:    {type:'coupon', code:(D.cupoane && D.cupoane[0]) || 'COD10', content:'Folosește codul în coș și primești reducerea:', note:'',
                align:'center', background:'#ffffff', text_color:C.text, font_size:24, border_color:C.principala, code_background:C.fundal_deschis,
                code_color:C.inchisa, padding_top:18, padding_bottom:18},
    header:    {type:'header',    content:'Titlul tău',            align:'center', font_size:32, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff', padding_top:24, padding_bottom:24, padding_left:20, padding_right:20},
    text:      {type:'text',      content:'Scrie textul tău aici...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333', padding_top:16, padding_bottom:16, padding_left:28, padding_right:28},
    image:     {type:'image',     image_url:'',                    alt:'',         align:'center', background:'#ffffff', width:100, link_url:''},
    // Clienții de email nu redau video, deci blocul e o copertă pe care se dă
    // clic plus un buton — nu un player.
    video:     {type:'video',     video_url:'', thumbnail_url:'', title:'', provider:'', label:'▶ Vezi videoclipul',
                align:'center', background:'#ffffff', width:100, border_radius:10,
                button_color:'#2d7d5f', text_color:'#ffffff', button_radius:6, title_color:'#1f2937', padding_top:16, padding_bottom:16},
    button:    {type:'button',    label:'Apasă aici',              url:'',         align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6, padding_top:12, padding_bottom:20},
    quote:     {type:'quote',     content:'Scrie citatul tău...',  author:'',      align:'left',   background:'#f0fdf4', text_color:'#166534', border_color:'#2d7d5f', font_size:16, padding_top:14, padding_bottom:14, padding_left:20, padding_right:24},
    social:    {type:'social',    links:[{platform:'facebook',url:''},{platform:'instagram',url:''}], align:'center', background:'#ffffff', icon_size:32, padding_top:12, padding_bottom:12},
    divider:   {type:'divider',   background:'#ffffff',            color:'#e5e7eb', width:80, thickness:1, padding_top:8, padding_bottom:8},
    spacer:    {type:'spacer',    height:24,                       background:'#ffffff'},
    columns_2:      {type:'columns_2',      background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_6040: {type:'columns_2_6040', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_4060: {type:'columns_2_4060', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_7030: {type:'columns_2_7030', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_3070: {type:'columns_2_3070', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_3:      {type:'columns_3',      background:'#ffffff', gap:12, columns:[[],[],[]]},
  };
  return JSON.parse(JSON.stringify(d[type] || {type}));
}

/**
 * Blocul pus INTR-O COLOANA pornește fără fundal propriu, ca să se vadă
 * culoarea coloanei. Cu alb implicit, fiecare casetă acoperea culoarea aleasă
 * și coloana părea că n-are niciun fundal.
 *
 * Titlul (Header) are textul alb, ales pentru fundalul lui verde: fără fundal,
 * pe coloana albă, textul alb nu se vedea deloc (nici pe pânză, nici în emailul
 * trimis). În coloană pornește deci cu textul închis, ca blocul Text.
 * Marginile pornesc de la zero: coloana are deja 10 px de jur împrejur.
 */
function subBlockDefault(type) {
  const d = blockDefault(type);
  if ('background' in d) { d.background = ''; }
  if (d.type === 'header') { d.text_color = '#1f2937'; d.font_size = 24; d.font_size_mobile = 20; }
  ['padding_top','padding_bottom','padding_left','padding_right'].forEach(k => { if (k in d) d[k] = 0; });
  return d;
}

/* ═══════════════════════════════════════════════════════════
   AJUTOARE: aceleași reguli ca NewsletterService (PHP)
═══════════════════════════════════════════════════════════ */
// htmlspecialchars(…, ENT_QUOTES)
const esc  = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
const attr = esc;
const num  = (v, d) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : d; };
const fontSize = (v, implicit) => { let n = num(v, 0); if (n <= 0) n = implicit; return Math.max(10, Math.min(96, n)); };
const hex = (v, implicit) => /^#[0-9a-fA-F]{3,8}$/.test(String(v||'').trim()) ? String(v).trim() : implicit;
const aliniere = (v, implicit) => ['left','center','right'].includes(v) ? v : (implicit || 'center');
/** O adresă de link care nu poate rula cod: fără schemă, sau http(s), mailto, tel (NewsletterService::adresaLinkSigura). */
function linkSigur(adresa) {
  const c = String(adresa||'').replace(/[\x00-\x20\x7f]+/g, '').toLowerCase();
  const m = c.match(/^([a-z][a-z0-9+.\-]*):/);
  return !m || ['http','https','mailto','tel'].includes(m[1]);
}
/** NewsletterService::allowRichTags: din textul scăpat, doar etichetele editorului redevin etichete. */
function allowRichTags(s) {
  s = s.replace(/&lt;(\/?(b|i|u|s|strike|del|strong|em))&gt;/gi, '<$1>');
  s = s.replace(/&lt;a\s+href=&quot;((?:[^&<>]|&amp;)*)&quot;&gt;/gi, (m, h) => '<a href="' + (linkSigur(h.replace(/&amp;/g,'&')) ? h : '#') + '">');
  s = s.replace(/&lt;\/a&gt;/gi, '</a>');
  s = s.replace(/&lt;span style=&quot;color:\s*(#[0-9a-fA-F]{3,8})\s*;?&quot;&gt;/g, '<span style="color:$1">');
  s = s.replace(/&lt;\/span&gt;/gi, '</span>');
  return s;
}
const nl2br = s => s.replace(/\r\n|\n|\r/g, '<br>');
const bogat = v => allowRichTags(nl2br(esc(v)));
function fundal(b, implicit) {
  const c = ('background' in b) ? String(b.background ?? '') : (implicit ?? '#ffffff');
  return c.trim() === '' ? '' : 'background:' + esc(c) + ';';
}
function ebFontStack(key) {
  const m = {arial:"Arial, Helvetica, sans-serif",helvetica:"Helvetica, Arial, sans-serif",verdana:"Verdana, Geneva, sans-serif",tahoma:"Tahoma, Geneva, sans-serif",trebuchet:"'Trebuchet MS', Helvetica, sans-serif",georgia:"Georgia, 'Times New Roman', serif",times:"'Times New Roman', Times, serif",palatino:"'Palatino Linotype', 'Book Antiqua', Palatino, serif",courier:"'Courier New', Courier, monospace",lucida:"'Lucida Sans Unicode', 'Lucida Grande', sans-serif"};
  return m[String(key||'').toLowerCase()] || '';
}
function adresaMedia(u) { return String(u||'').trim(); }
const lei = s => s.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' lei';

/* ═══════════════════════════════════════════════════════════
   ISTORICUL (Anulează / Refă)
═══════════════════════════════════════════════════════════ */
function snap()        { return JSON.parse(JSON.stringify(blocks)); }
function pushHistory() { history.push(snap()); if(history.length>80)history.shift(); future=[]; }
let _lastHistoryPush = 0;
function pushHistoryRar() { const now = Date.now(); if(now - _lastHistoryPush > 800) { pushHistory(); } _lastHistoryPush = now; }

function selValid() {
  if (!sel || sel.subsol) return;
  if (!blocks[sel.i]) { sel = null; return; }
  if (sel.ci !== undefined) {
    const col = (blocks[sel.i].columns || [])[sel.ci] || [];
    if (!col[sel.cbi]) sel = {i: sel.i};
  }
}
function undo() {
  if(!history.length) return;
  inchideEditarea(false);
  future.push(snap());
  blocks = history.pop();
  selValid();
  render(); sync(); markDirty();
}
function redo() {
  if(!future.length) return;
  inchideEditarea(false);
  history.push(snap());
  blocks = future.pop();
  selValid();
  render(); sync(); markDirty();
}

/* ═══════════════════════════════════════════════════════════
   OPERAȚIILE PE BLOCURI
═══════════════════════════════════════════════════════════ */
function blocAles() {
  if (!sel || sel.subsol || !blocks[sel.i]) return null;
  if (sel.ci !== undefined) return ((blocks[sel.i].columns || [])[sel.ci] || [])[sel.cbi] || null;
  return blocks[sel.i];
}
function addBlock(type, index) {
  pushHistory();
  const b = blockDefault(type);
  const idx = (index === undefined || index < 0 || index > blocks.length) ? blocks.length : index;
  blocks.splice(idx, 0, b);
  sel = {i: idx};
  tab = 'continut';
  render(); sync(); markDirty();
  focusBloc();
}
function addInColoana(i, ci, type, imagine) {
  const b = blocks[i];
  if (!b || !eColoane(b.type) || !IN_COLOANA.includes(type)) return;
  pushHistory();
  const cnt = colWidths(b.type).length;
  const cols = JSON.parse(JSON.stringify(b.columns || []));
  while (cols.length < cnt) cols.push([]);
  const nou = subBlockDefault(type);
  if (imagine) nou.image_url = imagine;
  cols[ci] = [...(cols[ci] || []), nou];
  b.columns = cols;
  sel = {i, ci, cbi: cols[ci].length - 1};
  tab = 'continut';
  render(); sync(); markDirty();
}
function removeSel() {
  if (!sel || sel.subsol) return;
  inchideEditarea(false);
  pushHistory();
  if (sel.ci !== undefined) {
    const col = blocks[sel.i].columns[sel.ci];
    col.splice(sel.cbi, 1);
    sel = {i: sel.i};
  } else {
    blocks.splice(sel.i, 1);
    sel = blocks.length ? {i: Math.min(sel.i, blocks.length - 1)} : null;
  }
  render(); sync(); markDirty();
  focusBloc();
}
function duplicateSel() {
  if (!sel || sel.subsol) return;
  inchideEditarea(false);
  pushHistory();
  if (sel.ci !== undefined) {
    const col = blocks[sel.i].columns[sel.ci];
    col.splice(sel.cbi + 1, 0, JSON.parse(JSON.stringify(col[sel.cbi])));
    sel = {i: sel.i, ci: sel.ci, cbi: sel.cbi + 1};
  } else {
    blocks.splice(sel.i + 1, 0, JSON.parse(JSON.stringify(blocks[sel.i])));
    sel = {i: sel.i + 1};
  }
  render(); sync(); markDirty();
  focusBloc();
}
function moveSel(delta) {
  if (!sel || sel.subsol) return;
  inchideEditarea(false);
  const lista = sel.ci !== undefined ? blocks[sel.i].columns[sel.ci] : blocks;
  const de = sel.ci !== undefined ? sel.cbi : sel.i;
  const la = de + delta;
  if (la < 0 || la >= lista.length) return;
  pushHistory();
  [lista[de], lista[la]] = [lista[la], lista[de]];
  if (sel.ci !== undefined) sel = {i: sel.i, ci: sel.ci, cbi: la}; else sel = {i: la};
  render(); sync(); markDirty();
  focusBloc();
}
function moveBlock(from, to) {
  if (from === to || from + 1 === to) return;
  pushHistory();
  const [b] = blocks.splice(from, 1);
  const adj = to > from ? to - 1 : to;
  blocks.splice(adj, 0, b);
  sel = {i: adj};
  render(); sync(); markDirty();
}
/** Schimbă blocul ales (sau un bloc dat). `deLaPanou`: panoul nu se redesenează (câmpul păstrează cursorul). */
function updateBlock(props, deLaPanou) {
  const b = blocAles();
  if (!b) return;
  // O schimbare din panou câștigă: scrisul de pe pânză se oprește fără să-și mai scrie textul peste ea.
  if (editare) { editare = null; ascundeBaraText(); }
  pushHistoryRar();
  Object.assign(b, props);
  renderCanvas();
  if (!deLaPanou) renderProps();
  sync(); markDirty();
}

/* ═══════════════════════════════════════════════════════════
   SINCRONIZAREA, „MODIFICAT”, SALVAREA AUTOMATĂ
═══════════════════════════════════════════════════════════ */
function sync() {
  const inp = document.getElementById('eb-blocks-input');
  if(inp) inp.value = JSON.stringify(blocks);
  const undoBtn = document.getElementById('btn-undo');
  const redoBtn = document.getElementById('btn-redo');
  if(undoBtn) undoBtn.disabled = !history.length;
  if(redoBtn) redoBtn.disabled = !future.length;
}
function stareSalvare(stare, text) {
  const el = document.getElementById('eb-stare-salvare');
  if (!el) return;
  el.dataset.stare = stare;
  el.firstElementChild.textContent = text;
  el.title = text;
}
let temporizatorSalvare = null;
function markDirty() {
  dirty = true;
  document.getElementById('eb-dirty-dot')?.classList.add('visible');
  stareSalvare('nesalvat', D.salvareAutomata ? 'Modificări nesalvate (se salvează singur)' : 'Modificări nesalvate');
  if (D.salvareAutomata) {
    clearTimeout(temporizatorSalvare);
    temporizatorSalvare = setTimeout(() => salveazaAjax(true), 4000);
  }
}
function clearDirty() {
  dirty = false;
  document.getElementById('eb-dirty-dot')?.classList.remove('visible');
  const ora = new Date().toLocaleTimeString('ro-RO', {hour:'2-digit', minute:'2-digit'});
  stareSalvare('salvat', 'Salvat la ' + ora);
}
let salvareInCurs = null;
/** Salvarea prin fetch (aceeași rută ca formularul, cu ajax=1): ciorna newsletterului și șabloanele de newsletter. */
async function salveazaAjax(automat) {
  if (!D.poateModifica || (D.tip !== 'campaign' && D.tip !== 'template')) return false;
  if (salvareInCurs) return salvareInCurs;
  if (automat && editare) { clearTimeout(temporizatorSalvare); temporizatorSalvare = setTimeout(() => salveazaAjax(true), 3000); return false; }
  clearTimeout(temporizatorSalvare);
  sync();
  const formular = document.getElementById('eb-form');
  if (!formular) return false;
  const date = new URLSearchParams(new FormData(formular));
  date.set('ajax', '1');
  stareSalvare('salveaza', 'Se salvează…');
  const versiune = JSON.stringify(blocks);
  salvareInCurs = (async () => {
    try {
      const r = await fetch('/admin/emails/builder', {method:'POST', body:date, credentials:'same-origin', headers:{'Accept':'application/json'}});
      const d = await r.json().catch(() => null);
      if (r.ok && d && d.ok) {
        if (D.tip === 'template' && d.id && Number(d.id) !== Number(D.id)) {
          D.id = Number(d.id);
          const idIn = document.getElementById('eb-id-input'); if (idIn) idIn.value = String(d.id);
          try { window.history.replaceState(null, '', '/admin/emails/builder?type=template&id=' + d.id); } catch (e) {}
        }
        if (D.tip === 'template') D.salvareAutomata = D.poateModifica;
        if (JSON.stringify(blocks) === versiune) clearDirty(); else markDirty();
        return true;
      }
      stareSalvare('eroare', (d && d.message) || 'Nu s-a putut salva. Încearcă din nou.');
    } catch (e) {
      stareSalvare('eroare', 'Nu s-a putut salva (conexiunea). Încearcă din nou.');
    } finally {
      salvareInCurs = null;
    }
    return false;
  })();
  return salvareInCurs;
}

/* ═══════════════════════════════════════════════════════════
   DESENUL BLOCURILOR (același HTML ca NewsletterService::renderBlock)
═══════════════════════════════════════════════════════════ */
function stilCadru(b) {
  const pl = Math.max(0, num(b.padding_left, 0)), pr = Math.max(0, num(b.padding_right, 0));
  const pt = Math.max(0, num(b.padding_top, 0)),  pb = Math.max(0, num(b.padding_bottom, 0));
  return {
    hPad: (pl || pr) ? `padding-left:${pl}px;padding-right:${pr}px;` : '',
    vPad: `padding-top:${pt}px;padding-bottom:${pb}px;`,
    pl, pr,
    font: ebFontStack(b.font_family) ? 'font-family:' + ebFontStack(b.font_family) + ';' : '',
    bgImg: String(b.bg_image||'').trim() !== '' ? `background-image:url('${esc(adresaMedia(b.bg_image))}');background-size:${['cover','contain','auto'].includes(b.bg_size)?b.bg_size:'cover'};background-repeat:no-repeat;background-position:center;` : '',
  };
}
const ed = (cale) => ` data-edit="${cale}"`;

/** Un bloc, ca în email. `cale` = „i” sau „i.ci.cbi” (pentru scrisul pe pânză). */
function blockHtml(b, cale, inColoana) {
  const s = stilCadru(b);
  const t = String(b.type || 'text');
  switch (t) {
    case 'header': {
      const size = fontSize(b.font_size, 34);
      return `<div class="nl-text-desktop" style="display:block;text-align:${esc(b.align||'center')};${fundal(b,'#1a7a5e')}${s.bgImg}${s.hPad}${s.vPad}${s.font}color:${esc(b.text_color||'#ffffff')};font-size:${size}px;font-weight:700;"${ed(cale)}>${bogat(b.content ?? '')}</div>`;
    }
    case 'text': {
      const size = fontSize(b.font_size, 15);
      return `<div class="nl-text-desktop" style="display:block;text-align:${esc(b.align||'left')};${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}${s.font}color:${esc(b.text_color||'#1f2937')};font-size:${size}px;line-height:1.6;"${ed(cale)}>${bogat(b.content ?? '')}</div>`;
    }
    case 'image': {
      const url = adresaMedia(b.image_url);
      if (!url) return `<div class="eb-bloc-gol">Imagine: trage o poză aici sau alege-o din dreapta</div>`;
      const w = Math.min(100, Math.max(10, num(b.width, 100)));
      return `<div style="${s.vPad}${s.hPad}text-align:${esc(b.align||'center')};${fundal(b)}${s.bgImg}"><img src="${attr(url)}" alt="${attr(b.alt||'')}" style="width:${w}%;max-width:100%;height:auto;border-radius:${Math.max(0,num(b.border_radius,0))}px;border:none;display:inline-block;vertical-align:top;"></div>`;
    }
    case 'button': {
      const fs = fontSize(b.font_size, 16);
      const pv = Math.min(40, Math.max(2, num(b.btn_pad_v, 11))), ph = Math.min(80, Math.max(4, num(b.btn_pad_h, 22)));
      return `<div style="text-align:${esc(b.align||'center')};${s.vPad}${s.hPad}${fundal(b)}${s.bgImg}"><a href="#" onclick="return false" style="display:inline-block;background:${esc(b.button_color||'#2d7d5f')};color:${esc(b.text_color||'#ffffff')};padding:${pv}px ${ph}px;border-radius:${Math.max(0,num(b.border_radius ?? b.radius,6))}px;text-decoration:none;font-weight:700;font-size:${fs}px;">${esc(b.label ?? 'Buton')}</a></div>`;
    }
    case 'divider':
      return `<div style="${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}"><hr style="border:none;border-top:${Math.max(1,num(b.thickness,1))}px solid ${esc(b.color||b.line_color||'#dbe2ea')};margin:0 auto;width:${Math.min(100,Math.max(10,num(b.width,80)))}%;"></div>`;
    case 'video': {
      const video = String(b.video_url||'').trim(), cop = adresaMedia(b.thumbnail_url);
      if (!video && !cop) return `<div class="eb-bloc-gol">Video: lipește în dreapta un link TikTok, YouTube sau Vimeo</div>`;
      const w = Math.min(100, Math.max(20, num(b.width, 100)));
      const titlu = String(b.title||'').trim();
      let h = '';
      if (cop) h += `<a href="#" onclick="return false" style="text-decoration:none;border:none;"><img src="${attr(cop)}" alt="${attr(titlu || 'Deschide videoclipul')}" style="width:${w}%;max-width:100%;height:auto;border:none;border-radius:${Math.max(0,num(b.border_radius,10))}px;display:inline-block;vertical-align:top;"></a>`;
      if (titlu) h += `<div style="margin-top:10px;${s.font}font-size:15px;font-weight:600;color:${esc(b.title_color||'#1f2937')};">${esc(titlu)}</div>`;
      if (video) h += `<div style="margin-top:${cop||titlu?12:0}px;"><a href="#" onclick="return false" style="display:inline-block;background:${esc(b.button_color||'#2d7d5f')};color:${esc(b.text_color||'#ffffff')};padding:11px 22px;border-radius:${Math.max(0,num(b.button_radius,6))}px;text-decoration:none;font-weight:700;font-size:15px;${s.font}">${esc(String(b.label||'').trim() !== '' ? b.label : '▶ Vezi videoclipul')}</a></div>`;
      return `<div style="text-align:${esc(b.align||'center')};${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}">${h}</div>`;
    }
    case 'quote': {
      const qc = esc(b.text_color||'#166534');
      const autor = String(b.author||'').trim();
      return `<div style="background:${esc(b.background ?? '#f0fdf4')};${s.bgImg}border-left:4px solid ${esc(b.border_color||'#2d7d5f')};${s.vPad}padding-left:${s.pl>0?s.pl:16}px;padding-right:${s.pr}px;${s.font}text-align:${esc(b.align||'left')};"><div style="color:${qc};font-style:italic;font-size:${fontSize(b.font_size,16)}px;line-height:1.6;">&bdquo;<span${ed(cale)}>${bogat(b.content ?? '')}</span>&rdquo;</div>${autor ? `<div style="font-size:12px;color:${qc};opacity:.7;margin-top:5px;">— ${esc(autor)}</div>` : ''}</div>`;
    }
    case 'social': {
      const size = Math.max(20, Math.min(80, num(b.icon_size, 32)));
      const culori = {facebook:'#1877f2',instagram:'#e1306c',twitter:'#000000',linkedin:'#0a66c2',youtube:'#ff0000',tiktok:'#010101',pinterest:'#e60023'};
      const icoane = (b.links||[]).map(l => { const p = culori[String(l.platform||'').toLowerCase()] ? String(l.platform).toLowerCase() : 'facebook';
        return `<a href="#" onclick="return false" style="display:inline-block;width:${size}px;height:${size}px;background:${culori[p]};border-radius:50%;margin:0 4px;text-decoration:none;"><img src="/assets/img/social/${p}.png" alt="${esc(p.charAt(0).toUpperCase()+p.slice(1))}" width="${size}" height="${size}" style="display:block;width:${size}px;height:${size}px;border:0;border-radius:50%;"></a>`; }).join('');
      return `<div style="background:${esc(b.background ?? '#ffffff')};${s.bgImg}${s.hPad}${s.vPad}text-align:${esc(b.align||'center')};">${icoane || '<span class="eb-col-gol" style="display:inline-block">Niciun link social</span>'}</div>`;
    }
    case 'logo': {
      const poza = adresaMedia(String(b.image_url||'').trim() || (D.logo && D.logo.url) || '');
      const w = Math.min(600, Math.max(40, num(b.width, 160)));
      const alt = String(b.alt||'').trim() || (D.logo && D.logo.alt) || '';
      const interior = poza
        ? `<img src="${attr(poza)}" alt="${attr(alt)}" width="${w}" style="width:${w}px;max-width:100%;height:auto;border:none;display:inline-block;vertical-align:top;">`
        : `<span style="display:inline-block;${s.font}color:${esc(hex(b.text_color,'#1f2937'))};font-size:${fontSize(b.font_size,24)}px;font-weight:700;letter-spacing:.06em;line-height:1.2;">${esc((D.logo && D.logo.alt) || '')}</span>`;
      return `<div style="${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}text-align:${esc(aliniere(b.align))};"><a href="#" onclick="return false" style="text-decoration:none;border:none;">${interior}</a></div>`;
    }
    case 'menu': {
      const culoare = esc(hex(b.text_color, '#1f2937'));
      const fs = fontSize(b.font_size, 14);
      const linkuri = (b.items||[]).filter(it => String(it.label||'').trim() !== '').map(it =>
        `<a href="#" onclick="return false" style="display:inline-block;padding:6px 12px;${s.font}color:${culoare};font-size:${fs}px;font-weight:600;line-height:1.4;text-decoration:none;">${esc(String(it.label).trim())}</a>`).join('');
      if (!linkuri) return `<div class="eb-bloc-gol">Meniu: adaugă în dreapta categoriile sau linkurile</div>`;
      return `<div style="${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}text-align:${esc(aliniere(b.align))};">${linkuri}</div>`;
    }
    case 'coupon': {
      const cod = String(b.code||'').trim();
      if (!cod) return `<div class="eb-bloc-gol">Cod reducere: scrie codul în dreapta</div>`;
      const ct = esc(hex(b.text_color, '#1f2937'));
      const text = String(b.content||'').trim(), nota = String(b.note||'').trim();
      return `<div style="${fundal(b)}${s.bgImg}${s.hPad}${s.vPad}${s.font}text-align:${esc(aliniere(b.align))};">`
        + (text ? `<div style="color:${ct};font-size:15px;line-height:1.5;margin-bottom:10px;">${bogat(text)}</div>` : '')
        + `<div style="display:inline-block;border:2px dashed ${esc(hex(b.border_color,'#2d7d5f'))};border-radius:8px;padding:12px 22px;background:${esc(hex(b.code_background,'#f0fdf4'))};color:${esc(hex(b.code_color,'#14532d'))};font-size:${fontSize(b.font_size,24)}px;font-weight:700;letter-spacing:.12em;font-family:'Courier New',Courier,monospace;">${esc(cod)}</div>`
        + (nota ? `<div style="color:${ct};font-size:13px;line-height:1.5;margin-top:10px;">${esc(nota)}</div>` : '')
        + `</div>`;
    }
    case 'products': return produseHtml(b);
    case 'spacer':
      return `<div style="height:${Math.max(4, num(b.height,24))}px;${s.hPad}${fundal(b)}${s.bgImg}"></div>`;
    default:
      if (eColoane(t)) return coloaneHtml(b, cale);
      return `<div class="eb-bloc-gol">[${esc(t)}]</div>`;
  }
}

/** Secțiunea cu coloane, ca în email (coloane „spongioase” de cel mult 600 px împreună). */
function coloaneHtml(b, cale) {
  const s = stilCadru(b);
  const widths = colWidths(b.type);
  const cols = b.columns || [];
  const colBg = fundal(b);
  const colText = esc(b.text_color || '#1f2937');
  const celule = widths.map((w, ci) => {
    const maxW = Math.max(120, Math.round(600 * w / 100));
    const copii = (cols[ci] || []).map((sb, cbi) => {
      const c = cale + '.' + ci + '.' + cbi;
      const esteSel = sel && sel.ci === ci && sel.cbi === cbi && sel.i === +cale;
      return `<div class="eb-sub${esteSel ? ' is-sel' : ''}" data-sub="${c}" tabindex="0" role="listitem" aria-label="${attr(eticheta(sb.type))} în coloana ${ci+1}">`
        + (esteSel ? uneltele(eticheta(sb.type), true) : '')
        + blockHtml(sb, c, true) + `</div>`;
    }).join('');
    return `<div class="nl-col-div eb-col-cell" data-col-i="${cale}" data-col-ci="${ci}" style="display:inline-block;vertical-align:top;width:100%;max-width:${maxW}px;font-size:14px;text-align:left;">`
      + `<div style="${colBg}color:${colText};padding:10px;line-height:1.55;">${copii || '<span class="eb-col-gol">Trage aici un bloc</span>'}</div></div>`;
  }).join('');
  return `<div style="${s.vPad}${s.hPad}background:${esc(b.background ?? b.block_background ?? '#ffffff')};${s.bgImg}font-size:0;text-align:center;">${celule}</div>`;
}

/* ── Produsele: cele alese au copia lor; cele automate se cer serverului (aceeași alegere ca la trimitere) ── */
const produseCache = {};
function cheieProduse(b) { return ['auto', b.sursa||'vandute', num(b.zile,30), num(b.categorie_id,0), num(b.numar,3)].join('|'); }
function produseBloc(b) {
  if ((b.mod || 'manual') !== 'auto') return Array.isArray(b.produse) ? b.produse : [];
  const k = cheieProduse(b);
  if (produseCache[k] === undefined) {
    produseCache[k] = null;
    const q = new URLSearchParams({mod:'auto', sursa:b.sursa||'vandute', zile:String(num(b.zile,30)), categorie_id:String(num(b.categorie_id,0)), numar:String(num(b.numar,3))});
    fetch('/admin/emails/builder/produse?' + q.toString(), {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r => r.json()).then(d => { produseCache[k] = (d && Array.isArray(d.items)) ? d.items : []; renderCanvas(); })
      .catch(() => { produseCache[k] = []; renderCanvas(); });
  }
  return produseCache[k];
}
function pozaBuna(u) { u = String(u||'').trim(); return u !== '' && !/\.svgz?(\?|$)/i.test(u); }
function produseHtml(b) {
  const lista = produseBloc(b);
  if (lista === null) return `<div class="eb-bloc-gol">Se aleg produsele…</div>`;
  if (!lista.length) return `<div class="eb-bloc-gol">${(b.mod||'manual') === 'auto' ? 'Nu găsesc acum produse pentru alegerea automată (în email blocul nu apare până nu sunt).' : 'Produse: caută și alege produsele în dreapta'}</div>`;
  const s = stilCadru(b);
  const pe = [1,2,3].includes(num(b.pe_rand,3)) ? num(b.pe_rand,3) : 3;
  const maxW = pe === 1 ? 320 : Math.floor(600 / pe);
  const card = hex(b.card_background,'#ffffff'), ct = hex(b.text_color,'#1f2937'), cp = hex(b.price_color,'#1f2937');
  const cb = hex(b.button_color,'#2d7d5f'), cbt = hex(b.button_text_color,'#ffffff');
  // Eticheta implicită a butonului (ProduseEmail::html) vine de la server, doar cu catalogul pe site.
  const et = String(b.button_label||'').trim() || D.etichetaButonProdus || 'Vezi';
  const cuPret = num(b.show_price,1) === 1 && D.cuMagazin, cuButon = num(b.show_button,1) === 1;
  const celule = lista.map(p => {
    const poza = pozaBuna(p.image_url) ? p.image_url : '';
    let h = '';
    if (poza) h += `<a href="#" onclick="return false" style="text-decoration:none;border:none;"><img src="${attr(poza)}" alt="${attr(p.name)}" width="${maxW-36}" style="display:block;width:100%;max-width:${maxW-36}px;height:auto;border:0;margin:0 auto;border-radius:6px;"></a>`;
    h += `<div style="font-size:14px;font-weight:700;line-height:1.35;color:${ct};margin-top:${poza?8:0}px;">${esc(p.name)}</div>`;
    const pret = Number(p.price||0), vechi = p.old_price == null ? null : Number(p.old_price);
    if (cuPret && pret > 0) h += `<div style="font-size:14px;font-weight:700;color:${cp};margin-top:4px;">${vechi !== null && vechi > pret ? `<s style="color:#9ca3af;font-weight:400;">${esc(lei(vechi))}</s> ` : ''}${esc(lei(pret))}</div>`;
    if (cuButon && p.url) h += `<div style="margin-top:10px;"><a href="#" onclick="return false" style="display:inline-block;background:${cb};color:${cbt};padding:9px 16px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;">${esc(et)}</a></div>`;
    return `<div class="nl-prod" style="display:inline-block;vertical-align:top;width:100%;max-width:${maxW}px;font-size:14px;text-align:center;"><div style="padding:8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;background:${card};border-radius:8px;"><tr><td style="padding:10px;text-align:center;font-family:Arial,Helvetica,sans-serif;">${h}</td></tr></table></div></div>`;
  }).join('');
  const titlu = String(b.title||'').trim();
  return `<div style="${fundal(b)}${s.hPad}${s.vPad}text-align:center;">`
    + (titlu ? `<div style="${s.font}font-size:18px;font-weight:700;line-height:1.3;color:${esc(hex(b.title_color,'#1f2937'))};padding:4px 12px 6px;">${esc(titlu)}</div>` : '')
    + `<div style="font-size:0;text-align:center;">${celule}</div></div>`;
}

/** Eticheta (numele blocului) și uneltele blocului ales: peste bloc, fără să-i schimbe înălțimea. */
function uneltele(nume, inColoana) {
  const lista = inColoana ? blocks[sel.i].columns[sel.ci] : blocks;
  const poz = inColoana ? sel.cbi : sel.i;
  const blocat = !D.poateModifica ? ' disabled' : '';
  // Într-o coloană (loc puțin) uneltele stau sub bloc, scurte, ca să nu acopere textul.
  return `<span class="eb-eticheta">${esc(nume)}</span>`
    + `<span class="eb-unelte${inColoana ? ' eb-unelte--sub' : ''}" data-unelte>`
    + (inColoana ? '' : `<button type="button" class="eb-trage" draggable="true" data-act="trage" aria-label="Trage blocul în altă parte" title="Trage">⠿</button>`)
    + `<button type="button" data-act="sus" aria-label="Mută ${attr(nume)} mai sus" title="Mai sus"${poz===0?' disabled':''}${blocat}>↑</button>`
    + `<button type="button" data-act="jos" aria-label="Mută ${attr(nume)} mai jos" title="Mai jos"${poz===lista.length-1?' disabled':''}${blocat}>↓</button>`
    + `<button type="button" data-act="dup" aria-label="Duplică ${attr(nume)}" title="Duplică"${blocat}>${inColoana ? '⧉' : 'Duplică'}</button>`
    + `<button type="button" data-act="sterge" class="eb-sterge" aria-label="Șterge ${attr(nume)}" title="Șterge"${blocat}>${inColoana ? '✕' : 'Șterge'}</button>`
    + `</span>`;
}

/* ═══════════════════════════════════════════════════════════
   PÂNZA
═══════════════════════════════════════════════════════════ */
function render() { renderCanvas(); renderProps(); }

function renderCanvas() {
  const canvas = document.getElementById('eb-canvas');
  if (!canvas) return;
  if (editare && document.body.contains(editare.el)) return; // cât se scrie, pânza nu se redesenează
  editare = null;
  ascundeBaraText();
  if (!blocks.length) {
    canvas.innerHTML = `<div class="eb-empty-state">Emailul e gol. Adaugă un bloc din stânga (clic sau trage-l aici)`
      + (D.sabloane && Object.keys(D.sabloane).length ? ` sau <button type="button" class="eb-buton-lat" onclick="ebShowStarterModal()">alege un șablon</button>` : '') + `.</div><div class="eb-indicator" id="eb-indicator"></div>`;
  } else {
    canvas.innerHTML = blocks.map((b, i) => {
      const esteSel = sel && !sel.subsol && sel.i === i && sel.ci === undefined;
      const ascuns = [b.hide_desktop?'desktop':'', b.hide_tablet?'tabletă':'', b.hide_mobile?'mobil':''].filter(Boolean);
      return `<div class="eb-bloc${esteSel?' is-sel':''}" data-block-index="${i}" tabindex="0" role="listitem" aria-label="Blocul ${i+1}: ${attr(eticheta(b.type))}${ascuns.length?' (ascuns pe '+ascuns.join(', ')+')':''}"${esteSel?' aria-current="true"':''}>`
        + (esteSel ? uneltele(eticheta(b.type) + (ascuns.length ? ' · ascuns pe ' + ascuns.join(', ') : ''), false) : '')
        + blockHtml(b, String(i), false) + `</div>`;
    }).join('') + `<div class="eb-indicator" id="eb-indicator"></div>`;
  }
  // Scrisul pe pânză: textul blocului ales (titlu, text, citat) devine editabil.
  const ales = blocAles();
  if (ales && D.poateModifica && SCRIS_PE_PANZA.includes(ales.type)) {
    const cale = sel.ci !== undefined ? `${sel.i}.${sel.ci}.${sel.cbi}` : String(sel.i);
    const el = canvas.querySelector(`[data-edit="${cale}"]`);
    if (el) {
      el.setAttribute('contenteditable', 'true');
      el.setAttribute('role', 'textbox');
      el.setAttribute('aria-multiline', 'true');
      el.setAttribute('aria-label', 'Textul blocului ' + eticheta(ales.type) + ' (scrie direct aici)');
      el.setAttribute('spellcheck', 'true');
    }
  }
  const subsol = document.getElementById('eb-subsol');
  if (subsol) {
    if (!subsol.dataset.umplut) { subsol.innerHTML = D.subsol || ''; subsol.dataset.umplut = '1'; }
    subsol.classList.toggle('is-sel', !!(sel && sel.subsol));
  }
  const subiect = document.getElementById('eb-subiect-text');
  if (subiect) {
    const v = document.getElementById('eb-subject-input')?.value || '';
    subiect.textContent = v || 'fără subiect (scrie-l în setările emailului)';
    subiect.classList.toggle('eb-subiect-gol', !v);
  }
}

function focusBloc() {
  requestAnimationFrame(() => {
    if (!sel || sel.subsol) return;
    const canvas = document.getElementById('eb-canvas');
    const el = sel.ci !== undefined ? canvas.querySelector(`[data-sub="${sel.i}.${sel.ci}.${sel.cbi}"]`) : canvas.querySelector(`[data-block-index="${sel.i}"]`);
    if (el && document.activeElement !== el && !el.contains(document.activeElement)) el.focus({preventScroll:false});
    el?.scrollIntoView({block:'nearest'});
  });
}

function selecteaza(nou, focus) {
  const vechi = JSON.stringify(sel);
  if (JSON.stringify(nou) === vechi) return false;
  inchideEditarea(false);
  sel = nou;
  if (nou && !nou.subsol) tab = tab || 'continut';
  render();
  if (focus) focusBloc();
  return true;
}

(function legaPanza() {
  const canvas = document.getElementById('eb-canvas');
  const scroll = document.getElementById('eb-canvas-scroll');
  if (!canvas) return;

  // Alegerea unui bloc: clic. Pe un text, cursorul rămâne unde s-a dat clic (scrisul pe pânză).
  canvas.addEventListener('mousedown', e => {
    if (e.target.closest('[data-unelte]')) return;
    if (e.target.closest('[contenteditable="true"]')) return;
    const sub = e.target.closest('[data-sub]');
    const bloc = e.target.closest('[data-block-index]');
    if (!bloc) return;
    let nou;
    if (sub) { const [i, ci, cbi] = sub.dataset.sub.split('.').map(Number); nou = {i, ci, cbi}; }
    else nou = {i: +bloc.dataset.blockIndex};
    const x = e.clientX, y = e.clientY;
    if (selecteaza(nou, false)) {
      const b = blocAles();
      if (b && SCRIS_PE_PANZA.includes(b.type) && D.poateModifica) {
        e.preventDefault();
        requestAnimationFrame(() => puneCursorul(x, y));
      }
    }
  });
  canvas.addEventListener('click', e => {
    const btn = e.target.closest('[data-act]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    ({sus: () => moveSel(-1), jos: () => moveSel(1), dup: duplicateSel, sterge: removeSel})[btn.dataset.act]?.();
  });
  canvas.addEventListener('keydown', e => {
    if (e.target.closest('[contenteditable="true"]')) return;
    const sub = e.target.closest('[data-sub]');
    const bloc = e.target.closest('[data-block-index]');
    if (!bloc || e.target.closest('button')) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const nou = sub ? (([i, ci, cbi]) => ({i, ci, cbi}))(sub.dataset.sub.split('.').map(Number)) : {i: +bloc.dataset.blockIndex};
      selecteaza(nou, true);
      // Enter pe un bloc de text deja ales: se intră în scris.
      if (!e.repeat && JSON.stringify(nou) === JSON.stringify(sel)) {
        const el = document.querySelector('#eb-canvas [contenteditable="true"]');
        if (el && e.key === 'Enter') { el.focus(); }
      }
    }
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && sel) { e.preventDefault(); moveSel(e.key === 'ArrowUp' ? -1 : 1); }
  });

  // Scrisul pe pânză
  canvas.addEventListener('focusin', e => {
    const el = e.target.closest('[contenteditable="true"]');
    if (!el) return;
    editare = {el, cale: el.dataset.edit};
    arataBaraText(el);
  });
  canvas.addEventListener('focusout', e => {
    const el = e.target.closest('[contenteditable="true"]');
    if (!el) return;
    setTimeout(() => {
      const bara = document.getElementById('eb-bara-text');
      if (document.activeElement && (bara.contains(document.activeElement) || el.contains(document.activeElement))) return;
      if (raportBara) return;
      inchideEditarea(true);
    }, 0);
  });
  canvas.addEventListener('input', e => {
    const el = e.target.closest('[contenteditable="true"]');
    if (!el) return;
    scrieDinPanza(el);
  });
  canvas.addEventListener('keydown', e => {
    const el = e.target.closest('[contenteditable="true"]');
    if (!el) return;
    if (e.key === 'Enter') { e.preventDefault(); document.execCommand('insertLineBreak'); }
    if (e.key === 'Escape') { e.preventDefault(); inchideEditarea(true); focusBloc(); }
    if ((e.ctrlKey || e.metaKey) && ['b','i','u'].includes(e.key.toLowerCase())) {
      e.preventDefault();
      document.execCommand({b:'bold', i:'italic', u:'underline'}[e.key.toLowerCase()]);
      scrieDinPanza(el);
    }
  });
  // Lipirea pe pânză: doar text (fără etichetele, stilurile și scripturile paginii de unde vine).
  canvas.addEventListener('paste', e => {
    const el = e.target.closest('[contenteditable="true"]');
    if (!el) return;
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text);
  });
  canvas.addEventListener('drop', e => { if (e.target.closest('[contenteditable="true"]') && !(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) && !dragState) e.preventDefault(); }, true);

  // Tragerea: din paletă (bloc nou), un bloc al emailului (mutarea), o poză din calculator (bloc Imagine nou sau poza blocului).
  const indicator = () => document.getElementById('eb-indicator');
  const tintaDrop = e => {
    const cell = e.target.closest('.eb-col-cell');
    if (cell && dragState && dragState.source === 'palette' && IN_COLOANA.includes(dragState.type)) return {cell};
    const cards = [...canvas.querySelectorAll(':scope > .eb-bloc')];
    let idx = cards.length;
    for (let k = 0; k < cards.length; k++) {
      const r = cards[k].getBoundingClientRect();
      if (e.clientY < r.top + r.height / 2) { idx = k; break; }
    }
    const pe = e.target.closest('[data-block-index]');
    return {idx, pe: pe ? +pe.dataset.blockIndex : -1, cards};
  };
  const curata = () => {
    canvas.querySelectorAll('.col-drag-over').forEach(c => c.classList.remove('col-drag-over'));
    const ind = indicator(); if (ind) ind.style.display = 'none';
    canvas.classList.remove('is-drag-fisier');
  };
  const areFisiere = e => e.dataTransfer && [...(e.dataTransfer.types || [])].includes('Files');
  canvas.addEventListener('dragover', e => {
    if (!dragState && !areFisiere(e)) return;
    if (!D.poateModifica) return;
    e.preventDefault();
    curata();
    if (!dragState) { canvas.classList.add('is-drag-fisier'); e.dataTransfer.dropEffect = 'copy'; }
    const t = tintaDrop(e);
    if (t.cell) { t.cell.classList.add('col-drag-over'); e.dataTransfer.dropEffect = 'copy'; return; }
    const ind = indicator();
    if (ind) {
      const er = canvas.getBoundingClientRect();
      const y = t.idx < t.cards.length ? t.cards[t.idx].getBoundingClientRect().top : (t.cards.length ? t.cards[t.cards.length-1].getBoundingClientRect().bottom : er.top + 4);
      ind.style.top = (y - er.top + canvas.scrollTop) + 'px';
      ind.style.display = 'block';
    }
    if (dragState) e.dataTransfer.dropEffect = dragState.source === 'palette' ? 'copy' : 'move';
  });
  canvas.addEventListener('dragleave', e => { if (!canvas.contains(e.relatedTarget)) curata(); });
  canvas.addEventListener('drop', async e => {
    if (!D.poateModifica) return;
    const t = tintaDrop(e);
    const fisiere = e.dataTransfer && e.dataTransfer.files ? [...e.dataTransfer.files].filter(f => /^image\//.test(f.type)) : [];
    curata();
    if (!dragState && fisiere.length) {
      e.preventDefault();
      const pe = t.pe >= 0 ? blocks[t.pe] : null;
      try {
        stareSalvare('salveaza', 'Se încarcă poza…');
        const imagini = await urcaPoze(fisiere.slice(0, 1));
        if (pe && (pe.type === 'image' || pe.type === 'logo')) { sel = {i: t.pe}; updateBlock({image_url: imagini[0].url}); }
        else { pushHistory(); const b = blockDefault('image'); b.image_url = imagini[0].url; blocks.splice(t.idx, 0, b); sel = {i: t.idx}; render(); sync(); markDirty(); }
        if (!dirty) clearDirty();
      } catch (err) { stareSalvare('eroare', err.message); }
      return;
    }
    if (!dragState) return;
    e.preventDefault();
    if (t.cell) {
      addInColoana(+t.cell.dataset.colI, +t.cell.dataset.colCi, dragState.type);
    } else if (dragState.source === 'palette') {
      addBlock(dragState.type, t.idx);
    } else if (dragState.source === 'canvas') {
      moveBlock(dragState.index, t.idx);
    }
    dragState = null;
  });
  canvas.addEventListener('dragstart', e => {
    const h = e.target.closest('[data-act="trage"]');
    if (!h || !sel || sel.ci !== undefined) return;
    dragState = {source:'canvas', index: sel.i};
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', 'bloc'); } catch (err) {}
  });
  canvas.addEventListener('dragend', () => { dragState = null; curata(); });

  // Clic pe fundalul pânzei: nimic ales (panoul arată setările emailului).
  scroll.addEventListener('mousedown', e => {
    if (e.target === scroll || e.target.id === 'eb-canvas-inner') selecteaza(null, false);
  });
  const subsol = document.getElementById('eb-subsol');
  subsol?.addEventListener('click', e => { e.preventDefault(); selecteaza({subsol:true}, false); });
  subsol?.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selecteaza({subsol:true}, false); } });
  document.getElementById('eb-subiect-bara')?.addEventListener('click', () => {
    selecteaza(null, false);
    document.getElementById('eb-set-subject')?.focus();
  });
})();

/* ── Scrisul pe pânză: DOM → textul blocului (exact formatul de până acum: text cu \n și etichetele editorului) ── */
function culoareHex(v) {
  v = String(v || '').trim();
  if (/^#[0-9a-f]{3,8}$/i.test(v)) return v.toLowerCase();
  const m = v.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
  if (!m) return '';
  return '#' + [m[1], m[2], m[3]].map(x => Math.max(0, Math.min(255, +x)).toString(16).padStart(2, '0')).join('');
}
function serializeaza(radacina) {
  let out = '';
  const walk = (n) => {
    n.childNodes.forEach(c => {
      if (c.nodeType === 3) { out += c.nodeValue.replace(/ /g, ' '); return; }
      if (c.nodeType !== 1) return;
      const tag = c.tagName.toLowerCase();
      if (tag === 'br') { out += '\n'; return; }
      if (tag === 'div' || tag === 'p') { if (out !== '' && !out.endsWith('\n')) out += '\n'; walk(c); return; }
      let deschide = '', inchide = '';
      if (tag === 'b' || tag === 'strong') { deschide = '<b>'; inchide = '</b>'; }
      else if (tag === 'i' || tag === 'em') { deschide = '<i>'; inchide = '</i>'; }
      else if (tag === 'u') { deschide = '<u>'; inchide = '</u>'; }
      else if (tag === 's' || tag === 'strike' || tag === 'del') { deschide = '<s>'; inchide = '</s>'; }
      else if (tag === 'a') {
        const h = c.getAttribute('href') || '';
        if (h && linkSigur(h) && !/["<>]/.test(h)) { deschide = '<a href="' + h + '">'; inchide = '</a>'; }
      } else if (tag === 'span' || tag === 'font') {
        const col = culoareHex(tag === 'font' ? c.getAttribute('color') : c.style.color);
        if (col) { deschide = '<span style="color:' + col + '">'; inchide = '</span>'; }
        if (c.style && (c.style.fontWeight === 'bold' || +c.style.fontWeight >= 600)) { deschide += '<b>'; inchide = '</b>' + inchide; }
        if (c.style && c.style.fontStyle === 'italic') { deschide += '<i>'; inchide = '</i>' + inchide; }
      }
      out += deschide; walk(c); out += inchide;
    });
  };
  walk(radacina);
  return out.replace(/\n$/, '');
}
function caleBloc(cale) {
  const p = String(cale).split('.').map(Number);
  if (p.length === 3) return ((blocks[p[0]]?.columns || [])[p[1]] || [])[p[2]] || null;
  return blocks[p[0]] || null;
}
function scrieDinPanza(el) {
  const b = caleBloc(el.dataset.edit);
  if (!b) return;
  pushHistoryRar();
  b.content = serializeaza(el);
  sync(); markDirty();
  const ta = document.querySelector('#eb-props-body textarea[data-k="content"]');
  if (ta && document.activeElement !== ta) ta.value = b.content;
  arataBaraText(el);
}
function inchideEditarea(redeseneaza) {
  if (!editare) return;
  const el = editare.el;
  if (document.body.contains(el)) scrieDinPanzaFaraIstoric(el);
  editare = null;
  ascundeBaraText();
  if (redeseneaza) renderCanvas();
}
function scrieDinPanzaFaraIstoric(el) {
  const b = caleBloc(el.dataset.edit);
  if (b) { const v = serializeaza(el); if (v !== b.content) { b.content = v; sync(); markDirty(); } }
}
function puneCursorul(x, y) {
  const el = document.querySelector('#eb-canvas [contenteditable="true"]');
  if (!el) return;
  el.focus({preventScroll:true});
  let r = null;
  if (document.caretRangeFromPoint) r = document.caretRangeFromPoint(x, y);
  else if (document.caretPositionFromPoint) { const p = document.caretPositionFromPoint(x, y); if (p) { r = document.createRange(); r.setStart(p.offsetNode, p.offset); } }
  if (r && el.contains(r.startContainer)) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
}

/* ── Bara mică de text (B / I / U / link / culoare) ── */
let raportBara = false;
let selectieSalvata = null;
function arataBaraText(el) {
  const bara = document.getElementById('eb-bara-text');
  const scroll = document.getElementById('eb-canvas-scroll');
  if (!bara || !scroll) return;
  bara.hidden = false;
  const r = el.getBoundingClientRect(), s = scroll.getBoundingClientRect();
  const sus = r.top - s.top + scroll.scrollTop - bara.offsetHeight - 8;
  bara.style.top = Math.max(scroll.scrollTop + 4, sus < scroll.scrollTop ? r.bottom - s.top + scroll.scrollTop + 8 : sus) + 'px';
  bara.style.left = Math.max(8, r.left - s.left + scroll.scrollLeft) + 'px';
}
function ascundeBaraText() { const bara = document.getElementById('eb-bara-text'); if (bara) bara.hidden = true; }
(function legaBaraText() {
  const bara = document.getElementById('eb-bara-text');
  if (!bara) return;
  const salveazaSelectia = () => { const s = window.getSelection(); selectieSalvata = s && s.rangeCount ? s.getRangeAt(0).cloneRange() : null; };
  // Selecția de pe pânză se ține minte mereu (culoarea se alege într-o fereastră a browserului, care ia focusul).
  document.addEventListener('selectionchange', () => {
    if (!editare || raportBara) return;
    const s = window.getSelection();
    if (s && s.rangeCount && editare.el.contains(s.getRangeAt(0).commonAncestorContainer)) selectieSalvata = s.getRangeAt(0).cloneRange();
  });
  const refaSelectia = () => { if (!selectieSalvata) return; const s = window.getSelection(); s.removeAllRanges(); s.addRange(selectieSalvata); };
  bara.addEventListener('mousedown', e => { if (!e.target.closest('label')) e.preventDefault(); else { salveazaSelectia(); raportBara = true; } });
  bara.addEventListener('click', e => {
    const btn = e.target.closest('button[data-cmd]');
    if (!btn || !editare) return;
    const el = editare.el;
    document.execCommand('styleWithCSS', false, false);
    if (btn.dataset.cmd === 'link') {
      salveazaSelectia();
      raportBara = true;
      const adresa = window.prompt('Adresa linkului (https://…, /pagina-din-site sau mailto:…):', 'https://');
      raportBara = false;
      el.focus();
      refaSelectia();
      if (!adresa || adresa === 'https://') return;
      if (!linkSigur(adresa) || /["<>]/.test(adresa)) { alert('Adresa nu e bună: folosește https://…, o cale din site (/…) sau mailto:…'); return; }
      const s = window.getSelection();
      if (s && s.isCollapsed) document.execCommand('insertHTML', false, '<a href="' + esc(adresa) + '">' + esc(adresa) + '</a>');
      else document.execCommand('createLink', false, adresa);
    } else {
      document.execCommand(btn.dataset.cmd);
    }
    scrieDinPanza(el);
  });
  const culoare = document.getElementById('eb-bara-culoare');
  culoare?.addEventListener('input', () => {
    if (!editare) return;
    const el = editare.el;
    el.focus();
    refaSelectia();
    document.execCommand('styleWithCSS', false, false);
    document.execCommand('foreColor', false, culoare.value);
    salveazaSelectia();
    scrieDinPanza(el);
  });
  culoare?.addEventListener('change', () => { raportBara = false; if (editare) editare.el.focus(); });
  culoare?.addEventListener('blur', () => { setTimeout(() => { raportBara = false; }, 0); });
})();

/* ═══════════════════════════════════════════════════════════
   PANOUL DIN DREAPTA (Conținut / Aspect / Afișare)
═══════════════════════════════════════════════════════════ */
const camp = (eticheta, interior, nota) => `<label class="eb-camp"><span>${eticheta}</span>${interior}${nota ? `<small>${nota}</small>` : ''}</label>`;
const grupCamp = (eticheta, interior, nota) => `<div class="eb-camp"><span>${eticheta}</span>${interior}${nota ? `<small>${nota}</small>` : ''}</div>`;
const intrare = (k, v, ph, tip) => `<input type="${tip||'text'}" data-k="${k}" value="${attr(v??'')}" placeholder="${attr(ph||'')}">`;
const numar = (k, v, mn, mx) => `<input type="number" data-k="${k}" value="${num(v,0)}" min="${mn}" max="${mx}">`;
const glisor = (k, v, mn, mx, eticheta) => camp(`${eticheta}: <b data-arata="${k}">${num(v,0)}</b>`, `<input type="range" data-k="${k}" value="${num(v,0)}" min="${mn}" max="${mx}">`);
const bifa = (k, v, eticheta) => `<label class="eb-bifa"><input type="checkbox" data-kc="${k}" ${v ? 'checked' : ''}> ${eticheta}</label>`;
function segment(k, v, optiuni) {
  return `<div class="eb-segment" role="group">${optiuni.map(([val, et]) => `<button type="button" data-seg="${k}" data-val="${attr(val)}" aria-pressed="${String(v) === String(val)}">${et}</button>`).join('')}</div>`;
}
function culori(k, v, implicit) {
  const val = String(v ?? '').trim();
  const sugestii = [...new Set([C.principala, C.inchisa, C.fundal_deschis, '#ffffff', '#f6f8f5', '#111827'].map(x => String(x).toLowerCase()))];
  return `<div class="eb-culori" data-culori="${k}">`
    + sugestii.map(c => `<button type="button" data-culoare="${c}" style="background:${c}" aria-label="Culoarea ${c}" aria-pressed="${val.toLowerCase() === c}"></button>`).join('')
    + `<input type="color" data-k-culoare="${k}" value="${attr(/^#[0-9a-fA-F]{6}$/.test(val) ? val : (implicit || '#ffffff'))}" aria-label="Altă culoare">`
    + `<input type="text" data-k="${k}" value="${attr(val)}" placeholder="${attr(implicit||'')}" aria-label="Codul culorii">`
    + `</div>`;
}
const alinieri = (k, v) => segment(k, v, [['left','Stânga'],['center','Centru'],['right','Dreapta']]);
function margini(b) {
  const v = Math.max(num(b.padding_top,0), num(b.padding_bottom,0));
  const o = Math.max(num(b.padding_left,0), num(b.padding_right,0));
  return grupCamp('Spațiu în jur', `
    <label class="eb-camp"><span>Sus și jos: <b data-arata="_v">${v}</b> px</span><input type="range" data-pad="v" value="${v}" min="0" max="80"></label>
    <label class="eb-camp"><span>Stânga și dreapta: <b data-arata="_o">${o}</b> px</span><input type="range" data-pad="o" value="${o}" min="0" max="80"></label>
    <details><summary class="eb-nota" style="cursor:pointer">Fiecare latură</summary><div class="eb-grila2" style="margin-top:8px">
      ${camp('Sus', numar('padding_top', b.padding_top, 0, 200))}${camp('Jos', numar('padding_bottom', b.padding_bottom, 0, 200))}
      ${camp('Stânga', numar('padding_left', b.padding_left, 0, 200))}${camp('Dreapta', numar('padding_right', b.padding_right, 0, 200))}
    </div></details>`);
}
const EB_FONTS = [['','Implicit (Arial)'],['arial','Arial'],['helvetica','Helvetica'],['verdana','Verdana'],['tahoma','Tahoma'],['trebuchet','Trebuchet MS'],['georgia','Georgia'],['times','Times New Roman'],['palatino','Palatino'],['courier','Courier New'],['lucida','Lucida Sans']];
const fonturi = v => camp('Font', `<select data-k="font_family">${EB_FONTS.map(([k,l]) => `<option value="${k}" ${(v||'')===k?'selected':''}>${l}</option>`).join('')}</select>`);
function zonaPoza(k, url, eticheta) {
  return grupCamp(eticheta || 'Poza', `<div class="eb-drop-poza" data-drop-poza="${k}" tabindex="0" role="button" aria-label="Trage poza aici sau alege din calculator">
      ${url ? `<img src="${attr(url)}" alt="">` : ''}
      <strong>Trage poza aici</strong><br>sau <button type="button" data-alege-fisier>alege din calculator</button> · <button type="button" data-alege-galerie="${k}">din galerie</button>
      <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <div class="eb-drop-stare" aria-live="polite"></div>
    </div>`, 'JPG, PNG, WebP sau GIF; poza intră și în Galerie. Poți și lipi o poză (Ctrl+V) cât e ales blocul.');
}
function imagineFundal(b) {
  return grupCamp('Imagine de fundal', `<div class="eb-rand">${intrare('bg_image', b.bg_image||'', '/uploads/…')}<button type="button" class="eb-mic" data-alege-galerie="bg_image" aria-label="Alege imaginea de fundal din galerie">…</button></div>
    <select data-k="bg_size" aria-label="Cum se așază imaginea de fundal"><option value="cover" ${(b.bg_size||'cover')==='cover'?'selected':''}>Acoperă tot</option><option value="contain" ${b.bg_size==='contain'?'selected':''}>Încape toată</option><option value="auto" ${b.bg_size==='auto'?'selected':''}>Mărimea ei</option></select>`);
}
function textBogat(k, v) {
  return camp('Textul (îl poți scrie și direct în email)', `<textarea data-k="${k}" rows="4">${esc(v||'')}</textarea>`, 'Rând nou = Enter. Pe email, selectează textul ca să-l faci îngroșat, cursiv, subliniat, link sau colorat.');
}

function campuri(b) {
  const t = b.type;
  const cont = [], asp = [];
  const inCol = sel && sel.ci !== undefined;
  switch (t) {
    case 'header':
    case 'text':
      cont.push(textBogat('content', b.content));
      asp.push(camp('Mărime text pe calculator (px)', numar('font_size', b.font_size || (t==='header'?32:16), 8, 96)));
      asp.push(camp('Mărime text pe telefon (px)', numar('font_size_mobile', b.font_size_mobile || (t==='header'?24:14), 8, 96)));
      asp.push(fonturi(b.font_family));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || (t==='header'?'center':'left'))));
      asp.push(grupCamp('Culoarea textului', culori('text_color', b.text_color, t==='header'?'#ffffff':'#333333')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff'), inCol ? 'Gol = fără fundal (se vede culoarea coloanei).' : 'Culorile site-ului apar primele.'));
      if (inCol) asp.push(bifa('_fara_fundal', String(b.background||'').trim()==='', 'Fără fundal (se vede culoarea coloanei)'));
      if (!inCol) asp.push(imagineFundal(b));
      break;
    case 'image':
      cont.push(zonaPoza('image_url', b.image_url));
      cont.push(camp('Adresa pozei', intrare('image_url', b.image_url, 'https://… sau /uploads/…')));
      cont.push(camp('Text alternativ (pentru cine nu vede poza)', intrare('alt', b.alt, 'ce arată poza')));
      cont.push(camp('Link când se apasă', intrare('link_url', b.link_url, 'https://… sau /pagina'), 'Gol = poza nu e link.'));
      asp.push(glisor('width', b.width || 100, 10, 100, 'Lățime (%)'));
      asp.push(glisor('border_radius', b.border_radius || 0, 0, 80, 'Rotunjire colțuri (px)'));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      if (inCol) asp.push(bifa('_fara_fundal', String(b.background||'').trim()==='', 'Fără fundal (se vede culoarea coloanei)'));
      break;
    case 'logo':
      cont.push(`<div class="eb-info-mic">${D.logo && D.logo.url ? 'Fără o poză aleasă aici, apare logo-ul site-ului.' : 'Site-ul nu are un logo pentru emailuri, deci apare numele magazinului. Poți alege o poză mai jos.'}</div>`);
      cont.push(zonaPoza('image_url', b.image_url || (D.logo && D.logo.url) || '', 'Alt logo (opțional)'));
      if (b.image_url) cont.push(`<button type="button" class="eb-buton-lat" data-logo-site>Folosește logo-ul site-ului</button>`);
      cont.push(camp('Link când se apasă', intrare('link_url', b.link_url ?? '/', '/'), 'Implicit prima pagină a site-ului.'));
      cont.push(camp('Text alternativ', intrare('alt', b.alt, (D.logo && D.logo.alt) || '')));
      asp.push(glisor('width', b.width || 160, 40, 400, 'Mărime (lățime în px)'));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff'), 'Alb implicit.'));
      asp.push(grupCamp('Culoarea numelui (fără poză)', culori('text_color', b.text_color, C.principala)));
      break;
    case 'menu': {
      const items = Array.isArray(b.items) ? b.items : [];
      cont.push(grupCamp('Linkurile meniului', `<div class="eb-lista">${items.map((it, j) => `<div class="eb-lista-rand">
          <div style="flex:1;display:flex;flex-direction:column;gap:4px;min-width:0">
            <input type="text" data-item="${j}" data-ik="label" value="${attr(it.label)}" aria-label="Textul linkului ${j+1}" maxlength="60">
            <input type="text" data-item="${j}" data-ik="url" value="${attr(it.url)}" aria-label="Adresa linkului ${j+1}" placeholder="/categorie/…">
          </div>
          <button type="button" class="eb-mic" data-item-mut="${j}" data-dir="-1" aria-label="Mută linkul ${j+1} mai sus" ${j===0?'disabled':''}>↑</button>
          <button type="button" class="eb-mic" data-item-mut="${j}" data-dir="1" aria-label="Mută linkul ${j+1} mai jos" ${j===items.length-1?'disabled':''}>↓</button>
          <button type="button" class="eb-mic eb-sterge" data-item-sterge="${j}" aria-label="Scoate linkul ${j+1}">✕</button>
        </div>`).join('') || '<span class="eb-nota">Niciun link încă.</span>'}</div>`));
      if ((D.categorii || []).length) {
        cont.push(grupCamp('Adaugă o categorie din catalog', `<div class="eb-rand"><select data-cat-noua aria-label="Categoria de adăugat">${D.categorii.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select><button type="button" class="eb-buton-lat" data-adauga-cat>Adaugă</button></div>`));
      }
      cont.push(`<button type="button" class="eb-buton-lat" data-adauga-link>+ Link scris de mine</button>`);
      asp.push(grupCamp('Fundal', culori('background', b.background, C.principala)));
      asp.push(grupCamp('Culoarea textului', culori('text_color', b.text_color, '#ffffff')));
      asp.push(camp('Mărime text (px)', numar('font_size', b.font_size || 14, 10, 30)));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(fonturi(b.font_family));
      break;
    }
    case 'products': {
      const auto = (b.mod || 'manual') === 'auto';
      cont.push(grupCamp('Ce produse', segment('mod', auto ? 'auto' : 'manual', [['manual','Aleg eu'],['auto','Automat']])));
      if (auto) {
        cont.push(camp('Care produse', `<select data-k="sursa">${[['vandute','Cele mai vândute'],['noi','Produse noi'],['categorie','Din categoria…'],['reduse','Reduse acum'],['recomandate','Recomandate fiecărui abonat']].map(([v,l]) => `<option value="${v}" ${(b.sursa||'vandute')===v?'selected':''}>${l}</option>`).join('')}</select>`,
          ({vandute:'Cele mai vândute din ultimele zile alese, la trimitere.', noi:'Cele mai noi produse active, la trimitere.', categorie:'Cele mai vândute din categorie, la trimitere.', reduse:'Produsele cu preț redus acum, cea mai mare reducere întâi.', recomandate:'Pentru fiecare abonat, din categoriile ultimei lui comenzi (fără ce a cumpărat). Cine n-a comandat primește cele mai vândute.'})[b.sursa||'vandute']));
        if ((b.sursa||'vandute') === 'vandute') cont.push(camp('Din ultimele (zile)', numar('zile', b.zile || 30, 1, 365)));
        if (b.sursa === 'categorie') cont.push(camp('Categoria', `<select data-k="categorie_id"><option value="0">Alege categoria</option>${(D.categorii||[]).map(c => `<option value="${c.id}" ${num(b.categorie_id,0)===c.id?'selected':''}>${esc(c.name)}</option>`).join('')}</select>`));
        cont.push(camp('Câte produse', numar('numar', b.numar || 3, 1, 12)));
        cont.push(`<div class="eb-info-mic">Produsele se aleg când pleacă emailul. În email intră doar produse active, cu poză.</div>`);
      } else {
        const alese = Array.isArray(b.produse) ? b.produse : [];
        cont.push(grupCamp('Produsele alese', `<div class="eb-lista">${alese.map((p, j) => `<div class="eb-lista-rand">
            ${pozaBuna(p.image_url) ? `<img src="${attr(p.image_url)}" alt="">` : '<img alt="">'}
            <span class="eb-lista-text">${esc(p.name)}${D.cuMagazin && Number(p.price) > 0 ? `<small>${esc(lei(Number(p.price)))}</small>` : ''}</span>
            <button type="button" class="eb-mic" data-prod-mut="${j}" data-dir="-1" aria-label="Mută ${attr(p.name)} mai sus" ${j===0?'disabled':''}>↑</button>
            <button type="button" class="eb-mic" data-prod-mut="${j}" data-dir="1" aria-label="Mută ${attr(p.name)} mai jos" ${j===alese.length-1?'disabled':''}>↓</button>
            <button type="button" class="eb-mic eb-sterge" data-prod-sterge="${j}" aria-label="Scoate ${attr(p.name)}">✕</button>
          </div>`).join('') || '<span class="eb-nota">Niciun produs ales încă.</span>'}</div>`));
        cont.push(camp('Caută un produs', `<input type="search" data-cauta-produs placeholder="numele sau codul produsului" autocomplete="off">`, 'Cel mult 12 produse. La trimitere se iau numele, poza și prețul de atunci.'));
        cont.push(`<div class="eb-lista" data-rezultate-produse aria-live="polite"></div>`);
      }
      cont.push(grupCamp('Câte pe rând', segment('pe_rand', num(b.pe_rand,3), [[1,'1'],[2,'2'],[3,'3']]), 'Pe telefon trec unul sub altul.'));
      cont.push(camp('Titlu deasupra (opțional)', intrare('title', b.title, 'ex. Recomandate pentru tine')));
      asp.push(camp('Textul butonului', intrare('button_label', b.button_label, D.etichetaButonProdus || '')));
      asp.push(bifa('show_price', num(b.show_price,1) === 1, 'Arată prețul'));
      asp.push(bifa('show_button', num(b.show_button,1) === 1, 'Arată butonul'));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      asp.push(grupCamp('Fundalul cardurilor', culori('card_background', b.card_background, '#ffffff')));
      asp.push(grupCamp('Culoarea butonului', culori('button_color', b.button_color, C.principala)));
      asp.push(grupCamp('Culoarea textului butonului', culori('button_text_color', b.button_text_color, '#ffffff')));
      asp.push(grupCamp('Culoarea numelui', culori('text_color', b.text_color, '#1f2937')));
      asp.push(grupCamp('Culoarea prețului', culori('price_color', b.price_color, '#1f2937')));
      asp.push(grupCamp('Culoarea titlului', culori('title_color', b.title_color, '#1f2937')));
      break;
    }
    case 'coupon':
      cont.push(camp('Codul', `<input type="text" data-k="code" value="${attr(b.code)}" list="eb-cupoane" maxlength="60" style="text-transform:uppercase">`
        + `<datalist id="eb-cupoane">${(D.cupoane||[]).map(c => `<option value="${attr(c)}">`).join('')}</datalist>`, (D.cupoane||[]).length ? 'Alege unul dintre cupoanele active sau scrie altul.' : 'Codul unui cupon din Marketing → Cupoane.'));
      cont.push(camp('Text deasupra', `<textarea data-k="content" rows="2">${esc(b.content||'')}</textarea>`));
      cont.push(camp('Text dedesubt (opțional)', intrare('note', b.note, 'ex. Valabil până duminică')));
      asp.push(camp('Mărimea codului (px)', numar('font_size', b.font_size || 24, 12, 48)));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      asp.push(grupCamp('Fundalul codului', culori('code_background', b.code_background, '#f0fdf4')));
      asp.push(grupCamp('Culoarea codului', culori('code_color', b.code_color, '#14532d')));
      asp.push(grupCamp('Chenarul', culori('border_color', b.border_color, C.principala)));
      asp.push(grupCamp('Culoarea textului', culori('text_color', b.text_color, '#1f2937')));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      break;
    case 'video':
      cont.push(camp('Link video', `<div class="eb-rand">${intrare('video_url', b.video_url, 'https://www.tiktok.com/@cont/video/...')}<button type="button" class="eb-mic" onclick="ebVideoLookup()" aria-label="Ia titlul și coperta de la TikTok / YouTube / Vimeo">⤓</button></div>`));
      cont.push(`<small class="eb-nota" id="eb-video-status">TikTok, YouTube, Vimeo sau un fișier .mp4. Apasă ⤓ ca să aduc singur coperta și titlul.</small>`);
      cont.push(zonaPoza('thumbnail_url', b.thumbnail_url, 'Coperta'));
      cont.push(camp('Titlu sub video (opțional)', intrare('title', b.title, 'ex: Cum se folosește produsul')));
      cont.push(camp('Text buton', intrare('label', b.label || '▶ Vezi videoclipul')));
      cont.push(`<div class="eb-info-mic">În email se trimite coperta cu un buton dedesubt, nu un player: Gmail, Outlook și Apple Mail nu redau video în mesaj. Clicul duce la videoclip.</div>`);
      asp.push(glisor('width', b.width || 100, 20, 100, 'Lățime copertă (%)'));
      asp.push(glisor('border_radius', b.border_radius ?? 10, 0, 80, 'Rotunjire copertă (px)'));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(grupCamp('Culoare buton', culori('button_color', b.button_color, '#2d7d5f')));
      asp.push(grupCamp('Culoare text buton', culori('text_color', b.text_color, '#ffffff')));
      asp.push(camp('Rotunjire buton (px)', numar('button_radius', b.button_radius ?? 6, 0, 50)));
      asp.push(grupCamp('Culoare titlu', culori('title_color', b.title_color, '#1f2937')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      break;
    case 'button':
      cont.push(camp('Textul butonului', intrare('label', b.label ?? 'Apasă aici')));
      cont.push(camp('Link când se apasă', intrare('url', b.url, 'https://… sau /pagina')));
      asp.push(grupCamp('Culoarea butonului', culori('button_color', b.button_color, '#2d7d5f')));
      asp.push(grupCamp('Culoarea textului', culori('text_color', b.text_color, '#ffffff')));
      asp.push(camp('Mărime text (px)', numar('font_size', b.font_size || 16, 10, 40)));
      asp.push(`<div class="eb-grila2">${camp('Înălțime (px)', numar('btn_pad_v', b.btn_pad_v ?? 11, 2, 40))}${camp('Lățime (px)', numar('btn_pad_h', b.btn_pad_h ?? 22, 4, 80))}</div>`);
      asp.push(camp('Rotunjire colțuri (px)', numar('border_radius', b.border_radius ?? 6, 0, 50)));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      if (inCol) asp.push(bifa('_fara_fundal', String(b.background||'').trim()==='', 'Fără fundal (se vede culoarea coloanei)'));
      if (!inCol) asp.push(imagineFundal(b));
      break;
    case 'quote':
      cont.push(textBogat('content', b.content));
      cont.push(camp('Autor', intrare('author', b.author, 'Opțional...')));
      asp.push(camp('Mărime text (px)', numar('font_size', b.font_size || 16, 10, 40)));
      asp.push(fonturi(b.font_family));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'left')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#f0fdf4')));
      asp.push(grupCamp('Culoare text', culori('text_color', b.text_color, '#166534')));
      asp.push(grupCamp('Culoare bordură', culori('border_color', b.border_color, '#2d7d5f')));
      break;
    case 'social': {
      const platforme = ['facebook','instagram','twitter','linkedin','youtube','tiktok','pinterest'];
      cont.push(grupCamp('Rețelele', `<div class="eb-lista">${(b.links||[]).map((l, j) => `<div class="eb-lista-rand">
          <select data-social="${j}" data-sk="platform" aria-label="Rețeaua ${j+1}" style="width:110px">${platforme.map(p => `<option value="${p}" ${l.platform===p?'selected':''}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`).join('')}</select>
          <input type="url" data-social="${j}" data-sk="url" value="${attr(l.url||'')}" placeholder="https://..." aria-label="Adresa rețelei ${j+1}">
          <button type="button" class="eb-mic eb-sterge" data-social-sterge="${j}" aria-label="Scoate rețeaua ${j+1}">✕</button></div>`).join('')}</div>`));
      cont.push(`<button type="button" class="eb-buton-lat" data-social-adauga>+ Adaugă rețea</button>`);
      asp.push(camp('Mărime iconițe (px)', numar('icon_size', b.icon_size || 32, 20, 80)));
      asp.push(grupCamp('Aliniere', alinieri('align', b.align || 'center')));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      break;
    }
    case 'divider':
      asp.push(grupCamp('Culoarea liniei', culori('color', b.color || b.line_color, '#e5e7eb')));
      asp.push(glisor('width', b.width || 80, 10, 100, 'Lățime (%)'));
      asp.push(camp('Grosime (px)', numar('thickness', b.thickness || 1, 1, 10)));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      if (inCol) asp.push(bifa('_fara_fundal', String(b.background||'').trim()==='', 'Fără fundal (se vede culoarea coloanei)'));
      cont.push(`<p class="eb-nota">O linie între blocuri. Culoarea și grosimea sunt la Aspect.</p>`);
      break;
    case 'spacer':
      cont.push(glisor('height', b.height || 24, 4, 200, 'Înălțime (px)'));
      asp.push(grupCamp('Fundal', culori('background', b.background, '#ffffff')));
      break;
    default:
      if (eColoane(t)) {
        const cols = b.columns || [];
        colWidths(t).forEach((w, ci) => {
          const lista = cols[ci] || [];
          cont.push(grupCamp(`Coloana ${ci+1} (${w}%)`, `<div class="eb-lista">${lista.map((sb, cbi) => `<div class="eb-lista-rand"><span class="eb-lista-text">${esc(eticheta(sb.type))}</span><button type="button" class="eb-mic" data-alege-sub="${ci}.${cbi}">Schimbă</button></div>`).join('') || '<span class="eb-nota">Goală: trage aici un bloc din stânga.</span>'}</div>
            <div class="eb-rand"><select data-col-tip="${ci}" aria-label="Blocul de adăugat în coloana ${ci+1}">${IN_COLOANA.map(x => `<option value="${x}">${esc(eticheta(x))}</option>`).join('')}</select><button type="button" class="eb-buton-lat" data-col-adauga="${ci}">+ Adaugă</button></div>`));
        });
        asp.push(grupCamp('Fundalul secțiunii', culori('background', b.background, '#ffffff')));
        asp.push(imagineFundal(b));
      } else {
        cont.push('<p class="eb-nota">Bloc necunoscut: rămâne cum e.</p>');
      }
  }
  if (!['spacer'].includes(t)) asp.push(margini(b));
  const afis = [
    bifa('hide_mobile', !!b.hide_mobile, 'Ascunde pe mobil'),
    bifa('hide_tablet', !!b.hide_tablet, 'Ascunde pe tabletă'),
    bifa('hide_desktop', !!b.hide_desktop, 'Ascunde pe desktop'),
    `<p class="eb-nota">Funcționează în majoritatea aplicațiilor de email (Apple Mail, iOS, Outlook). Unele versiuni de Gmail pot ignora ascunderea.</p>`,
  ];
  return {continut: cont, aspect: asp, afisare: afis};
}

function panouSubsol() {
  const f = D.firma || {};
  const lipsa = Array.isArray(f.lipsa) ? f.lipsa : [];
  const rand = (et, v) => `<div class="eb-lista-rand"><span class="eb-lista-text"><small>${esc(et)}</small>${v ? esc(v) : '<span style="color:#b45309">necompletat</span>'}</span></div>`;
  return `<div class="eb-info-mic">Subsolul se pune singur în fiecare newsletter și nu se poate scoate: cine trimite emailul, de ce îl primește omul și linkul personal de dezabonare.</div>`
    + (lipsa.length ? `<div class="eb-avert-mic">Lipsesc: ${esc(lipsa.join(', '))}.</div>` : '')
    + `<div class="eb-lista">${rand('Firma', f.firma)}${rand('Sediul', f.adresa)}${rand('Nr. Reg. Com.', f.regcom)}${rand('CUI', f.cui)}${rand('Politica de confidențialitate', f.confidentialitate)}</div>`
    + (D.poateSetari ? `<a class="eb-buton-lat" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none" href="/admin/settings/store?tab=general#setari-firma">Schimbă datele firmei</a>` : `<p class="eb-nota">Datele firmei le schimbă administratorul general (Setări site → Datele firmei).</p>`);
}

function renderProps() {
  const corp = document.getElementById('eb-props-body');
  const email = document.getElementById('eb-props-email');
  const titlu = document.getElementById('eb-props-titlu');
  const taburi = document.getElementById('eb-taburi');
  if (!corp) return;
  if (sel && sel.subsol) {
    titlu.textContent = 'Subsol (automat)';
    taburi.hidden = true; email.hidden = true; corp.hidden = false;
    corp.innerHTML = panouSubsol();
    corp.removeAttribute('aria-labelledby');
    return;
  }
  const b = blocAles();
  if (!b) {
    titlu.textContent = 'Emailul';
    taburi.hidden = true; email.hidden = false; corp.hidden = true; corp.innerHTML = '';
    return;
  }
  const inCol = sel.ci !== undefined;
  titlu.innerHTML = esc(eticheta(b.type)) + (inCol ? ` <small style="font-weight:400;color:#6b7280;font-size:12px">în coloana ${sel.ci+1}</small><button type="button" data-inapoi-sectiune>← Secțiunea</button>` : '');
  taburi.hidden = false; email.hidden = true; corp.hidden = false;
  const f = campuri(b);
  if (!f[tab] || !f[tab].length) tab = f.continut.length ? 'continut' : 'aspect';
  taburi.querySelectorAll('[role="tab"]').forEach(t => {
    const ales = t.dataset.tab === tab;
    t.setAttribute('aria-selected', ales ? 'true' : 'false');
    t.tabIndex = ales ? 0 : -1;
    t.hidden = !f[t.dataset.tab] || !f[t.dataset.tab].length;
  });
  corp.setAttribute('aria-labelledby', 'eb-tab-' + tab);
  corp.innerHTML = (f[tab] || []).join('') + (!D.poateModifica ? '<p class="eb-nota">Newsletterul nu se mai poate modifica.</p>' : '');
  if (!D.poateModifica) corp.querySelectorAll('input,select,textarea,button').forEach(x => { if (!x.matches('[data-inapoi-sectiune]')) x.disabled = true; });
  legaPanou(corp, b);
}

(function legaTaburi() {
  const taburi = document.getElementById('eb-taburi');
  taburi?.addEventListener('click', e => { const t = e.target.closest('[role="tab"]'); if (!t) return; tab = t.dataset.tab; renderProps(); document.getElementById('eb-tab-' + tab)?.focus(); });
  taburi?.addEventListener('keydown', e => {
    if (!['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) return;
    const vizibile = [...taburi.querySelectorAll('[role="tab"]')].filter(t => !t.hidden);
    let k = vizibile.findIndex(t => t.dataset.tab === tab);
    k = e.key === 'Home' ? 0 : e.key === 'End' ? vizibile.length - 1 : (k + (e.key === 'ArrowRight' ? 1 : -1) + vizibile.length) % vizibile.length;
    e.preventDefault(); tab = vizibile[k].dataset.tab; renderProps(); document.getElementById('eb-tab-' + tab)?.focus();
  });
  document.getElementById('eb-props-titlu')?.addEventListener('click', e => { if (e.target.closest('[data-inapoi-sectiune]') && sel) { selecteaza({i: sel.i}, true); } });
})();

let cautareProduse = null;
let textCautareProduse = '';
function legaPanou(corp, b) {
  corp.querySelectorAll('[data-k]').forEach(el => {
    const ev = el.tagName === 'SELECT' || el.type === 'checkbox' ? 'change' : 'input';
    el.addEventListener(ev, () => {
      const k = el.dataset.k;
      let v = el.value;
      if (el.type === 'number' || el.type === 'range' || ['categorie_id'].includes(k)) v = num(v, 0);
      if (k === 'code') v = String(v).toUpperCase();
      const arata = corp.querySelector(`[data-arata="${k}"]`); if (arata) arata.textContent = String(v);
      if (el.type === 'text' && el.closest('[data-culori]')) {
        if (v !== '' && !/^#[0-9a-fA-F]{3,8}$/.test(v)) return;
        const p = el.closest('[data-culori]').querySelector('input[type=color]'); if (p && /^#[0-9a-fA-F]{6}$/.test(v)) p.value = v;
      }
      const reface = ['sursa'].includes(k) || el.tagName === 'SELECT' && k !== 'font_family' && k !== 'bg_size';
      updateBlock({[k]: v}, !reface);
    });
  });
  corp.querySelectorAll('[data-kc]').forEach(el => el.addEventListener('change', () => {
    if (el.dataset.kc === '_fara_fundal') { updateBlock({background: el.checked ? '' : '#ffffff'}); return; }
    updateBlock({[el.dataset.kc]: el.checked ? 1 : 0}, true);
  }));
  corp.querySelectorAll('[data-seg]').forEach(btn => btn.addEventListener('click', () => {
    const k = btn.dataset.seg;
    let v = btn.dataset.val;
    if (k === 'pe_rand') v = num(v, 3);
    updateBlock({[k]: v});
  }));
  corp.querySelectorAll('[data-culori]').forEach(grup => {
    const k = grup.dataset.culori;
    const text = grup.querySelector('input[type=text]');
    grup.querySelectorAll('[data-culoare]').forEach(btn => btn.addEventListener('click', () => updateBlock({[k]: btn.dataset.culoare})));
    const p = grup.querySelector('input[type=color]');
    p?.addEventListener('input', () => {
      if (text) text.value = p.value;
      if (p._raf) return;
      p._raf = requestAnimationFrame(() => { p._raf = null; updateBlock({[k]: p.value}, true); });
    });
    p?.addEventListener('change', () => updateBlock({[k]: p.value}));
  });
  corp.querySelectorAll('[data-pad]').forEach(el => el.addEventListener('input', () => {
    const v = num(el.value, 0);
    const a = corp.querySelector(`[data-arata="_${el.dataset.pad}"]`); if (a) a.textContent = String(v);
    updateBlock(el.dataset.pad === 'v' ? {padding_top: v, padding_bottom: v} : {padding_left: v, padding_right: v}, true);
  }));
  // Poza: tragerea, alegerea din calculator, galeria.
  corp.querySelectorAll('[data-drop-poza]').forEach(zona => legaZonaPoza(zona, url => updateBlock({[zona.dataset.dropPoza]: url})));
  corp.querySelectorAll('[data-alege-galerie]').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); ebOpenImgModal(null, btn.dataset.alegeGalerie); }));
  corp.querySelector('[data-logo-site]')?.addEventListener('click', () => updateBlock({image_url: ''}));
  // Meniul
  corp.querySelectorAll('[data-item]').forEach(el => el.addEventListener('input', () => {
    const items = JSON.parse(JSON.stringify(b.items || []));
    items[+el.dataset.item][el.dataset.ik] = el.value;
    updateBlock({items}, true);
  }));
  corp.querySelectorAll('[data-item-mut]').forEach(btn => btn.addEventListener('click', () => {
    const items = JSON.parse(JSON.stringify(b.items || []));
    const j = +btn.dataset.itemMut, k = j + (+btn.dataset.dir);
    if (k < 0 || k >= items.length) return;
    [items[j], items[k]] = [items[k], items[j]];
    updateBlock({items});
  }));
  corp.querySelectorAll('[data-item-sterge]').forEach(btn => btn.addEventListener('click', () => updateBlock({items: (b.items || []).filter((_, j) => j !== +btn.dataset.itemSterge)})));
  corp.querySelector('[data-adauga-cat]')?.addEventListener('click', () => {
    const id = num(corp.querySelector('[data-cat-noua]').value, 0);
    const c = (D.categorii || []).find(x => x.id === id);
    if (c) updateBlock({items: [...(b.items || []), {label: c.name, url: c.url, category_id: c.id}]});
  });
  corp.querySelector('[data-adauga-link]')?.addEventListener('click', () => updateBlock({items: [...(b.items || []), {label: 'Link nou', url: '/', category_id: 0}]}));
  // Produsele alese
  corp.querySelectorAll('[data-prod-mut]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(JSON.stringify(b.produse || []));
    const j = +btn.dataset.prodMut, k = j + (+btn.dataset.dir);
    if (k < 0 || k >= p.length) return;
    [p[j], p[k]] = [p[k], p[j]];
    updateBlock({produse: p});
  }));
  corp.querySelectorAll('[data-prod-sterge]').forEach(btn => btn.addEventListener('click', () => updateBlock({produse: (b.produse || []).filter((_, j) => j !== +btn.dataset.prodSterge)})));
  const cauta = corp.querySelector('[data-cauta-produs]');
  const rezultate = corp.querySelector('[data-rezultate-produse]');
  if (cauta && rezultate) {
    const arata = items => {
      const avute = new Set((b.produse || []).map(p => p.id));
      rezultate.innerHTML = items.length ? items.map(p => `<div class="eb-lista-rand">${pozaBuna(p.image_url) ? `<img src="${attr(p.image_url)}" alt="">` : '<img alt="">'}<span class="eb-lista-text">${esc(p.name)}${D.cuMagazin && Number(p.price) > 0 ? `<small>${esc(lei(Number(p.price)))}</small>` : ''}</span><button type="button" class="eb-mic" data-adauga-prod="${p.id}" ${avute.has(p.id) || (b.produse||[]).length >= 12 ? 'disabled' : ''}>${avute.has(p.id) ? 'Ales' : 'Adaugă'}</button></div>`).join('') : '<span class="eb-nota">Niciun produs găsit.</span>';
      rezultate.querySelectorAll('[data-adauga-prod]').forEach(btn => btn.addEventListener('click', () => {
        const p = items.find(x => x.id === +btn.dataset.adaugaProd);
        if (p) updateBlock({produse: [...(b.produse || []), {id:p.id, name:p.name, image_url:p.image_url, url:p.url, price:p.price, old_price:p.old_price}]});
        setTimeout(() => document.querySelector('#eb-props-body [data-cauta-produs]')?.focus(), 0);
      }));
    };
    const cere = () => {
      const q = cauta.value.trim();
      clearTimeout(cautareProduse);
      cautareProduse = setTimeout(() => {
        fetch('/admin/emails/builder/produse?q=' + encodeURIComponent(q), {credentials:'same-origin', headers:{'Accept':'application/json'}})
          .then(r => r.json()).then(d => arata(d && Array.isArray(d.items) ? d.items : [])).catch(() => { rezultate.innerHTML = '<span class="eb-nota">Nu am putut căuta. Încearcă din nou.</span>'; });
      }, 220);
    };
    cauta.value = textCautareProduse;
    cauta.addEventListener('input', () => { textCautareProduse = cauta.value; cere(); });
    cere();
  }
  // Rețelele sociale
  corp.querySelectorAll('[data-social]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => {
    const links = JSON.parse(JSON.stringify(b.links || []));
    links[+el.dataset.social][el.dataset.sk] = el.value;
    updateBlock({links}, el.tagName !== 'SELECT');
  }));
  corp.querySelectorAll('[data-social-sterge]').forEach(btn => btn.addEventListener('click', () => updateBlock({links: (b.links || []).filter((_, j) => j !== +btn.dataset.socialSterge)})));
  corp.querySelector('[data-social-adauga]')?.addEventListener('click', () => updateBlock({links: [...(b.links || []), {platform:'facebook', url:''}]}));
  // Secțiunile
  corp.querySelectorAll('[data-alege-sub]').forEach(btn => btn.addEventListener('click', () => { const [ci, cbi] = btn.dataset.alegeSub.split('.').map(Number); selecteaza({i: sel.i, ci, cbi}, true); }));
  corp.querySelectorAll('[data-col-adauga]').forEach(btn => btn.addEventListener('click', () => {
    const ci = +btn.dataset.colAdauga;
    addInColoana(sel.i, ci, corp.querySelector(`[data-col-tip="${ci}"]`).value);
  }));
}

/* ═══════════════════════════════════════════════════════════
   POZELE: încărcarea în galerie (aceeași rută și verificare ca Galerie)
═══════════════════════════════════════════════════════════ */
const MARIME_MAXIMA = 15 * 1024 * 1024;
async function urcaPoze(fisiere) {
  const bune = [...fisiere].filter(f => /^image\/(jpeg|png|webp|gif)$/.test(f.type));
  if (!bune.length) throw new Error('Doar poze JPG, PNG, WebP sau GIF.');
  const mare = bune.find(f => f.size > MARIME_MAXIMA);
  if (mare) throw new Error('Poza „' + (mare.name || 'din clipboard') + '” e prea mare (cel mult 15 MB).');
  const fd = new FormData();
  fd.append('raspuns', 'json');
  bune.forEach((f, k) => fd.append('image_files[]', f, f.name || ('poza-' + Date.now() + '-' + k + '.' + (f.type.split('/')[1] || 'png'))));
  const r = await fetch('/admin/gallery/bulk-upload', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-CSRF-Token': D.csrf || '', 'Accept':'application/json'}});
  const d = await r.json().catch(() => null);
  if (!r.ok || !d || !d.ok || !Array.isArray(d.imagini) || !d.imagini.length) throw new Error((d && d.mesaj) || 'Poza nu a putut fi încărcată.');
  const grila = document.getElementById('eb-gallery-grid');
  document.getElementById('eb-gallery-gol')?.remove();
  d.imagini.forEach(im => {
    if (!grila) return;
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'eb-gallery-img'; btn.dataset.url = im.url; btn.title = im.title || ''; btn.dataset.searchMatch = '1';
    const img = document.createElement('img'); img.src = im.url; img.alt = im.title || ''; btn.appendChild(img);
    grila.prepend(btn);
  });
  return d.imagini;
}
/** Zona „Trage poza aici”: tragerea unui fișier, alegerea din calculator, Enter / Space. */
function legaZonaPoza(zona, gata) {
  const input = zona.querySelector('input[type=file]');
  const stare = zona.querySelector('.eb-drop-stare');
  const spune = (t, eroare) => { if (stare) { stare.textContent = t; stare.classList.toggle('is-eroare', !!eroare); } };
  const urca = async fisiere => {
    if (!fisiere || !fisiere.length) return;
    spune('Se încarcă…');
    try { const im = await urcaPoze(fisiere); spune('Gata: poza e în galerie.'); gata(im[0].url, im); }
    catch (e) { spune(e.message, true); }
  };
  zona.addEventListener('click', e => { if (e.target.closest('[data-alege-galerie]')) return; if (e.target.closest('[data-alege-fisier]') || e.target === zona || e.target.tagName === 'STRONG') input?.click(); });
  zona.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === zona) { e.preventDefault(); input?.click(); } });
  input?.addEventListener('change', () => { urca(input.files); input.value = ''; });
  zona.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) { e.preventDefault(); zona.classList.add('is-peste'); } });
  zona.addEventListener('dragleave', () => zona.classList.remove('is-peste'));
  zona.addEventListener('drop', e => { e.preventDefault(); zona.classList.remove('is-peste'); urca(e.dataTransfer.files); });
  zona._urca = urca;
}
// Lipirea unei poze (Ctrl+V): în fereastra „Alege imagine”, sau pe un bloc Imagine / Logo ales (în afara câmpurilor de scris).
document.addEventListener('paste', e => {
  const fisiere = [...((e.clipboardData && e.clipboardData.files) || [])].filter(f => /^image\//.test(f.type));
  if (!fisiere.length) return;
  const modal = document.getElementById('eb-img-modal');
  if (modal && modal.style.display !== 'none') { e.preventDefault(); document.getElementById('eb-img-drop')?._urca(fisiere); return; }
  const tinta = document.activeElement;
  if (tinta && (tinta.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(tinta.tagName))) return;
  const b = blocAles();
  if (!b || !['image','logo','video'].includes(b.type) || !D.poateModifica) return;
  e.preventDefault();
  const zona = document.querySelector('#eb-props-body [data-drop-poza]');
  if (zona && zona._urca) zona._urca(fisiere);
});

/* ═══════════════════════════════════════════════════════════
   PALETA: clic (după blocul ales) sau tragere; căutarea
═══════════════════════════════════════════════════════════ */
document.querySelectorAll('.eb-block-btn[data-block-type]').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!D.poateModifica) return;
    const t = btn.dataset.blockType;
    // Cu un bloc dintr-o coloană ales, un bloc potrivit intră în aceeași coloană.
    if (sel && sel.ci !== undefined && IN_COLOANA.includes(t)) { addInColoana(sel.i, sel.ci, t); return; }
    addBlock(t, sel && !sel.subsol ? sel.i + 1 : blocks.length);
  });
  btn.addEventListener('dragstart', e => {
    dragState = {source:'palette', type: btn.dataset.blockType};
    e.dataTransfer.effectAllowed = 'copy';
    try { e.dataTransfer.setData('text/plain', btn.dataset.blockType); } catch (err) {}
  });
  btn.addEventListener('dragend', () => { dragState = null; });
});
document.getElementById('eb-cauta-bloc')?.addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  document.querySelectorAll('.eb-palette .eb-grup').forEach(g => {
    let vizibile = 0;
    g.querySelectorAll('.eb-block-btn').forEach(b => {
      const text = (b.dataset.cauta || '').normalize('NFD').replace(/[̀-ͯ]/g, '');
      const da = !q || text.includes(q);
      b.hidden = !da; if (da) vizibile++;
    });
    g.hidden = vizibile === 0;
    if (q && g.tagName === 'DETAILS' && vizibile) g.open = true;
  });
});

/* ═══════════════════════════════════════════════════════════
   EDITEZ / DESKTOP / MOBIL
═══════════════════════════════════════════════════════════ */
document.querySelectorAll('.eb-preview-tabs button[data-preview]').forEach(btn => {
  btn.addEventListener('click', () => {
    inchideEditarea(true);
    previewMode = btn.dataset.preview;
    document.querySelectorAll('.eb-preview-tabs button').forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
    applyPreviewMode();
  });
});

function applyPreviewMode() {
  const canvasScroll  = document.getElementById('eb-canvas-scroll');
  const previewPane   = document.getElementById('eb-preview-pane');
  if(previewMode === 'edit') {
    if(canvasScroll) canvasScroll.hidden = false;
    if(previewPane)  previewPane.hidden = true;
    ebPreviewCerere++;
  } else {
    if(canvasScroll) canvasScroll.hidden = true;
    if(previewPane)  previewPane.hidden = false;
    // Desktop = 680 px, lățimea emailului (.nl-card): sub 641 px emailul își pune singur mărimile de telefon (media query).
    loadPreview(previewMode === 'mobile' ? 375 : 680);
  }
}

/* Previzualizările sunt izolate (sandbox="allow-scripts", fără allow-same-origin: cadrul are o origine opacă; N1a,
   docs/SECURITATE.md), deci pagina nu le mai poate citi documentul. Înălțimea o trimite cadrul, printr-un mesaj (scriptul
   de mai jos, pus la sfârșitul emailului); pagina primește doar mesajele propriilor cadre.
   Înălțimea e a conținutului (body), nu documentElement.scrollHeight: acela e cel puțin înălțimea cadrului, deci fiecare
   previzualizare adăuga 10 px și cadrul nu se mai micșora. Se trimite la fiecare schimbare de mărime (ResizeObserver): la
   prima așezare (un cadru încărcat înainte să fie așezat trimitea 0), după imagini și la altă lățime. */
const EB_SCRIPT_INALTIME = '<script>(function(){var o=' + JSON.stringify(window.location.origin) + ',u=0;'
  + 'function t(){var b=document.body;if(!b)return;var s=getComputedStyle(b);'
  + 'var h=Math.ceil(Math.max(b.scrollHeight,b.getBoundingClientRect().height)+(parseFloat(s.marginTop)||0)+(parseFloat(s.marginBottom)||0));'
  + 'if(h>0&&h!==u){u=h;parent.postMessage({ax:"eb-inaltime",h:h},o);}}'
  + 'if(window.ResizeObserver&&document.body){new ResizeObserver(t).observe(document.body);}'
  + 'window.addEventListener("load",t);window.addEventListener("resize",t);t();})();<\/script>';
window.addEventListener('message', (e) => {
  if (e.origin !== 'null' || !e.data || e.data.ax !== 'eb-inaltime') return;
  const cadru = document.getElementById('eb-preview-iframe');
  const h = Number(e.data.h);
  if (cadru && e.source === cadru.contentWindow && Number.isFinite(h) && h > 0 && h < 200000) cadru.style.height = h + 'px';
});

/* Fiecare previzualizare primește un cadru NOU, pus în pagină vizibil, și abia apoi emailul (srcdoc). Înainte, același
   cadru izolat era ascuns (display:none) la fiecare clic și reîncărcat cât era ascuns: prima previzualizare de după
   încărcarea paginii mergea, următoarele (Mobil, din nou Desktop) puteau rămâne un chenar alb gol, până la o reîncărcare a
   paginii („Salvează”). Un cadru nou nu păstrează nimic de la previzualizarea de dinainte (nici înălțimea). */
function ebCadruPrevizualizare(vechi, stil) {
  const cadru = document.createElement('iframe');
  cadru.id = vechi.id;
  cadru.title = vechi.title;
  cadru.setAttribute('sandbox', 'allow-scripts');
  cadru.style.cssText = stil;
  vechi.replaceWith(cadru);
  return cadru;
}

/* Răspunsul previzualizării: JSON-ul cu emailul sau un mesaj de spus omului (sesiune expirată, refuz, eroare). */
function ebCerePrevizualizare() {
  const subject = document.getElementById('eb-subject-input')?.value || '';
  return fetch('/admin/emails/builder/preview', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded', 'Accept':'application/json'},
    credentials:'same-origin',
    body: 'blocks_json='+encodeURIComponent(JSON.stringify(blocks))+'&subject='+encodeURIComponent(subject),
  })
  .then(r => r.json().catch(() => null).then(d => {
    if (r.ok && d && d.ok && typeof d.html === 'string') return d.html;
    throw new Error((d && (d.message || d.error)) || (r.redirected || r.status === 401 || r.status === 403
      ? 'Sesiunea a expirat sau nu ai acces. Reîncarcă pagina și încearcă din nou.'
      : 'Nu am putut genera previzualizarea. Încearcă din nou.'));
  }));
}

// Doar ultimul clic contează: un răspuns venit după alt clic (Desktop → Mobil repede) sau după „Editez” nu se mai pune.
let ebPreviewCerere = 0;
function loadPreview(width) {
  const nr = ++ebPreviewCerere;
  const loading = document.getElementById('eb-preview-loading');
  const iframe  = document.getElementById('eb-preview-iframe');
  const frame   = document.getElementById('eb-preview-frame');

  if(loading) { loading.textContent = 'Se generează previzualizarea...'; loading.style.display = ''; }
  if(iframe)  iframe.style.display  = 'none';
  if(frame) {
    frame.style.width = (width + 2) + 'px';
    frame.classList.toggle('mobile-preview', width <= 400);
  }

  ebCerePrevizualizare()
  .then(html => {
    if(nr !== ebPreviewCerere || previewMode === 'edit') return;
    const vechi = document.getElementById('eb-preview-iframe');
    if(!vechi) return;
    if(loading) loading.style.display = 'none';
    ebCadruPrevizualizare(vechi, `width:${width}px;height:600px;`).srcdoc = html + EB_SCRIPT_INALTIME;
  })
  .catch(err => {
    if(nr !== ebPreviewCerere || !loading) return;
    loading.textContent = err && err.message ? err.message : 'Eroare la generarea previzualizării.';
    loading.style.display = '';
  });
}

/* ═══════════════════════════════════════════════════════════
   ȘABLOANELE DE START
═══════════════════════════════════════════════════════════ */
window.ebShowStarterModal = function() {
  document.getElementById('eb-starter-modal').style.display='flex';
  setTimeout(() => document.querySelector('#eb-starter-modal .eb-modal-close')?.focus(), 0);
};
window.ebCloseStarterModal = function() {
  document.getElementById('eb-starter-modal').style.display='none';
};
window.ebApplyStarter = function(preset) {
  const starters = {
    blank: [
      {type:'header', content:'Titlul email-ului', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
    ],
    welcome: [
      {type:'header', content:'Bun venit! 👋', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Îți mulțumim că te-ai abonat la newsletter-ul nostru. Suntem bucuroși să te avem alături!', align:'center', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'spacer', height:8, background:'#ffffff'},
      {type:'button', label:'Descoperă produsele noastre', url:'', align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    promo: [
      {type:'header', content:'Ofertă specială 🎁', align:'center', font_size:34, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'image', image_url:'', alt:'Banner promoție', align:'center', background:'#ffffff', width:100},
      {type:'spacer', height:12, background:'#ffffff'},
      {type:'text', content:'Nu rata această ofertă exclusivă! Comandă acum și beneficiezi de reducere.', align:'center', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'spacer', height:8, background:'#ffffff'},
      {type:'button', label:'Cumpără acum', url:'', align:'center', background:'#ffffff', button_color:'#dc2626', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    newsletter: [
      {type:'header', content:'Newsletter lunar', align:'center', font_size:28, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Salutare!\n\nIată cele mai importante noutăți ale lunii...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'text', content:'Titlu articol\n\nDescriere scurtă a articolului sau produsului. Click pentru a afla mai multe.', align:'left', font_size:15, font_size_mobile:13, background:'#f9fafb', text_color:'#374151'},
      {type:'button', label:'Citește mai mult', url:'', align:'left', background:'#f9fafb', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:5},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    order: [
      {type:'header', content:'Confirmare comandă', align:'center', font_size:28, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Dragă {{customer_name}},\n\nComanda ta #{{order_number}} a fost primită cu succes. Mulțumim!', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:100, thickness:1},
      {type:'text', content:'{{order_items_html}}', align:'left', font_size:14, font_size_mobile:13, background:'#f9fafb', text_color:'#374151'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:100, thickness:1},
      {type:'text', content:'Total: {{order_total}}', align:'right', font_size:16, font_size_mobile:15, background:'#ffffff', text_color:'#111827'},
      {type:'spacer', height:12, background:'#ffffff'},
      {type:'button', label:'Vezi comanda', url:'{{order_action_url}}', align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    simple: [
      {type:'header', content:'Titlu email', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Scrie conținutul tău principal aici.', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'text', content:'Informații suplimentare sau footer text.', align:'center', font_size:13, font_size_mobile:12, background:'#f9fafb', text_color:'#6b7280'},
      {type:'spacer', height:16, background:'#f9fafb'},
    ],
  };
  // Șabloanele magazinului vin de la server (NewsletterSabloane), cu produse reale.
  const sabloaneMagazin = D.sabloane || {};
  const tpl = preset.startsWith('magazin:') ? sabloaneMagazin[preset.slice(8)] : starters[preset];
  if(!tpl) return;
  // Un șablon înlocuiește tot conținutul: întrebăm dacă e deja ceva scris (se poate și reveni cu Anulează).
  if(blocks.length > 0 && dirty && !confirm('Șablonul înlocuiește conținutul de acum. Continui? (Poți reveni cu „Anulează”.)')) return;
  inchideEditarea(false);
  pushHistory();
  blocks = JSON.parse(JSON.stringify(tpl));
  sel = null;
  ebCloseStarterModal();
  render(); sync(); markDirty();
};

/* ═══════════════════════════════════════════════════════════
   VIDEO
═══════════════════════════════════════════════════════════ */
/**
 * Ia titlul și coperta unui link video de la TikTok / YouTube / Vimeo.
 *
 * Serverul întreabă furnizorul și descarcă imaginea la noi: adresele de
 * copertă ale TikTok expiră și sunt blocate de pe alte site-uri, deci una
 * legată direct ar arăta ruptă în inbox peste câteva zile.
 */
window.ebVideoLookup = async function() {
  const b = blocAles();
  if(!b || b.type !== 'video') return;
  const stare = document.getElementById('eb-video-status');
  const spune = (text, eroare) => {
    if(!stare) return;
    stare.textContent = text;
    stare.style.color = eroare ? '#dc2626' : '#6b7280';
  };
  const url = String(b.video_url||'').trim();
  if(url === '') { spune('Lipește întâi linkul videoclipului.', true); return; }
  spune('Caut videoclipul…', false);
  try {
    const date = new FormData();
    date.append('url', url);
    const raspuns = await fetch('/admin/emails/builder/video-lookup', {method:'POST', body:date, credentials:'same-origin'});
    const d = await raspuns.json();
    if(!d || d.ok !== true) { spune(d?.error || 'Nu am putut lua datele videoclipului.', true); return; }
    pushHistory();
    if(d.thumbnail_url) b.thumbnail_url = d.thumbnail_url;
    b.provider = d.provider || '';
    // Titlul și eticheta se completează doar dacă n-a scris deja omul altceva.
    if(!String(b.title||'').trim() && d.title) b.title = d.title;
    if(!String(b.label||'').trim() || b.label === '▶ Vezi videoclipul') {
      b.label = d.provider_label ? ('▶ Vezi pe ' + d.provider_label) : '▶ Vezi videoclipul';
    }
    render(); sync(); markDirty();
    spune(
      d.thumbnail_url
        ? (d.thumbnail_local ? 'Gata: coperta e salvată la noi.' : 'Gata, dar coperta a rămas la furnizor — pune una din galerie dacă vrei să fie sigură.')
        : 'Link recunoscut, dar fără copertă. Alege una din galerie.',
      false
    );
  } catch (e) {
    spune('Nu am putut contacta serverul. Încearcă din nou.', true);
  }
};

/* ═══════════════════════════════════════════════════════════
   ALEGE IMAGINE (galeria, adresa, încărcarea)
═══════════════════════════════════════════════════════════ */
let imgPickKey = 'image_url';
let imgPickCb = null;
let imgPickFocus = null;
window.ebOpenImgModal = function(_blockIndex, propKey, callback) {
  imgPickKey = propKey || 'image_url';
  imgPickCb = callback || null;
  imgPickFocus = document.activeElement;
  const b = blocAles();
  const inp = document.getElementById('eb-img-url-input');
  if(inp) inp.value = (b && b[imgPickKey]) || '';
  const srch = document.getElementById('eb-gallery-search');
  if(srch) srch.value = '';
  document.getElementById('eb-gallery-grid')?.querySelectorAll('.eb-gallery-img').forEach(el=>{ el.dataset.searchMatch='1'; el.classList.toggle('is-ales', !!inp && el.dataset.url === inp.value); });
  const stare = document.getElementById('eb-img-drop-stare'); if (stare) { stare.textContent = ''; stare.classList.remove('is-eroare'); }
  document.getElementById('eb-img-modal').style.display='flex';
  ebGalleryPage(0);
  setTimeout(() => document.getElementById('eb-img-drop')?.focus(), 0);
};
const GALLERY_PAGE_SIZE = 12;
let galleryPage = 0;
window.ebGalleryPage = function(page) {
  const grid = document.getElementById('eb-gallery-grid');
  if(!grid) return;
  const allImgs = [...grid.querySelectorAll('.eb-gallery-img')];
  const imgs = allImgs.filter(el => el.dataset.searchMatch !== '0');
  const total = imgs.length;
  const pages = Math.ceil(total / GALLERY_PAGE_SIZE) || 1;
  galleryPage = Math.max(0, Math.min(page, pages-1));
  allImgs.forEach(el => el.style.display = 'none');
  imgs.slice(galleryPage*GALLERY_PAGE_SIZE, (galleryPage+1)*GALLERY_PAGE_SIZE).forEach(el => el.style.display = '');
  const pager = document.getElementById('eb-gallery-pager');
  if(pager) {
    if(total === 0) { pager.style.display='flex'; pager.innerHTML='<span style="color:#6b7280;font-size:12px;">Nicio imagine găsită.</span>'; return; }
    if(pages <= 1) { pager.style.display='none'; return; }
    pager.style.display = 'flex';
    pager.innerHTML = `<button type="button" class="eb-mic" onclick="ebGalleryPage(${galleryPage-1})" ${galleryPage===0?'disabled':''}>← Înapoi</button>
      <span style="font-size:12px;color:#6b7280;align-self:center;">Pagina ${galleryPage+1} / ${pages}</span>
      <button type="button" class="eb-mic" onclick="ebGalleryPage(${galleryPage+1})" ${galleryPage===pages-1?'disabled':''}>Înainte →</button>`;
  }
};
window.ebCloseImgModal = function() {
  document.getElementById('eb-img-modal').style.display='none';
  imgPickCb=null;
  if (imgPickFocus && document.body.contains(imgPickFocus)) imgPickFocus.focus();
};
document.getElementById('eb-gallery-search')?.addEventListener('input', () => {
  const q = (document.getElementById('eb-gallery-search').value||'').toLowerCase();
  document.getElementById('eb-gallery-grid')?.querySelectorAll('.eb-gallery-img').forEach(el => {
    const match = !q || (el.title||el.dataset.url||'').toLowerCase().includes(q);
    el.dataset.searchMatch = match ? '1' : '0';
  });
  ebGalleryPage(0);
});
document.getElementById('eb-gallery-grid')?.addEventListener('click', e => {
  const t = e.target.closest('.eb-gallery-img');
  if (!t) return;
  ebPickGalleryImg(t.dataset.url);
});
document.getElementById('eb-gallery-grid')?.addEventListener('dblclick', e => { if (e.target.closest('.eb-gallery-img')) ebConfirmImgUrl(); });
{
  let imgModalMdInside = false;
  const imgOvl = document.getElementById('eb-img-modal');
  imgOvl?.addEventListener('mousedown', e => { imgModalMdInside = e.target !== imgOvl; });
  imgOvl?.addEventListener('click', e => { if(e.target === imgOvl && !imgModalMdInside) ebCloseImgModal(); });
  imgOvl?.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); ebCloseImgModal(); } });
  const zona = document.getElementById('eb-img-drop');
  if (zona) legaZonaPoza(zona, url => { ebPickGalleryImg(url); ebConfirmImgUrl(); });
}
document.getElementById('eb-starter-modal')?.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); ebCloseStarterModal(); } });
window.ebPickGalleryImg = function(url) {
  const inp = document.getElementById('eb-img-url-input');
  if(inp) inp.value = url;
  document.getElementById('eb-gallery-grid')?.querySelectorAll('.eb-gallery-img').forEach(el => el.classList.toggle('is-ales', el.dataset.url === url));
};
window.ebConfirmImgUrl = function() {
  const url = document.getElementById('eb-img-url-input')?.value?.trim()||'';
  const cb = imgPickCb;
  imgPickCb = null;
  ebCloseImgModal();
  if(cb) { cb(url); }
  else if(blocAles()) updateBlock({[imgPickKey]: url});
};

/* ═══════════════════════════════════════════════════════════
   SETĂRILE EMAILULUI (subiectul, numele, șablonul)
═══════════════════════════════════════════════════════════ */
const setName    = document.getElementById('eb-set-name');
const setSubject = document.getElementById('eb-set-subject');
const setActive  = document.getElementById('eb-set-active');
const setRecip   = document.getElementById('eb-set-recip-mode');
const setAdminR  = document.getElementById('eb-set-admin-recip');

if(setName) setName.addEventListener('input', () => {
  const nameInp = document.getElementById('eb-name-input');
  if(nameInp) nameInp.value = setName.value;
  const titleDisplay = document.getElementById('eb-title-display');
  if(titleDisplay) titleDisplay.textContent = setName.value;
  markDirty();
});
if(setSubject) setSubject.addEventListener('input', () => {
  const subj = document.getElementById('eb-subject-input');
  if(subj) subj.value = setSubject.value;
  renderCanvas();
  markDirty();
});
if(setActive) setActive.addEventListener('change', () => {
  let activeInp = document.getElementById('eb-active-input');
  if(!activeInp) {
    activeInp = document.createElement('input');
    activeInp.type = 'hidden'; activeInp.id = 'eb-active-input'; activeInp.value = '1';
    document.getElementById('eb-form')?.appendChild(activeInp);
  }
  activeInp.value = setActive.checked ? '1' : '0';
  // Serverul citește doar prezența câmpului: debifat, câmpul are alt nume.
  activeInp.name = setActive.checked ? 'builder_is_active' : 'builder_is_active_off';
  markDirty();
});
if(setRecip) setRecip.addEventListener('change', () => {
  const inp = document.getElementById('eb-recip-mode-input');
  if(inp) inp.value = setRecip.value;
  const wrap = document.getElementById('eb-admin-recip-wrap');
  if(wrap) wrap.style.display = ['admin','client_admin'].includes(setRecip.value) ? '' : 'none';
  markDirty();
});
if(setAdminR) setAdminR.addEventListener('input', () => {
  const inp = document.getElementById('eb-recip-admin-input');
  if(inp) inp.value = setAdminR.value;
  markDirty();
});
document.querySelectorAll('.eb-token-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    navigator.clipboard?.writeText(btn.dataset.token).then(() => {
      const orig = btn.textContent;
      btn.textContent = '✓ Copiat!';
      setTimeout(()=>btn.textContent=orig, 1500);
    });
  });
});

/* ═══════════════════════════════════════════════════════════
   SALVAREA
═══════════════════════════════════════════════════════════ */
function salveaza() {
  inchideEditarea(true);
  sync();
  if (D.tip === 'campaign' || D.tip === 'template') { salveazaAjax(false); return; }
  dirty = false; // formularul pleacă: pagina se reîncarcă
  document.getElementById('eb-form')?.submit();
}
document.getElementById('btn-save')?.addEventListener('click', salveaza);
window.addEventListener('beforeunload', e => {
  if(dirty) { e.preventDefault(); e.returnValue=''; }
});

/* ═══════════════════════════════════════════════════════════
   TASTELE
═══════════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  const activ = document.activeElement;
  const inCamp = activ && (activ.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(activ.tagName));
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s') { e.preventDefault(); salveaza(); return; }
  if (inCamp) return;
  if (document.getElementById('eb-img-modal')?.style.display === 'flex' || document.getElementById('eb-starter-modal')?.style.display === 'flex') return;
  if((e.ctrlKey||e.metaKey) && !e.shiftKey && e.key.toLowerCase()==='z') { e.preventDefault(); undo(); }
  if((e.ctrlKey||e.metaKey) && (e.key.toLowerCase()==='y' || (e.shiftKey && e.key.toLowerCase()==='z'))) { e.preventDefault(); redo(); }
  if((e.key==='Delete'||e.key==='Backspace') && sel && !sel.subsol && activ && activ.closest && activ.closest('#eb-canvas') && !activ.closest('button')) {
    e.preventDefault(); removeSel();
  }
  if (e.key === 'Escape' && sel && activ && activ.closest && activ.closest('#eb-canvas')) { selecteaza(null, false); }
});
document.getElementById('btn-undo')?.addEventListener('click', undo);
document.getElementById('btn-redo')?.addEventListener('click', redo);
document.getElementById('eb-back-link')?.addEventListener('click', e => {
  if(dirty && !confirm('Ai modificări nesalvate. Ești sigur că vrei să pleci?')) e.preventDefault();
  else dirty = false;
});

/* ═══════════════════════════════════════════════════════════
   PAȘII NEWSLETTERULUI (Destinatari, Verificare, Trimite)
   Logica e în /assets/js/admin/newsletter-pasi.js; aici doar ce
   ține de starea builderului (blocurile, salvarea, „modificat”).
═══════════════════════════════════════════════════════════ */
window.ebBuilder = {
  // Textul scris pe pânză e deja în blocuri (fiecare literă îl scrie), deci nu trebuie oprit scrisul.
  blocuri: () => JSON.parse(JSON.stringify(blocks)),
  sincronizeaza: () => sync(),
  esteModificat: () => dirty,
  marcheazaSalvat: () => clearDirty(),
  marcheazaModificat: () => markDirty(),
};

/* ═══════════════════════════════════════════════════════════
   PORNIREA
═══════════════════════════════════════════════════════════ */
(function ebFitHeight() {
  const el = document.getElementById('eb-wrap');
  if (!el) return;
  // Nu blocăm derularea paginii (meniul adminului s-ar fi oprit); panourile se derulează singure (overscroll-behavior).
  const fit = () => { el.style.height = (window.innerHeight - (el.getBoundingClientRect().top + window.scrollY)) + 'px'; };
  fit();
  window.addEventListener('resize', fit);
})();
// Pe un ecran sub 1600 px, meniul adminului se strânge (emailul de 640 px și cele două panouri încap la 1366 px), dacă omul
// nu l-a deschis anume (butonul ☰ îl arată la loc și ține minte alegerea).
(function strangeMeniul() {
  try {
    const app = document.getElementById('admin-shell');
    const buton = document.getElementById('sidebar-toggle-btn');
    if (!app || window.innerWidth >= 1600 || window.innerWidth <= 900) return;
    if (window.localStorage.getItem('admin_sidebar_hidden') === '0') return;
    app.classList.add('sidebar-hidden');
    buton?.setAttribute('aria-expanded', 'false');
  } catch (e) { /* fără memorie: meniul rămâne cum e */ }
})();

render();
sync();
stareSalvare('salvat', D.salvareAutomata ? 'Salvat (se salvează singur)' : 'Salvat');
if (D.arataStart) setTimeout(() => ebShowStarterModal(), 300);

})(); // end IIFE
</script>
