<?php

declare(strict_types=1);

/**
 * WP-21 (C11c, C11d; din Biovitality b9fcaf8, 84e29a9): ce schimbă constructorul de emailuri (views/admin/emails/builder.php)
 * față de codul Bioscem, ca texte declarate pentru tools/dev/compara-cu-baza.php ($porturiBiovitality, pagina
 * /admin/emails/builder, un template nou): blocul „Video / TikTok” (paleta, tipul, valorile implicite, previzualizarea,
 * panoul, căutarea copertei) și fundalul blocurilor din coloane („Fără fundal”, alinierea textului). Fiecare intrare:
 * „nou” = rândurile scrise acum (cu un rând de context neschimbat înainte și după, ca textul să fie unic în pagină),
 * „vechi” = rândurile codului Bioscem în locul lor. Scrise din diferența dintre cele două variante ale fișierului.
 *
 * @return list<array{nou: string, vechi: string}>
 */
return [
    [
        // Paleta: butonul blocului nou (scris de bucla $palBlocks).
        'nou' => <<<'TXT'
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="video">
          <span class="eb-block-icon">▶</span>Video / TikTok        </button>
        
TXT,
        'vechi' => '',
    ],
    [
        'nou' => <<<'TXT'
  image:          {label:'Imagine',     icon:'🖼'},
  video:          {label:'Video',       icon:'▶'},
  button:         {label:'Buton',       icon:'◉'},
  quote:          {label:'Citat',       icon:'❝'},
  social:         {label:'Social',      icon:'◎'},
  divider:        {label:'Separator',   icon:'—'},
  spacer:         {label:'Spațiu',      icon:'↕'},
  columns_2:      {label:'50% / 50%',   icon:'▌▌'},
  columns_2_6040: {label:'60% / 40%',   icon:'▐▌'},
  columns_2_4060: {label:'40% / 60%',   icon:'▌▐'},
  columns_2_7030: {label:'70% / 30%',   icon:'▐▏'},
  columns_2_3070: {label:'30% / 70%',   icon:'▏▐'},
  columns_3:      {label:'33%×3',       icon:'▌▌▌'},
};
// Returns column widths (%) for a given type
const colWidths = type => {
  if(type==='columns_3')      return [33,34,33];
  if(type==='columns_2_6040') return [60,40];
  if(type==='columns_2_4060') return [40,60];
  if(type==='columns_2_7030') return [70,30];
  if(type==='columns_2_3070') return [30,70];
  return [50,50];
};
const isColumns2 = type => type==='columns_2'||type==='columns_2_6040'||type==='columns_2_4060'||type==='columns_2_7030'||type==='columns_2_3070';

function blockDefault(type) {
  const d = {
    header:    {type:'header',    content:'Titlul tău',            align:'center', font_size:32, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff'},
    text:      {type:'text',      content:'Scrie textul tău aici...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
    image:     {type:'image',     image_url:'',                    alt:'',         align:'center', background:'#ffffff', width:100, link_url:''},
    // Clienții de email nu redau video, deci blocul e o copertă pe care se dă
    // clic plus un buton — nu un player.
    video:     {type:'video',     video_url:'', thumbnail_url:'', title:'', provider:'', label:'▶ Vezi videoclipul',
                align:'center', background:'#ffffff', width:100, border_radius:10,
                button_color:'#2d7d5f', text_color:'#ffffff', button_radius:6, title_color:'#1f2937'},
    button:    {type:'button',    label:'Apasă aici',              url:'',         align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
    quote:     {type:'quote',     content:'Scrie citatul tău...',  author:'',      align:'left',   background:'#f0fdf4', text_color:'#166534', border_color:'#2d7d5f', font_size:16},
    social:    {type:'social',    links:[{platform:'facebook',url:''},{platform:'instagram',url:''}], align:'center', background:'#ffffff', icon_size:32},
    divider:   {type:'divider',   background:'#ffffff',            color:'#e5e7eb', width:80, thickness:1},
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
 */
function subBlockDefault(type) {
  const d = blockDefault(type);
  if ('background' in d) { d.background = ''; }
  return d;
}
TXT,
        'vechi' => <<<'TXT'
  image:          {label:'Imagine',     icon:'🖼'},
  button:         {label:'Buton',       icon:'◉'},
  quote:          {label:'Citat',       icon:'❝'},
  social:         {label:'Social',      icon:'◎'},
  divider:        {label:'Separator',   icon:'—'},
  spacer:         {label:'Spațiu',      icon:'↕'},
  columns_2:      {label:'50% / 50%',   icon:'▌▌'},
  columns_2_6040: {label:'60% / 40%',   icon:'▐▌'},
  columns_2_4060: {label:'40% / 60%',   icon:'▌▐'},
  columns_2_7030: {label:'70% / 30%',   icon:'▐▏'},
  columns_2_3070: {label:'30% / 70%',   icon:'▏▐'},
  columns_3:      {label:'33%×3',       icon:'▌▌▌'},
};
// Returns column widths (%) for a given type
const colWidths = type => {
  if(type==='columns_3')      return [33,34,33];
  if(type==='columns_2_6040') return [60,40];
  if(type==='columns_2_4060') return [40,60];
  if(type==='columns_2_7030') return [70,30];
  if(type==='columns_2_3070') return [30,70];
  return [50,50];
};
const isColumns2 = type => type==='columns_2'||type==='columns_2_6040'||type==='columns_2_4060'||type==='columns_2_7030'||type==='columns_2_3070';

function blockDefault(type) {
  const d = {
    header:    {type:'header',    content:'Titlul tău',            align:'center', font_size:32, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff'},
    text:      {type:'text',      content:'Scrie textul tău aici...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
    image:     {type:'image',     image_url:'',                    alt:'',         align:'center', background:'#ffffff', width:100, link_url:''},
    button:    {type:'button',    label:'Apasă aici',              url:'',         align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
    quote:     {type:'quote',     content:'Scrie citatul tău...',  author:'',      align:'left',   background:'#f0fdf4', text_color:'#166534', border_color:'#2d7d5f', font_size:16},
    social:    {type:'social',    links:[{platform:'facebook',url:''},{platform:'instagram',url:''}], align:'center', background:'#ffffff', icon_size:32},
    divider:   {type:'divider',   background:'#ffffff',            color:'#e5e7eb', width:80, thickness:1},
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
TXT,
    ],
    [
        'nou' => <<<'TXT'
      while(newCols.length<cnt) newCols.push([]);
      newCols[ci] = [...(newCols[ci]||[]), subBlockDefault(dragState.type)];
      pushHistory();
TXT,
        'vechi' => <<<'TXT'
      while(newCols.length<cnt) newCols.push([]);
      newCols[ci] = [...(newCols[ci]||[]), blockDefault(dragState.type)];
      pushHistory();
TXT,
    ],
    [
        'nou' => <<<'TXT'
function subBlockMiniInner(sb) {
  // Fundal gol = fără fundal: se vede culoarea coloanei de dedesubt.
  const bg  = String(sb.background||'').trim();
  const bgCss = bg ? `background:${esc(bg)};` : '';
  const tc  = sb.text_color||'#333333';
  const ali = sb.align||'left';
  const ff  = ebFontStack(sb.font_family);
  const fontStyle = ff ? `font-family:${ff};` : '';
  switch(sb.type) {
    case 'header': return `<div style="${bgCss}color:${esc(tc)};text-align:${ali};${fontStyle}font-size:${+(sb.font_size||24)}px;font-weight:700;padding:4px 6px;line-height:1.2;">${safeRich((sb.content||'Header').substring(0,50))}</div>`;
    case 'text':   return `<div style="${bgCss}color:${esc(tc)};text-align:${ali};${fontStyle}font-size:${+(sb.font_size||14)}px;padding:4px 6px;line-height:1.4;white-space:pre-wrap;">${safeRich((sb.content||'Text').substring(0,120))}</div>`;
    case 'image':  return sb.image_url ? `<div style="${bgCss}padding:3px;text-align:${sb.align||'center'};"><img src="${attr(sb.image_url)}" alt="" style="width:${+(sb.width||100)}%;max-width:100%;height:auto;border-radius:${+(sb.border_radius||0)}px;display:inline-block;vertical-align:top;"></div>` : `<div style="${bgCss}padding:4px 6px;color:#9ca3af;font-size:10px;border:1px dashed #d1d5db;border-radius:3px;">🖼 Imagine</div>`;
    case 'video':  return sb.thumbnail_url
      ? `<div style="${bgCss}padding:3px;text-align:${sb.align||'center'};position:relative;"><img src="${attr(sb.thumbnail_url)}" alt="" style="width:${+(sb.width||100)}%;max-width:100%;height:auto;border-radius:${+(sb.border_radius||10)}px;display:inline-block;vertical-align:top;"><span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.6);">▶</span></div>`
      : `<div style="${bgCss}padding:4px 6px;color:#9ca3af;font-size:10px;border:1px dashed #d1d5db;border-radius:3px;">▶ Video</div>`;
    case 'button': { const bc=sb.button_color||'#2d7d5f'; return `<div style="${bgCss}padding:4px 6px;text-align:${sb.align||'left'};"><span style="display:inline-block;background:${esc(bc)};color:${esc(tc)};padding:${Math.min(+(sb.btn_pad_v??8),10)}px ${Math.min(+(sb.btn_pad_h??18),16)}px;border-radius:${+(sb.border_radius||6)}px;font-size:${Math.min(+(sb.font_size||14),16)}px;font-weight:600;">${esc(sb.label||'Buton')}</span></div>`; }
    case 'divider':return `<div style="${bgCss}padding:4px 6px;"><hr style="border:none;border-top:${+(sb.thickness||1)}px solid ${esc(sb.color||'#e5e7eb')};margin:0;"></div>`;
    case 'spacer': return `<div style="${bgCss}height:${Math.min(+(sb.height||16),24)}px;"></div>`;
    default:       return `<div style="padding:3px 6px;font-size:10px;color:#6b7280;">${esc(sb.type||'?')}</div>`;
TXT,
        'vechi' => <<<'TXT'
function subBlockMiniInner(sb) {
  const bg  = sb.background||'#ffffff';
  const tc  = sb.text_color||'#333333';
  const ff  = ebFontStack(sb.font_family);
  const fontStyle = ff ? `font-family:${ff};` : '';
  switch(sb.type) {
    case 'header': return `<div style="background:${esc(bg)};color:${esc(tc)};${fontStyle}font-size:${+(sb.font_size||24)}px;font-weight:700;padding:4px 6px;line-height:1.2;">${safeRich((sb.content||'Header').substring(0,50))}</div>`;
    case 'text':   return `<div style="background:${esc(bg)};color:${esc(tc)};${fontStyle}font-size:${+(sb.font_size||14)}px;padding:4px 6px;line-height:1.4;white-space:pre-wrap;">${safeRich((sb.content||'Text').substring(0,120))}</div>`;
    case 'image':  return sb.image_url ? `<div style="background:${esc(bg)};padding:3px;text-align:${sb.align||'center'};"><img src="${attr(sb.image_url)}" alt="" style="width:${+(sb.width||100)}%;max-width:100%;height:auto;border-radius:${+(sb.border_radius||0)}px;display:inline-block;vertical-align:top;"></div>` : `<div style="background:${esc(bg)};padding:4px 6px;color:#9ca3af;font-size:10px;border:1px dashed #d1d5db;border-radius:3px;">🖼 Imagine</div>`;
    case 'button': { const bc=sb.button_color||'#2d7d5f'; return `<div style="background:${esc(bg)};padding:4px 6px;text-align:${sb.align||'left'};"><span style="display:inline-block;background:${esc(bc)};color:${esc(tc)};padding:${Math.min(+(sb.btn_pad_v??8),10)}px ${Math.min(+(sb.btn_pad_h??18),16)}px;border-radius:${+(sb.border_radius||6)}px;font-size:${Math.min(+(sb.font_size||14),16)}px;font-weight:600;">${esc(sb.label||'Buton')}</span></div>`; }
    case 'divider':return `<div style="background:${esc(bg)};padding:4px 6px;"><hr style="border:none;border-top:${+(sb.thickness||1)}px solid ${esc(sb.color||'#e5e7eb')};margin:0;"></div>`;
    case 'spacer': return `<div style="background:${esc(bg)};height:${Math.min(+(sb.height||16),24)}px;"></div>`;
    default:       return `<div style="padding:3px 6px;font-size:10px;color:#6b7280;">${esc(sb.type||'?')}</div>`;
TXT,
    ],
    [
        'nou' => <<<'TXT'
        : `<div style="background:${esc(bg)}${bgImg};padding:12px 16px;text-align:center;color:#9ca3af;border:2px dashed #d1d5db;border-radius:4px;">🖼 Nicio imagine selectată</div>`;
    case 'video': {
      if (!b.thumbnail_url && !b.video_url) {
        return `<div style="background:${esc(bg)}${bgImg};padding:12px 16px;text-align:center;color:#9ca3af;border:2px dashed #d1d5db;border-radius:4px;">▶ Lipește un link TikTok, YouTube sau Vimeo</div>`;
      }
      const vbc = b.button_color||'#2d7d5f';
      const vtc = b.text_color||'#ffffff';
      // Butonul de play e desenat peste copertă doar aici, în editor. În
      // emailul trimis rămâne coperta plus butonul de dedesubt: clienții de
      // email nu așază nimic peste imagini.
      const media = b.thumbnail_url
        ? `<span style="position:relative;display:inline-block;max-width:100%;"><img src="${attr(b.thumbnail_url)}" alt="${attr(b.title||'')}" style="width:${+(b.width||100)}%;max-width:100%;height:auto;border-radius:${+(b.border_radius||10)}px;display:block;"><span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:54px;height:54px;border-radius:999px;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;">▶</span></span>`
        : '';
      const titluVideo = b.title ? `<div style="margin-top:10px;${fontStyle}font-size:15px;font-weight:600;color:${esc(b.title_color||'#1f2937')};">${esc(b.title)}</div>` : '';
      const butonVideo = `<div style="margin-top:12px;"><span style="display:inline-block;background:${esc(vbc)};color:${esc(vtc)};padding:11px 22px;border-radius:${+(b.button_radius||6)}px;font-weight:700;font-size:15px;">${esc(b.label||'▶ Vezi videoclipul')}</span></div>`;
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;text-align:${ali};">${media}${titluVideo}${butonVideo}</div>`;
    }
    case 'button': {
TXT,
        'vechi' => <<<'TXT'
        : `<div style="background:${esc(bg)}${bgImg};padding:12px 16px;text-align:center;color:#9ca3af;border:2px dashed #d1d5db;border-radius:4px;">🖼 Nicio imagine selectată</div>`;
    case 'button': {
TXT,
    ],
    [
        'nou' => <<<'TXT'
  const numInput  = (key, val, min=0, max=999, step=1) => `<input type="number" data-key="${key}" value="${+val||0}" min="${min}" max="${max}" step="${step}">`;
  const urlInput  = (key, val, ph='https://...') => `<input type="url" data-key="${key}" value="${attr(val)}" placeholder="${attr(ph)}">`;
  const sep       = () => `<hr class="eb-props-sep">`;
TXT,
        'vechi' => <<<'TXT'
  const numInput  = (key, val, min=0, max=999, step=1) => `<input type="number" data-key="${key}" value="${+val||0}" min="${min}" max="${max}" step="${step}">`;
  const urlInput  = (key, val) => `<input type="url" data-key="${key}" value="${attr(val)}" placeholder="https://...">`;
  const sep       = () => `<hr class="eb-props-sep">`;
TXT,
    ],
    [
        'nou' => <<<'TXT'
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;

    case 'video':
      rows.push(group('Link video', `<div class="eb-image-row">${textInput('video_url', b.video_url||'', 'https://www.tiktok.com/@cont/video/...')}<button type="button" class="btn" onclick="ebVideoLookup(${i})" title="Ia titlul și coperta de la TikTok / YouTube / Vimeo">⤓</button></div>`));
      rows.push(group('', `<small class="muted" id="eb-video-status">TikTok, YouTube, Vimeo sau un fișier .mp4. Apasă ⤓ ca să aduc singur coperta și titlul.</small>`));
      rows.push(group('Copertă', `<div class="eb-image-row">${textInput('thumbnail_url', b.thumbnail_url||'', '/uploads/...')}<button type="button" class="btn btn-secondary" onclick="ebOpenImgModal(${i}, 'thumbnail_url')">📁</button></div>`));
      rows.push(group('Titlu sub video (opțional)', textInput('title', b.title||'', 'ex: Cum se folosește produsul')));
      rows.push(group('Text buton', textInput('label', b.label||'▶ Vezi videoclipul')));
      rows.push(group('Lățime copertă (%)', `${numInput('width', b.width||100, 20, 100)} <input type="range" data-key="width" value="${+(b.width||100)}" min="20" max="100">`));
      rows.push(group('Rotunjire copertă (px)', `${numInput('border_radius', b.border_radius||10, 0, 80)} <input type="range" data-key="border_radius" value="${+(b.border_radius||10)}" min="0" max="80">`));
      rows.push(group('Aliniere', alignRow('align', b.align||'center', b)));
      rows.push(sep());
      rows.push(group('Culoare buton', colorRow('button_color', b.button_color||'#2d7d5f')));
      rows.push(group('Culoare text buton', colorRow('text_color', b.text_color||'#ffffff')));
      rows.push(group('Rotunjire buton (px)', numInput('button_radius', b.button_radius||6, 0, 50)));
      rows.push(group('Culoare titlu', colorRow('title_color', b.title_color||'#1f2937')));
      rows.push(group('Fundal', colorRow('background', b.background||'#ffffff')));
      rows.push(sep());
      rows.push(group('', `<small class="muted">În email se trimite coperta cu un buton dedesubt, nu un player: Gmail, Outlook și Apple Mail nu redau video în mesaj. Clicul duce la videoclip.</small>`));
      break;
TXT,
        'vechi' => <<<'TXT'
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;
TXT,
    ],
    [
        'nou' => <<<'TXT'
  ];
  // Aliniere: aceleași butoane pentru orice fel de bloc din coloană (WP-21, C11c, din Biovitality b9fcaf8).
  const subAlignRow = (val, implicit) => {
    const activ = val || implicit;
    const buton = (cheie, eticheta, sageata) =>
      `<button type="button" class="eb-rich-btn" data-subalign="${cheie}" style="${activ===cheie?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="${eticheta}"><span>${eticheta}</span><span>${sageata}</span></button>`;
    return `<div class="eb-align-row" style="margin-top:2px;">${buton('left','Stânga','\u21a4')}${buton('center','Centru','\u2194')}${buton('right','Dreapta','\u21a6')}</div>`;
  };
  // Fundal gol = fără fundal: se vede culoarea coloanei. Altfel fiecare casetă
  // își punea albul ei peste culoarea aleasă la coloană.
  const subBgRow = (val) => {
    const gol = String(val||'').trim()==='';
    return `<div style="display:flex;align-items:center;gap:6px;">
        <input type="color" data-subbgcolor value="${a2(gol?'#ffffff':val)}" style="width:34px;height:26px;padding:0;border:1px solid #d1d5db;border-radius:4px;background:none;cursor:pointer;${gol?'opacity:.4;':''}">
        ${ti('background', gol?'':val, 'fără fundal')}
      </div>
      <label style="display:flex;align-items:center;gap:5px;margin-top:4px;font-size:10.5px;color:#374151;cursor:pointer;">
        <input type="checkbox" data-subbgnone ${gol?'checked':''}> Fără fundal (se vede culoarea coloanei)
      </label>`;
  };
  const subFontSelect = (val) => `<select data-subkey="font_family" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;">${SB_FONTS.map(([k,l])=>`<option value="${k}" ${(val||'')===k?'selected':''}>${l}</option>`).join('')}</select>`;
  if(sb.type==='text'||sb.type==='header') {
    h += g('Conținut', subRichBar + `<textarea data-subkey="content" rows="3" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;resize:vertical;">${e2(sb.content||'')}</textarea>`);
    h += g('Font', subFontSelect(sb.font_family||''));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||(sb.type==='header'?24:14), 8, 72));
    h += g('Culoare text', ti('text_color', sb.text_color||'#333333', '#333333'));
    h += g('Aliniere', subAlignRow(sb.align, sb.type==='header' ? 'center' : 'left'));
    h += g('Fundal', subBgRow(sb.background));
  } else if(sb.type==='image') {
    h += g('URL imagine', `<div class="eb-image-row">${ti('image_url', sb.image_url||'', 'https://...')}<button type="button" class="btn btn-secondary" data-subimg-pick style="padding:4px 7px;font-size:11px;">📁</button></div>`);
    const sw = +(sb.width||100);
    h += g('Lățime (%)', `<div style="display:flex;align-items:center;gap:6px;"><input type="range" data-subkey="width" value="${sw}" min="10" max="100" style="flex:1;"><span data-subwidth-label style="font-size:11px;color:#6b7280;min-width:28px;text-align:right;">${sw}%</span></div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||0, 0, 80));
    h += g('Fundal', subBgRow(sb.background));
    h += g('Link (opțional)', ti('link_url', sb.link_url||'', 'https://...'));
    h += g('Aliniere', subAlignRow(sb.align, 'center'));
  } else if(sb.type==='button') {
    h += g('Text buton', ti('label', sb.label||'Apasă'));
    h += g('URL', ti('url', sb.url||'', 'https://...'));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||14, 10, 40));
    h += g('Mărime buton (spațiere px)', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↕<input type="number" data-subkey="btn_pad_v" value="${+(sb.btn_pad_v??8)}" min="2" max="40" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↔<input type="number" data-subkey="btn_pad_h" value="${+(sb.btn_pad_h??18)}" min="4" max="80" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      </div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||6, 0, 50));
    h += g('Culoare buton', ti('button_color', sb.button_color||'#2d7d5f', '#2d7d5f'));
    h += g('Culoare text', ti('text_color', sb.text_color||'#ffffff', '#ffffff'));
    h += g('Fundal', subBgRow(sb.background));
  } else if(sb.type==='spacer') {
    h += g('Înălțime (px)', ni('height', sb.height||16, 4, 200));
  } else if(sb.type==='divider') {
    h += g('Culoare linie', ti('color', sb.color||'#e5e7eb', '#e5e7eb'));
    h += g('Grosime (px)', ni('thickness', sb.thickness||1, 1, 10));
    h += g('Fundal', subBgRow(sb.background));
  }
TXT,
        'vechi' => <<<'TXT'
  ];
  const subFontSelect = (val) => `<select data-subkey="font_family" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;">${SB_FONTS.map(([k,l])=>`<option value="${k}" ${(val||'')===k?'selected':''}>${l}</option>`).join('')}</select>`;
  if(sb.type==='text'||sb.type==='header') {
    h += g('Conținut', subRichBar + `<textarea data-subkey="content" rows="3" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;resize:vertical;">${e2(sb.content||'')}</textarea>`);
    h += g('Font', subFontSelect(sb.font_family||''));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||(sb.type==='header'?24:14), 8, 72));
    h += g('Culoare text', ti('text_color', sb.text_color||'#333333', '#333333'));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  } else if(sb.type==='image') {
    h += g('URL imagine', `<div class="eb-image-row">${ti('image_url', sb.image_url||'', 'https://...')}<button type="button" class="btn btn-secondary" data-subimg-pick style="padding:4px 7px;font-size:11px;">📁</button></div>`);
    const sw = +(sb.width||100);
    h += g('Lățime (%)', `<div style="display:flex;align-items:center;gap:6px;"><input type="range" data-subkey="width" value="${sw}" min="10" max="100" style="flex:1;"><span data-subwidth-label style="font-size:11px;color:#6b7280;min-width:28px;text-align:right;">${sw}%</span></div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||0, 0, 80));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
    h += g('Link (opțional)', ti('link_url', sb.link_url||'', 'https://...'));
    h += g('Aliniere', `<div class="eb-align-row" style="margin-top:2px;">
      <button type="button" class="eb-rich-btn" data-subalign="left"  style="${(sb.align||'center')==='left'  ?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Stânga"><span>Stânga</span><span>↤</span></button>
      <button type="button" class="eb-rich-btn" data-subalign="center" style="${(sb.align||'center')==='center'?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Centru"><span>Centru</span><span>↔</span></button>
      <button type="button" class="eb-rich-btn" data-subalign="right" style="${(sb.align||'center')==='right' ?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Dreapta"><span>Dreapta</span><span>↦</span></button>
    </div>`);
  } else if(sb.type==='button') {
    h += g('Text buton', ti('label', sb.label||'Apasă'));
    h += g('URL', ti('url', sb.url||'', 'https://...'));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||14, 10, 40));
    h += g('Mărime buton (spațiere px)', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↕<input type="number" data-subkey="btn_pad_v" value="${+(sb.btn_pad_v??8)}" min="2" max="40" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↔<input type="number" data-subkey="btn_pad_h" value="${+(sb.btn_pad_h??18)}" min="4" max="80" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      </div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||6, 0, 50));
    h += g('Culoare buton', ti('button_color', sb.button_color||'#2d7d5f', '#2d7d5f'));
    h += g('Culoare text', ti('text_color', sb.text_color||'#ffffff', '#ffffff'));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  } else if(sb.type==='spacer') {
    h += g('Înălțime (px)', ni('height', sb.height||16, 4, 200));
  } else if(sb.type==='divider') {
    h += g('Culoare linie', ti('color', sb.color||'#e5e7eb', '#e5e7eb'));
    h += g('Grosime (px)', ni('thickness', sb.thickness||1, 1, 10));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  }
TXT,
    ],
    [
        'nou' => <<<'TXT'

  // Fundalul blocului din coloană: bifa îl golește (transparent), selectorul
  // de culoare îl pune la loc. Cele două trebuie ținute sincronizate.
  const bgText = el.querySelector('input[data-subkey="background"]');
  const bgColor = el.querySelector('[data-subbgcolor]');
  const bgNone = el.querySelector('[data-subbgnone]');
  if(bgColor) {
    bgColor.addEventListener('input', () => {
      if(bgNone) bgNone.checked = false;
      if(bgText) bgText.value = bgColor.value;
      bgColor.style.opacity = '';
      updateSubBlock(blockI, ci, cbi, {background: bgColor.value});
    });
  }
  if(bgNone) {
    bgNone.addEventListener('change', () => {
      const valoare = bgNone.checked ? '' : (bgColor ? bgColor.value : '#ffffff');
      if(bgText) bgText.value = valoare;
      if(bgColor) bgColor.style.opacity = bgNone.checked ? '.4' : '';
      updateSubBlock(blockI, ci, cbi, {background: valoare});
    });
  }
  if(bgText) {
    // Scris de mână: bifa se stinge sau se aprinde după conținut.
    bgText.addEventListener('input', () => {
      const gol = bgText.value.trim() === '';
      if(bgNone) bgNone.checked = gol;
      if(bgColor) {
        bgColor.style.opacity = gol ? '.4' : '';
        if(!gol && /^#[0-9a-f]{6}$/i.test(bgText.value.trim())) bgColor.value = bgText.value.trim();
      }
    });
  }

  // Sub-block alignment buttons
  el.querySelectorAll('[data-subalign]').forEach(btn => {
TXT,
        'vechi' => <<<'TXT'

  // Sub-block image alignment buttons
  el.querySelectorAll('[data-subalign]').forEach(btn => {
TXT,
    ],
    [
        'nou' => <<<'TXT'
let imgPickCb = null;
/**
 * Ia titlul și coperta unui link video de la TikTok / YouTube / Vimeo.
 *
 * Serverul întreabă furnizorul și descarcă imaginea la noi: adresele de
 * copertă ale TikTok expiră și sunt blocate de pe alte site-uri, deci una
 * legată direct ar arăta ruptă în inbox peste câteva zile.
 */
window.ebVideoLookup = async function(blockIndex) {
  const b = blocks[blockIndex];
  if(!b) return;
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
    render();
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

window.ebOpenImgModal = function(blockIndex, propKey, callback) {
TXT,
        'vechi' => <<<'TXT'
let imgPickCb = null;
window.ebOpenImgModal = function(blockIndex, propKey, callback) {
TXT,
    ],
];
