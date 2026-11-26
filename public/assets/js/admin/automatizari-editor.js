/*
 * Editorul vizual al unei automatizări (pluginul automatizari; views/admin/automatizari/editor.php, macheta aprobată
 * „Automatizări – editorul de flux”). Datele vin din JSON-ul paginii (#af-date); tot ce se scrie în pagină trece prin
 * createElement / textContent (nimic din date nu ajunge ca HTML). Fluxul e un arbore: o listă de pași, în care ultimul
 * poate fi un „Dacă / altfel” cu două liste (da, nu). Salvarea trimite arborele la /admin/automatizari/{id}/salveaza,
 * unde serverul îl verifică din nou (App\Support\Automatizari\Flux::randuriDinArbore).
 *
 * Mișcarea: un pas adăugat apare (se mărește puțin și se luminează), unul mutat alunecă la locul nou, unul șters se
 * stinge (FLIP cu Web Animations, 220 ms, ca builderul newsletterului); cu „mișcare redusă” în sistem nu se mișcă nimic.
 */
(function () {
  'use strict';
  const nodDate = document.getElementById('af-date');
  if (!nodDate) return;
  const D = JSON.parse(nodDate.textContent || '{}');
  const S = {
    nume: D.nume || '',
    stare: D.stare || 'ciorna',
    declansator: D.declansator,
    setari: D.setari && !Array.isArray(D.setari) ? D.setari : {},
    permite_reintrarea: !!D.permite_reintrarea,
    pasi: Array.isArray(D.pasi) ? D.pasi : [],
  };
  // Din PHP un obiect gol vine ca listă ([]): proprietățile puse pe o listă s-ar pierde la JSON.stringify.
  (function obiecte(lista) {
    for (const nod of lista) {
      if (!nod.setari || Array.isArray(nod.setari)) nod.setari = {};
      if (nod.tip === 'daca') { obiecte(nod.da = nod.da || []); obiecte(nod.nu = nod.nu || []); }
    }
  })(S.pasi);
  if (Array.isArray(D.emailuri)) D.emailuri = Object.assign({}, D.emailuri);
  if (Array.isArray(D.previzualizari)) D.previzualizari = Object.assign({}, D.previzualizari);
  if (Array.isArray(D.numere_pasi)) D.numere_pasi = {};
  let ales = D.pas_ales || '__decl';
  let murdar = false;
  let alegeLoc = null; // {lista, index} unde se pune pasul ales din fereastra „+”
  const miscareRedusa = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const flux = document.getElementById('af-flux');
  const panza = document.getElementById('af-panza');
  const alege = document.getElementById('af-alege');
  const alegeCauta = document.getElementById('af-alege-cauta');
  const panouCorp = document.getElementById('af-panou-corp');
  const panouSubsol = document.getElementById('af-panou-subsol');

  // ── Desenul: iconițe și culori pe tip (ca în machetă) ─────────────────────────────────────────────────────────────
  const CULORI = {
    decl: ['#dbeafe', '#1d4ed8'], asteapta: ['#fef3c7', '#92400e'], daca: ['#ede9fe', '#6d28d9'],
    email: ['#d1fae5', '#065f46'], anunta: ['#d1fae5', '#065f46'], cod_reducere: ['#d1fae5', '#065f46'],
    eticheta_adauga: ['#e0e7ff', '#3730a3'], eticheta_scoate: ['#e0e7ff', '#3730a3'], lista_muta: ['#e0e7ff', '#3730a3'],
    lista_adauga: ['#e0e7ff', '#3730a3'],
  };
  const CULORI_GRUP = { timp: ['#fef3c7', '#92400e'], mesaje: ['#d1fae5', '#065f46'], abonat: ['#e0e7ff', '#3730a3'] };
  const DESEN = {
    decl: '<path d="M6 7h12l-1 13H7L6 7z"></path><path d="M9 7a3 3 0 0 1 6 0"></path>',
    decl_abonat_confirmat: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M16 11l2 2 4-4"></path>',
    decl_cont_creat: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M19 8v6"></path><path d="M22 11h-6"></path>',
    decl_cos_abandonat: '<circle cx="9" cy="20" r="1.5"></circle><circle cx="18" cy="20" r="1.5"></circle><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6"></path>',
    decl_client_inactiv: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path><path d="M4 4l16 16"></path>',
    decl_eticheta_primita: '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"></path><path d="M7.5 7.5h.01"></path>',
    asteapta: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
    daca: '<path d="M12 3v6"></path><path d="M12 9L6 15v6"></path><path d="M12 9l6 6v6"></path>',
    email: '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 7l9 6 9-6"></path>',
    anunta: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path>',
    cod_reducere: '<path d="M3 9V7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2a3 3 0 0 0 0 6v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a3 3 0 0 0 0-6z"></path><path d="M9.5 14.5l5-5"></path>',
    eticheta_adauga: '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"></path><path d="M7.5 7.5h.01"></path>',
    eticheta_scoate: '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"></path><path d="M9 11h6"></path>',
    lista_muta: '<path d="M8 6h13"></path><path d="M8 12h13"></path><path d="M8 18h13"></path><path d="M3 6h.01"></path><path d="M3 12h.01"></path><path d="M3 18h.01"></path>',
    lista_adauga: '<path d="M11 12H3"></path><path d="M16 6H3"></path><path d="M16 18H3"></path><path d="M18 9v6"></path><path d="M21 12h-6"></path>',
  };
  function svg(tip) {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = DESEN[tip] || DESEN.email; // desenele sunt constante din acest fișier
    return s;
  }
  function h(tag, atribute, ...copii) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(atribute || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : String(v));
    }
    for (const c of copii.flat()) {
      if (c === null || c === undefined || c === false) continue;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return el;
  }

  // ── Arborele ──────────────────────────────────────────────────────────────────────────────────────────────────────
  function cauta(cheie, lista = S.pasi, parinte = null) {
    for (let i = 0; i < lista.length; i++) {
      const nod = lista[i];
      if (nod.cheie === cheie) return { nod, lista, index: i, parinte };
      if (nod.tip === 'daca') {
        const r = cauta(cheie, nod.da || [], nod) || cauta(cheie, nod.nu || [], nod);
        if (r) return r;
      }
    }
    return null;
  }
  function toate(lista = S.pasi, rez = []) {
    for (const nod of lista) {
      rez.push(nod);
      if (nod.tip === 'daca') { toate(nod.da || [], rez); toate(nod.nu || [], rez); }
    }
    return rez;
  }
  /** Pașii „Trimite email” de deasupra unui pas (pe drumul până la el), pentru condițiile „A deschis / A apăsat”. */
  function emailuriInainte(cheie) {
    const drum = [];
    (function mergi(lista, acum) {
      for (const nod of lista) {
        if (nod.cheie === cheie) { drum.push(...acum); return true; }
        acum = acum.concat(nod.tip === 'email' ? [nod] : []);
        if (nod.tip === 'daca' && (mergi(nod.da || [], acum) || mergi(nod.nu || [], acum))) return true;
      }
      return false;
    })(S.pasi, []);
    return drum;
  }
  function cheieNoua() {
    const folosite = new Set(toate().map((n) => n.cheie));
    let c;
    do { c = 'p' + Math.random().toString(36).slice(2, 8); } while (folosite.has(c));
    return c;
  }
  function implicite(tip) {
    switch (tip) {
      case 'asteapta': return { cat: 1, unitate: 'zile', doar_ziua: false, ora_de: 9, ora_pana: 20 };
      case 'daca': return { conditie: 'a_deschis', email_pas: '' };
      case 'email': return { email_id: 0 };
      case 'anunta': return { destinatari: '', subiect: '', mesaj: '' };
      case 'cod_reducere': return { tip: 'percent', valoare: 10, zile: 14, prefix: '', cumulabil: false };
      case 'lista_muta': return { lista_id: 0, din_lista_id: 0 };
      case 'lista_adauga': return { lista_id: 0 };
      default: return { eticheta_id: 0 };
    }
  }

  // ── Textele cardurilor ────────────────────────────────────────────────────────────────────────────────────────────
  const numeLista = (id) => (D.liste.find((l) => l.id === Number(id)) || {}).nume || '';
  const numeEticheta = (id) => (D.etichete.find((e) => e.id === Number(id)) || {}).nume || '';
  const numeCategorie = (id) => (D.categorii.find((c) => c.id === Number(id)) || {}).nume || ('#' + id);
  const numeProdus = (id) => (D.produse.find((p) => p.id === Number(id)) || {}).nume || ('produsul #' + id);
  const procent = (a, b) => (b > 0 ? Math.round((a * 100) / b) + '%' : '—');
  const UNITATI = { minute: ['minut', 'minute'], ore: ['oră', 'ore'], zile: ['zi', 'zile'], saptamani: ['săptămână', 'săptămâni'] };
  const durata = (n, u) => n + ' ' + ((UNITATI[u] || UNITATI.zile)[Number(n) === 1 ? 0 : 1]);
  function filtruProduse(s) {
    const parti = [];
    if ((s.categorii || []).length) parti.push('din „' + s.categorii.map(numeCategorie).join('”, „') + '”');
    if ((s.produse || []).length) parti.push(s.produse.length === 1 ? numeProdus(s.produse[0]) : s.produse.length + ' produse alese');
    return parti.length ? parti.join(' sau ') : 'orice produs';
  }
  function textDeclansator() {
    const s = S.setari || {};
    const d = D.declansatoare[S.declansator] || { eticheta: S.declansator };
    let sub = '';
    switch (S.declansator) {
      case 'abonat_confirmat': sub = Number(s.lista_id) > 0 ? 'în lista „' + numeLista(s.lista_id) + '”' : 'în orice listă'; break;
      case 'comanda_plasata': sub = filtruProduse(s); break;
      case 'comanda_finalizata': sub = filtruProduse(s) + (s.doar_prima ? ' · doar prima comandă' : ''); break;
      case 'cos_abandonat': sub = 'după ' + durata(s.dupa || 60, s.unitate || 'minute'); break;
      case 'client_inactiv': sub = durata(s.zile || 90, 'zile') + ' fără comandă'; break;
      case 'cont_creat': sub = 'contul de client e creat'; break;
      case 'eticheta_primita': sub = Number(s.eticheta_id) > 0 ? 'eticheta „' + numeEticheta(s.eticheta_id) + '”' : 'alege eticheta'; break;
    }
    return [d.eticheta, sub + (S.permite_reintrarea ? ' · cu reintrare' : '')];
  }
  function textPas(nod) {
    const s = nod.setari || {};
    const n = (D.numere_pasi || {})[nod.cheie] || {};
    switch (nod.tip) {
      case 'asteapta':
        return ['Așteaptă', durata(s.cat || 1, s.unitate), s.doar_ziua ? 'doar între ' + s.ora_de + ':00 și ' + s.ora_pana + ':00' : '',
          n.in_procesare ? n.in_procesare + ' așteaptă' : ''];
      case 'daca': {
        let t = D.conditii[s.conditie] || 'Condiție';
        if (s.conditie === 'are_eticheta') t = 'Are eticheta „' + (numeEticheta(s.eticheta_id) || '…') + '”';
        if (s.conditie === 'in_lista') t = 'E în lista „' + (numeLista(s.lista_id) || '…') + '”';
        if (s.conditie === 'total_minim') t = 'Comanda are cel puțin ' + (s.valoare || 0) + ' lei';
        if (s.conditie === 'a_cumparat') t = 'A cumpărat ' + filtruProduse(s) + (s.de_la_intrare ? ' de la intrare' : '');
        return ['Dacă', t + '?', '', ''];
      }
      case 'email': {
        const e = D.emailuri[s.email_id];
        return ['Trimite email', e ? e.subiect || e.nume : 'Emailul nu e făcut încă',
          n.trimise ? 'deschis ' + procent(n.deschise, n.trimise) + ' · clic ' + procent(n.clicuri, n.trimise) : (e ? '' : 'apasă ca să-l faci'), ''];
      }
      case 'anunta': return ['Anunță-mă', s.subiect || 'Email către administrator', s.destinatari || D.email_contact || 'adresa de contact a site-ului', ''];
      case 'cod_reducere':
        return ['Cod de reducere', (s.tip === 'fixed' ? (s.valoare || 0) + ' lei' : (s.valoare || 0) + '%') + ' reducere',
          'cod unic, valabil ' + durata(s.zile || 14, 'zile'), ''];
      case 'eticheta_adauga': return ['Adaugă etichetă', numeEticheta(s.eticheta_id) || 'Alege eticheta', '', ''];
      case 'eticheta_scoate': return ['Scoate eticheta', numeEticheta(s.eticheta_id) || 'Alege eticheta', '', ''];
      case 'lista_muta': return ['Mută în listă', numeLista(s.lista_id) || 'Alege lista', Number(s.din_lista_id) > 0 ? 'din „' + numeLista(s.din_lista_id) + '”' : 'din celelalte liste', ''];
      case 'lista_adauga': return ['Adaugă în listă', numeLista(s.lista_id) || 'Alege lista', '', ''];
    }
    return [nod.tip, '', '', ''];
  }

  // ── Pânza ─────────────────────────────────────────────────────────────────────────────────────────────────────────
  let locuri = new Map();
  function card(cheie, tip, eticheta, titlu, sub, insigna) {
    const [fundal, culoare] = CULORI[tip] || CULORI.email;
    const ic = h('span', { class: 'af-ic', style: 'background:' + fundal + ';color:' + culoare });
    ic.appendChild(svg(tip === 'decl' && DESEN['decl_' + S.declansator] ? 'decl_' + S.declansator : tip));
    return h('button', {
      type: 'button', class: 'af-card', 'data-cheie': cheie, 'aria-pressed': ales === cheie ? 'true' : 'false',
      'aria-label': eticheta + ': ' + titlu + (sub ? ', ' + sub : ''),
      onclick: () => selecteaza(cheie),
    }, ic, h('span', { class: 'af-text' },
      h('span', { class: 'af-tip', style: 'color:' + culoare, text: eticheta }),
      h('span', { class: 'af-titlu', text: titlu }),
      sub ? h('span', { class: 'af-sub', text: sub }) : null),
    insigna ? h('span', { class: 'af-insigna', text: insigna }) : null);
  }
  function plus(lista, index) {
    const id = 'loc' + locuri.size;
    locuri.set(id, { lista, index });
    return h('button', { type: 'button', class: 'af-plus', 'data-loc': id, 'aria-label': 'Adaugă un pas aici', 'aria-expanded': 'false',
      'aria-controls': 'af-alege', onclick: (ev) => deschideAlege(ev.currentTarget), text: '+' });
  }
  function secventa(lista, inRamura) {
    const parti = [];
    lista.forEach((nod, i) => {
      parti.push(h('span', { class: 'af-linie' }), plus(lista, i), h('span', { class: 'af-linie' }));
      const [et, titlu, sub, ins] = textPas(nod);
      parti.push(card(nod.cheie, nod.tip, et, titlu, sub, ins));
      if (nod.tip === 'daca') {
        nod.da = nod.da || []; nod.nu = nod.nu || [];
        parti.push(h('div', { class: 'af-ramuri' },
          ramura('Da', 'da', nod.da), ramura('Nu', 'nu', nod.nu)));
      }
    });
    const ultim = lista[lista.length - 1];
    if (!ultim || ultim.tip !== 'daca') {
      if (lista.length === 0 && inRamura) {
        parti.push(h('span', { class: 'af-linie' }), plus(lista, 0), h('span', { class: 'af-linie' }),
          h('span', { class: 'af-gata', text: 'Gata, iese din automatizare' }));
      } else {
        parti.push(h('span', { class: 'af-linie' }), plus(lista, lista.length), h('span', { class: 'af-linie' }),
          h('span', { class: 'af-capat', title: 'Capătul fluxului', 'aria-label': 'Capătul fluxului', text: '✓' }));
      }
    }
    return parti;
  }
  function ramura(eticheta, clasa, lista) {
    return h('div', { class: 'af-ramura' }, h('span', { class: 'af-linie af-linie--lunga' }),
      h('span', { class: 'af-pastila af-pastila--' + clasa, text: eticheta }), ...secventa(lista, true));
  }
  function pozitii() {
    const p = new Map();
    flux.querySelectorAll('.af-card').forEach((el) => p.set(el.dataset.cheie, el.getBoundingClientRect()));
    return p;
  }
  /** Desenează pânza; cu `animat`, FLIP: fiecare card pornește din locul vechi, cele noi apar, cele șterse se sting. */
  function deseneaza(animat, nouCheie) {
    const inainte = animat && !miscareRedusa ? pozitii() : null;
    const fantome = [];
    if (inainte) {
      const ramase = new Set(toate().map((n) => n.cheie).concat(['__decl']));
      flux.querySelectorAll('.af-card').forEach((el) => {
        if (!ramase.has(el.dataset.cheie)) {
          const r = el.getBoundingClientRect();
          const pr = panza.getBoundingClientRect();
          const copie = el.cloneNode(true);
          copie.removeAttribute('data-cheie');
          copie.setAttribute('inert', '');
          copie.setAttribute('aria-hidden', 'true');
          copie.style.cssText = 'position:absolute;margin:0;pointer-events:none;z-index:5;left:' + (r.left - pr.left + panza.scrollLeft) + 'px;top:'
            + (r.top - pr.top + panza.scrollTop) + 'px;width:' + r.width + 'px;';
          fantome.push(copie);
        }
      });
    }
    locuri = new Map();
    const [titlu, sub] = textDeclansator();
    flux.replaceChildren(card('__decl', 'decl', 'Pornește când', titlu, sub, ''), ...secventa(S.pasi, false));
    if (!inainte) return;
    fantome.forEach((f) => {
      panza.appendChild(f);
      f.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.94)' }], { duration: 220, easing: 'ease-out' })
        .finished.then(() => f.remove(), () => f.remove());
    });
    flux.querySelectorAll('.af-card').forEach((el) => {
      const vechi = inainte.get(el.dataset.cheie);
      const acum = el.getBoundingClientRect();
      if (vechi) {
        const dx = vechi.left - acum.left;
        const dy = vechi.top - acum.top;
        if (dx || dy) el.animate([{ transform: 'translate(' + dx + 'px,' + dy + 'px)' }, { transform: 'none' }], { duration: 220, easing: 'cubic-bezier(.2,.7,.2,1)' });
      } else if (el.dataset.cheie === nouCheie) {
        el.animate([{ opacity: 0, transform: 'scale(.96)', boxShadow: '0 0 0 6px rgba(29,78,216,.25)' },
          { opacity: 1, transform: 'scale(1)', boxShadow: '0 4px 14px rgba(29,78,216,.18)' }], { duration: 220, easing: 'ease-out' });
      }
    });
  }

  // ── Fereastra „+” (pașii pe grupuri, cu căutare) ──────────────────────────────────────────────────────────────────
  const faraDiacritice = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  function umpleAlege() {
    const q = faraDiacritice(alegeCauta.value.trim());
    const grupuri = document.getElementById('af-alege-grupuri');
    const parti = [];
    for (const [grup, titluGrup] of Object.entries(D.grupuri)) {
      const butoane = Object.entries(D.pasi_tipuri).filter(([, p]) => p.grup === grup)
        .filter(([, p]) => !q || faraDiacritice(p.eticheta + ' ' + titluGrup).includes(q))
        .map(([tip, p]) => {
          const [fundal, culoare] = CULORI_GRUP[grup] || CULORI.email;
          const ic = h('span', { class: 'af-alege-ic', style: 'background:' + fundal + ';color:' + culoare });
          ic.appendChild(svg(tip));
          return h('button', { type: 'button', 'data-tip': tip, onclick: () => adaugaPas(tip) }, ic, p.eticheta);
        });
      if (butoane.length) parti.push(h('div', { class: 'af-alege-grup' }, h('span', { text: titluGrup }), h('div', { class: 'af-alege-grila' }, ...butoane)));
    }
    grupuri.replaceChildren(...(parti.length ? parti : [h('p', { class: 'af-alege-gol', text: 'Niciun pas nu se potrivește.' })]));
  }
  let butonAlege = null;
  function deschideAlege(buton) {
    if (butonAlege === buton && !alege.hidden) { inchideAlege(); return; }
    inchideAlege();
    alegeLoc = locuri.get(buton.dataset.loc);
    butonAlege = buton;
    buton.setAttribute('aria-expanded', 'true');
    alegeCauta.value = '';
    umpleAlege();
    alege.hidden = false;
    const r = buton.getBoundingClientRect();
    const pr = panza.getBoundingClientRect();
    const latime = alege.offsetWidth;
    let stanga = r.left - pr.left + panza.scrollLeft + r.width / 2 - latime / 2;
    stanga = Math.max(panza.scrollLeft + 8, Math.min(stanga, panza.scrollLeft + panza.clientWidth - latime - 8));
    alege.style.left = stanga + 'px';
    alege.style.top = (r.bottom - pr.top + panza.scrollTop + 10) + 'px';
    alegeCauta.focus();
  }
  function inchideAlege(inapoi) {
    if (alege.hidden) return;
    alege.hidden = true;
    if (butonAlege) {
      butonAlege.setAttribute('aria-expanded', 'false');
      if (inapoi && document.body.contains(butonAlege)) butonAlege.focus();
    }
    butonAlege = null;
  }
  alegeCauta.addEventListener('input', umpleAlege);
  alege.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') { ev.preventDefault(); inchideAlege(true); }
  });
  document.addEventListener('mousedown', (ev) => {
    if (!alege.hidden && !alege.contains(ev.target) && !(ev.target.closest && ev.target.closest('.af-plus'))) inchideAlege();
  });
  function adaugaPas(tip) {
    if (!alegeLoc) return;
    const nod = { cheie: cheieNoua(), tip, setari: implicite(tip) };
    if (tip === 'daca') {
      // Pașii de după locul ales trec pe ramura „Nu” (fluxul continuă doar pe ramuri).
      nod.da = [];
      nod.nu = alegeLoc.lista.splice(alegeLoc.index);
    }
    alegeLoc.lista.splice(alegeLoc.index, 0, nod);
    inchideAlege();
    ales = nod.cheie;
    schimbat();
    deseneaza(true, nod.cheie);
    panou();
    const el = flux.querySelector('.af-card[data-cheie="' + nod.cheie + '"]');
    if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'nearest', behavior: miscareRedusa ? 'auto' : 'smooth' }); }
  }

  // ── Selecția și panoul ────────────────────────────────────────────────────────────────────────────────────────────
  function selecteaza(cheie) {
    ales = cheie;
    flux.querySelectorAll('.af-card').forEach((el) => el.setAttribute('aria-pressed', el.dataset.cheie === cheie ? 'true' : 'false'));
    panou();
  }
  function camp(eticheta, control, nota) {
    return h('label', { class: 'af-camp' }, eticheta, control, nota ? h('span', { class: 'af-nota', text: nota }) : null);
  }
  function select(valoare, optiuni, la) {
    const el = h('select', { onchange: (ev) => la(ev.target.value) });
    for (const [v, t] of optiuni) {
      const o = h('option', { value: v, text: t });
      if (String(v) === String(valoare)) o.selected = true;
      el.appendChild(o);
    }
    return el;
  }
  function numar(valoare, min, max, la, eticheta) {
    return h('input', { type: 'number', value: valoare, min, max, step: 'any', 'aria-label': eticheta || null,
      oninput: (ev) => la(ev.target.value === '' ? '' : Number(ev.target.value)) });
  }
  function bifa(text, valoare, la) {
    const i = h('input', { type: 'checkbox', onchange: (ev) => la(ev.target.checked) });
    i.checked = !!valoare;
    return h('label', { class: 'af-bifa' }, i, text);
  }
  function nota(text) { return h('p', { class: 'af-nota', text }); }
  function caseta(text, atentie) { return h('div', { class: 'af-caseta' + (atentie ? ' af-caseta--atentie' : ''), text }); }
  /** Actualizarea unei setări: datele, cardul (fără să refacă panoul, deci fără să piardă focusul). */
  function seteaza(obiect, cheie, valoare) {
    obiect[cheie] = valoare;
    schimbat();
    deseneaza(false);
  }
  function optiuniListe(cuToate, textToate) {
    return (cuToate ? [[0, textToate]] : [[0, 'Alege lista…']]).concat(D.liste.map((l) => [l.id, l.nume + (l.implicita ? ' (toți abonații)' : '')]));
  }
  function alegereEticheta(s, cheie) {
    const mesaj = h('span', { class: 'af-mesaj', role: 'status', 'aria-live': 'polite' });
    const nou = h('input', { type: 'text', maxlength: 80, placeholder: 'ex. a cumpărat ceai', 'aria-label': 'Numele etichetei noi' });
    const sel = select(s[cheie] || 0, [[0, 'Alege eticheta…']].concat(D.etichete.map((e) => [e.id, e.nume])), (v) => seteaza(s, cheie, Number(v)));
    const buton = h('button', { type: 'button', class: 'af-btn', text: 'Adaugă', onclick: async () => {
      const nume = nou.value.trim();
      if (!nume) { nou.focus(); return; }
      const r = await cere('eticheta', { nume });
      if (r.ok) { D.etichete = r.etichete; s[cheie] = r.eticheta.id; schimbat(); deseneaza(false); panou(); }
      else { mesaj.dataset.ton = 'eroare'; mesaj.textContent = r.mesaj || 'Eticheta nu a putut fi făcută.'; }
    } });
    return [camp('Eticheta', sel), h('div', { class: 'af-camp' }, 'Sau o etichetă nouă', h('div', { class: 'af-rand' }, nou, h('span', { class: 'af-ingust' }, buton))), mesaj];
  }
  /** Categoriile (bife) și produsele (căutare, jetoane) unui filtru „doar pentru”. */
  function filtru(s) {
    s.produse = s.produse || []; s.categorii = s.categorii || [];
    const bucati = [];
    const mod = s.produse.length ? 'produse' : (s.categorii.length ? 'categorii' : 'oricare');
    const zona = h('div', { class: 'af-camp' });
    const deseneazaZona = (m) => {
      zona.replaceChildren();
      if (m === 'categorii') {
        const lista = h('div', { class: 'af-lista-bife', role: 'group', 'aria-label': 'Categoriile' });
        D.categorii.forEach((c) => {
          const i = h('input', { type: 'checkbox', onchange: (ev) => {
            s.categorii = ev.target.checked ? s.categorii.concat([c.id]) : s.categorii.filter((x) => x !== c.id);
            schimbat(); deseneaza(false);
          } });
          i.checked = s.categorii.includes(c.id);
          lista.appendChild(h('label', null, i, c.nume));
        });
        zona.append(D.categorii.length ? lista : nota('Catalogul nu are categorii.'));
      } else if (m === 'produse') {
        const jetoane = h('div', { class: 'af-jetoane' });
        const rezultate = h('div', { class: 'af-rezultate' });
        const pune = () => jetoane.replaceChildren(...s.produse.map((id) => h('span', { class: 'af-jeton' }, numeProdus(id),
          h('button', { type: 'button', 'aria-label': 'Scoate ' + numeProdus(id), text: '×', onclick: () => {
            s.produse = s.produse.filter((x) => x !== id); schimbat(); deseneaza(false); pune();
          } }))));
        let ceas = null;
        const cautare = h('input', { type: 'search', placeholder: 'Caută după nume sau cod…', 'aria-label': 'Caută un produs', oninput: (ev) => {
          clearTimeout(ceas);
          const q = ev.target.value.trim();
          if (q.length < 2) { rezultate.replaceChildren(); return; }
          ceas = setTimeout(async () => {
            try {
              const r = await (await fetch('/admin/emails/builder/produse?q=' + encodeURIComponent(q), { credentials: 'same-origin' })).json();
              rezultate.replaceChildren(...(r.items || []).slice(0, 8).map((p) => h('button', { type: 'button', text: p.name, onclick: () => {
                if (!D.produse.find((x) => x.id === Number(p.id))) D.produse.push({ id: Number(p.id), nume: p.name });
                if (!s.produse.includes(Number(p.id))) s.produse = s.produse.concat([Number(p.id)]);
                schimbat(); deseneaza(false); pune(); rezultate.replaceChildren(); cautare.value = '';
              } })));
            } catch (e) { rezultate.replaceChildren(nota('Căutarea nu a mers. Încearcă din nou.')); }
          }, 250);
        } });
        pune();
        zona.append(cautare, rezultate, jetoane);
      }
    };
    const optiuni = [['oricare', 'Orice produs']];
    if (D.categorii.length) optiuni.push(['categorii', 'Produse din anumite categorii…']);
    if (D.cu_catalog) optiuni.push(['produse', 'Anumite produse…']);
    bucati.push(camp('Doar pentru', select(mod, optiuni, (v) => {
      if (v === 'oricare') { s.produse = []; s.categorii = []; }
      if (v === 'categorii') s.produse = [];
      if (v === 'produse') s.categorii = [];
      schimbat(); deseneaza(false); deseneazaZona(v);
    })));
    deseneazaZona(mod);
    bucati.push(zona);
    return bucati;
  }
  function numerePas(cheie) {
    const n = (D.numere_pasi || {})[cheie] || {};
    return h('div', { class: 'af-numere-pas', 'aria-label': 'Numărătorile pasului' },
      h('span', null, h('strong', { text: String(n.intrate || 0) }), 'Intrate'),
      h('span', null, h('strong', { text: String(n.in_procesare || 0) }), 'În procesare'),
      h('span', null, h('strong', { text: String(n.iesite || 0) }), 'Ieșite'));
  }

  function panouDeclansator() {
    const s = S.setari;
    const bucati = [];
    bucati.push(camp('Ce o pornește', select(S.declansator, Object.entries(D.declansatoare).map(([k, d]) => [k, d.eticheta]), (v) => {
      S.declansator = v; S.setari = {}; schimbat(); deseneaza(false); panou();
    })));
    switch (S.declansator) {
      case 'abonat_confirmat':
        bucati.push(camp('Doar pentru', select(s.lista_id || 0, optiuniListe(true, 'Orice listă'), (v) => seteaza(s, 'lista_id', Number(v)))),
          nota('Pornește când omul confirmă abonarea din emailul primit (dubla confirmare).'));
        break;
      case 'comanda_plasata':
        bucati.push(...filtru(s), nota('O comandă plătită cu cardul sau prin ordin de plată intră în flux după confirmarea plății (cel mult 3 zile).'));
        break;
      case 'comanda_finalizata':
        bucati.push(...filtru(s), bifa('Doar la prima comandă a clientului', s.doar_prima, (v) => seteaza(s, 'doar_prima', v)),
          nota('Pornește când comanda trece în „Finalizată” (din Comenzi, la generarea AWB-ului sau din ERP).'));
        break;
      case 'cos_abandonat':
        if (!s.unitate) s.unitate = 'ore';
        if (!s.dupa) s.dupa = 1;
        bucati.push(h('div', { class: 'af-camp' }, 'Coșul e abandonat după', h('div', { class: 'af-rand' },
          h('span', { class: 'af-ingust' }, numar(s.dupa, 1, 10080, (v) => seteaza(s, 'dupa', v), 'Cât')),
          select(s.unitate, [['minute', 'minute'], ['ore', 'ore']], (v) => seteaza(s, 'unitate', v)))));
        bucati.push(caseta(D.email_clasic_cos
          ? 'Fără emailuri duble: cât automatizarea e pornită, coșurile abonaților primesc doar emailurile ei. Emailul clasic de coș abandonat (Emailuri de comandă) rămâne pentru cei care nu sunt abonați.'
          : 'Cât automatizarea e pornită, coșurile abonaților primesc doar emailurile ei (emailul clasic de coș abandonat e oprit pe site).', true));
        bucati.push(nota('Intră doar coșurile cu adresa unui abonat activ, nevăzute de atâta timp, din ultimele 3 zile, fără o comandă a aceleiași adrese. Dacă omul cumpără între timp, emailurile se opresc.'));
        break;
      case 'client_inactiv':
        bucati.push(camp('Zile fără comandă', numar(s.zile || 90, 7, 730, (v) => seteaza(s, 'zile', v))),
          nota('Intră clienții abonați a căror ultimă comandă a trecut de atâtea zile (doar cine a trecut pragul în ultimele 30 de zile, nu toți clienții vechi deodată). Se verifică o dată pe oră.'));
        break;
      case 'cont_creat':
        bucati.push(nota('Pornește când un client își face cont pe site (și prima intrare cu Google), dacă adresa lui e abonată la newsletter.'));
        break;
      case 'eticheta_primita':
        bucati.push(...alegereEticheta(s, 'eticheta_id'), nota('Pornește când abonatul primește eticheta (de la pasul „Adaugă etichetă” al altei automatizări).'));
        break;
    }
    bucati.push(bifa('O singură dată pe persoană', !S.permite_reintrarea, (v) => { S.permite_reintrarea = !v; schimbat(); deseneaza(false); }),
      nota('Debifat = permite reintrarea: după ce iese din flux, omul poate intra din nou la un eveniment nou (altă comandă, alt coș). Același eveniment nu intră niciodată de două ori.'),
      caseta('Intră doar abonații activi ai newsletterului. Cine se dezabonează (sau e respins) iese din flux la primul email de după.'));
    return bucati;
  }

  function panouPas(nod) {
    const s = nod.setari;
    const b = [];
    switch (nod.tip) {
      case 'asteapta':
        b.push(h('div', { class: 'af-rand' }, h('span', { class: 'af-ingust' }, numar(s.cat, 1, 1000, (v) => seteaza(s, 'cat', v), 'Cât')),
          select(s.unitate, [['zile', 'zile'], ['ore', 'ore'], ['saptamani', 'săptămâni'], ['minute', 'minute']], (v) => seteaza(s, 'unitate', v))));
        b.push(bifa('Trimite doar între ' + s.ora_de + ':00 și ' + s.ora_pana + ':00', s.doar_ziua, (v) => { seteaza(s, 'doar_ziua', v); panou(); }));
        if (s.doar_ziua) {
          const ore = Array.from({ length: 24 }, (_, i) => [i, i + ':00']);
          b.push(h('div', { class: 'af-rand' },
            camp('De la', select(s.ora_de, ore, (v) => { seteaza(s, 'ora_de', Number(v)); panou(); })),
            camp('Până la', select(s.ora_pana, ore.slice(1).concat([[24, '24:00']]), (v) => { seteaza(s, 'ora_pana', Number(v)); panou(); }))));
        }
        b.push(nota('Așteptarea începe când omul ajunge la pas. Cu intervalul orar, un email care ar pica noaptea pleacă dimineața.'));
        break;
      case 'daca': {
        b.push(camp('Condiția', select(s.conditie, Object.entries(D.conditii), (v) => {
          nod.setari = { conditie: v }; if (v === 'a_cumparat') nod.setari.de_la_intrare = true;
          schimbat(); deseneaza(false); panou();
        })));
        if (s.conditie === 'a_deschis' || s.conditie === 'a_dat_clic') {
          const inainte = emailuriInainte(nod.cheie);
          b.push(camp('Care email', select(s.email_pas || '', [['', 'Oricare email de mai sus']].concat(inainte.map((n) => [n.cheie, textPas(n)[1]])),
            (v) => seteaza(s, 'email_pas', v))));
          if (!inainte.length) b.push(caseta('Nu e niciun „Trimite email” deasupra acestui pas: condiția va fi mereu „Nu”.', true));
          b.push(nota('Pune o așteptare înainte (de exemplu 2 zile), ca omul să apuce să deschidă emailul. Pentru un abonat care a refuzat urmărirea, condiția e „Nu”.'));
        } else if (s.conditie === 'are_eticheta') {
          b.push(...alegereEticheta(s, 'eticheta_id'));
        } else if (s.conditie === 'in_lista') {
          b.push(camp('Lista', select(s.lista_id || 0, optiuniListe(false), (v) => seteaza(s, 'lista_id', Number(v)))));
        } else if (s.conditie === 'total_minim') {
          b.push(camp('Totalul comenzii, cel puțin (lei)', numar(s.valoare || 0, 0, 1000000, (v) => seteaza(s, 'valoare', v))),
            nota('Doar pentru fluxurile pornite de o comandă (plasată sau finalizată); altfel „Nu”.'));
        } else if (s.conditie === 'a_cumparat') {
          b.push(...filtru(s), bifa('Doar după ce a intrat în flux', s.de_la_intrare, (v) => seteaza(s, 'de_la_intrare', v)));
        }
        b.push(nota('Pe ramura „Da” poți lăsa gol ca să iasă din automatizare.'));
        break;
      }
      case 'email': {
        const e = D.emailuri[s.email_id];
        const mesaj = h('span', { class: 'af-mesaj', role: 'status', 'aria-live': 'polite' });
        if (e) {
          const cadru = h('iframe', { title: 'Previzualizarea emailului', sandbox: '', loading: 'lazy', tabindex: '-1', src: D.previzualizari[s.email_id] });
          b.push(h('div', { class: 'af-miniatura' }, h('div', { class: 'af-miniatura-cadru' }, cadru),
            h('div', { class: 'af-miniatura-subiect', text: 'Subiect: ' + (e.subiect || '—') })));
        } else {
          b.push(h('div', { class: 'af-miniatura' }, h('div', { class: 'af-miniatura-gol', text: 'Emailul nu e făcut încă' })));
        }
        b.push(h('button', { type: 'button', class: 'af-btn af-btn--contur', text: e ? 'Editează emailul' : 'Fă emailul', onclick: async () => {
          let id = s.email_id;
          if (!D.emailuri[id]) {
            const r = await cere('email-nou', {});
            if (!r.ok) { mesaj.dataset.ton = 'eroare'; mesaj.textContent = r.mesaj || 'Emailul nu a putut fi făcut.'; return; }
            D.emailuri[r.email.id] = r.email; D.previzualizari[r.email.id] = r.previzualizare;
            s.email_id = r.email.id; id = r.email.id; schimbat();
          }
          if (murdar && !(await salveaza())) return;
          window.location.href = '/admin/emails/builder?type=automatizare&id=' + encodeURIComponent(id);
        } }));
        b.push(nota('Se deschide același editor ca la newsletter (varianta C). Blocul „Produse automat” arată produse potrivite pentru fiecare client. Codurile: ' + Object.keys(D.coduri).join(' ')));
        if (e) {
          const adresa = h('input', { type: 'email', value: D.email_test || '', 'aria-label': 'Adresa pentru test', placeholder: 'adresa@exemplu.ro' });
          b.push(h('div', { class: 'af-camp' }, 'Trimite un test', h('div', { class: 'af-rand' }, adresa, h('span', { class: 'af-ingust' },
            h('button', { type: 'button', class: 'af-btn', text: 'Trimite', onclick: async () => {
              mesaj.dataset.ton = ''; mesaj.textContent = 'Se trimite…';
              const r = await cere('email-test', { email_id: s.email_id, adresa: adresa.value.trim() });
              mesaj.dataset.ton = r.ok ? 'ok' : 'eroare'; mesaj.textContent = r.mesaj || (r.ok ? 'Trimis.' : 'Nu a plecat.');
            } })))), nota('Testul are exemple în locul codurilor („Ana”, „EXEMPLU10”) și nu e urmărit.'));
          const n = (D.numere_pasi || {})[nod.cheie] || {};
          if (n.trimise) b.push(nota('Trimise ' + n.trimise + ' · deschise ' + procent(n.deschise, n.trimise) + ' · clic ' + procent(n.clicuri, n.trimise)));
        }
        b.push(mesaj);
        break;
      }
      case 'anunta':
        b.push(camp('Către', h('input', { type: 'text', value: s.destinatari || '', placeholder: D.email_contact || 'adresa@exemplu.ro',
          oninput: (ev) => seteaza(s, 'destinatari', ev.target.value) }), 'Gol = adresa de contact a site-ului. Cel mult 5 adrese, despărțite prin virgulă.'),
        camp('Subiect', h('input', { type: 'text', maxlength: 190, value: s.subiect || '', placeholder: 'Automatizarea „' + S.nume + '”',
          oninput: (ev) => seteaza(s, 'subiect', ev.target.value) })),
        camp('Mesaj (opțional)', h('textarea', { maxlength: 2000, oninput: (ev) => seteaza(s, 'mesaj', ev.target.value) }, s.mesaj || '')),
        nota('Primești adresa abonatului și, la comenzi, numărul comenzii. Poți folosi codurile, de exemplu {{numar_comanda}}.'));
        break;
      case 'cod_reducere':
        b.push(h('div', { class: 'af-rand' },
          camp('Reducerea', select(s.tip, [['percent', 'Procent (%)'], ['fixed', 'Sumă (lei)']], (v) => seteaza(s, 'tip', v))),
          camp('Valoare', numar(s.valoare, 1, 10000, (v) => seteaza(s, 'valoare', v)))),
        camp('Valabil (zile)', numar(s.zile, 1, 365, (v) => seteaza(s, 'zile', v))),
        camp('Început de cod (opțional)', h('input', { type: 'text', maxlength: 8, value: s.prefix || '', placeholder: 'ex. BUN',
          oninput: (ev) => seteaza(s, 'prefix', ev.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '')) })),
        bifa('Se poate folosi și peste alte reduceri', s.cumulabil, (v) => seteaza(s, 'cumulabil', v)),
        caseta('Fiecare om primește un cod al lui, folosibil o singură dată (în Cupoane → Coduri unice). Pune {{cod_reducere}} în emailul de după acest pas; {{cod_reducere_valoare}} și {{cod_reducere_expira}} arată reducerea și data.'));
        break;
      case 'eticheta_adauga':
      case 'eticheta_scoate':
        b.push(...alegereEticheta(s, 'eticheta_id'), nota('Doar abonații au etichete. „Primește o etichetă” poate porni altă automatizare.'));
        break;
      case 'lista_muta':
        b.push(camp('Mută în lista', select(s.lista_id || 0, optiuniListe(false), (v) => seteaza(s, 'lista_id', Number(v)))),
          camp('Din', select(s.din_lista_id || 0, optiuniListe(true, 'Toate celelalte liste'), (v) => seteaza(s, 'din_lista_id', Number(v)))),
          nota('Lista implicită („Toți abonații”) rămâne mereu.'));
        break;
      case 'lista_adauga':
        b.push(camp('Adaugă în lista', select(s.lista_id || 0, optiuniListe(false), (v) => seteaza(s, 'lista_id', Number(v)))));
        break;
    }
    b.push(numerePas(nod.cheie));
    return b;
  }

  function panou() {
    const tip = document.getElementById('af-panou-tip');
    const titlu = document.getElementById('af-panou-titlu');
    if (ales === '__decl' || !cauta(ales)) {
      ales = '__decl';
      tip.textContent = 'Declanșator';
      titlu.textContent = (D.declansatoare[S.declansator] || {}).eticheta || 'Declanșator';
      panouCorp.replaceChildren(...panouDeclansator());
      panouSubsol.hidden = true;
      return;
    }
    const gasit = cauta(ales);
    tip.textContent = 'Pas';
    titlu.textContent = (D.pasi_tipuri[gasit.nod.tip] || {}).eticheta || gasit.nod.tip;
    panouCorp.replaceChildren(...panouPas(gasit.nod));
    const sus = h('button', { type: 'button', class: 'af-btn', text: '↑ Mută sus', disabled: gasit.index === 0 || null, onclick: () => muta(-1) });
    const jos = h('button', { type: 'button', class: 'af-btn', text: '↓ Mută jos',
      disabled: gasit.index >= gasit.lista.length - 1 || gasit.lista[gasit.index + 1].tip === 'daca' || gasit.nod.tip === 'daca' || null, onclick: () => muta(1) });
    panouSubsol.replaceChildren(sus, jos, h('button', { type: 'button', class: 'af-btn af-btn--pericol', text: 'Șterge pasul', onclick: sterge }));
    panouSubsol.hidden = false;
  }
  function muta(directie) {
    const g = cauta(ales);
    if (!g) return;
    const j = g.index + directie;
    if (j < 0 || j >= g.lista.length || g.nod.tip === 'daca' || g.lista[j].tip === 'daca') return;
    [g.lista[g.index], g.lista[j]] = [g.lista[j], g.lista[g.index]];
    schimbat();
    deseneaza(true);
    panou();
    flux.querySelector('.af-card[data-cheie="' + ales + '"]')?.focus({ preventScroll: true });
  }
  function sterge() {
    const g = cauta(ales);
    if (!g) return;
    if (g.nod.tip === 'daca' && ((g.nod.da || []).length || (g.nod.nu || []).length)
      && !window.confirm('Ștergi condiția și toți pașii de pe ramurile ei?')) return;
    g.lista.splice(g.index, 1);
    const urmatorul = g.lista[g.index] || g.lista[g.index - 1];
    ales = urmatorul ? urmatorul.cheie : (g.parinte ? g.parinte.cheie : '__decl');
    schimbat();
    deseneaza(true);
    panou();
    flux.querySelector('.af-card[data-cheie="' + ales + '"]')?.focus({ preventScroll: true });
  }

  // ── Salvarea, pornirea, cererile ──────────────────────────────────────────────────────────────────────────────────
  const stareSalvare = document.getElementById('af-salvare');
  function arataSalvare(stare, text) { stareSalvare.dataset.stare = stare; stareSalvare.textContent = text; }
  function schimbat() { murdar = true; arataSalvare('nesalvat', 'Nesalvat'); }
  async function cere(actiune, campuri) {
    const fd = new FormData();
    fd.append('_csrf', D.csrf);
    for (const [k, v] of Object.entries(campuri)) fd.append(k, typeof v === 'string' ? v : String(v));
    try {
      const r = await fetch('/admin/automatizari/' + D.id + '/' + actiune, { method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-CSRF-Token': D.csrf, Accept: 'application/json' } });
      const j = await r.json().catch(() => ({ ok: false, mesaj: 'Răspuns neașteptat (' + r.status + ').' }));
      return j;
    } catch (e) {
      return { ok: false, mesaj: 'Nu am putut ajunge la server. Verifică legătura și încearcă din nou.' };
    }
  }
  function arataProbleme(lista) {
    const el = document.getElementById('af-probleme');
    if (!lista || !lista.length) { el.hidden = true; el.textContent = ''; return; }
    el.textContent = 'Ca să poată porni: ' + lista.join(' ');
    el.hidden = false;
  }
  function arataStare(stare) {
    S.stare = stare;
    const el = document.getElementById('af-stare');
    el.dataset.stare = stare;
    el.textContent = { activa: 'Activă', oprita: 'Oprită', ciorna: 'Ciornă' }[stare] || stare;
    document.getElementById('af-porneste').textContent = stare === 'activa' ? 'Oprește' : 'Pornește';
  }
  async function salveaza() {
    arataSalvare('salveaza', 'Se salvează…');
    const r = await cere('salveaza', { flux: JSON.stringify({ nume: S.nume, declansator: S.declansator, setari: S.setari,
      permite_reintrarea: S.permite_reintrarea, pasi: S.pasi }) });
    if (!r.ok) { arataSalvare('eroare', r.mesaj || 'Nu s-a salvat'); return false; }
    murdar = false;
    arataSalvare('salvat', r.oprita ? 'Salvat · oprită (fluxul nu e complet)' : 'Salvat');
    arataStare(r.stare || S.stare);
    arataProbleme(r.probleme);
    return true;
  }
  document.getElementById('af-salveaza').addEventListener('click', salveaza);
  document.getElementById('af-porneste').addEventListener('click', async () => {
    if (murdar && !(await salveaza())) return;
    const r = await cere('stare', { stare: S.stare === 'activa' ? 'oprita' : 'activa', ajax: '1' });
    if (r.ok) { arataStare(r.stare); arataProbleme([]); arataSalvare('salvat', r.stare === 'activa' ? 'Pornită' : 'Oprită'); }
    else { arataProbleme([r.mesaj || 'Nu a pornit.']); }
  });
  document.getElementById('af-nume').addEventListener('input', (ev) => { S.nume = ev.target.value; schimbat(); });
  document.addEventListener('keydown', (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); salveaza(); }
  });
  window.addEventListener('beforeunload', (ev) => { if (murdar) { ev.preventDefault(); ev.returnValue = ''; } });
  document.getElementById('af-inapoi').addEventListener('click', (ev) => {
    if (murdar && !window.confirm('Ai schimbări nesalvate. Pleci fără să le salvezi?')) ev.preventDefault(); else murdar = false;
  });

  // Ca la builder: sub 1600 px meniul adminului se strânge (pânza și panoul încap la 1366 px), dacă omul nu l-a deschis anume.
  (function strangeMeniul() {
    try {
      const app = document.getElementById('admin-shell');
      if (!app || window.innerWidth >= 1600 || window.innerWidth <= 900) return;
      if (window.localStorage.getItem('admin_sidebar_hidden') === '0') return;
      app.classList.add('sidebar-hidden');
      document.getElementById('sidebar-toggle-btn')?.setAttribute('aria-expanded', 'false');
    } catch (e) { /* fără memorie: meniul rămâne cum e */ }
  })();
  (function potriveste() {
    const el = document.getElementById('af');
    const fit = () => { el.style.height = (window.innerHeight - (el.getBoundingClientRect().top + window.scrollY)) + 'px'; };
    fit();
    window.addEventListener('resize', fit);
  })();

  deseneaza(false);
  panou();
  if (ales !== '__decl') flux.querySelector('.af-card[data-cheie="' + ales + '"]')?.scrollIntoView({ block: 'center' });
  window.afEditor = { stare: () => JSON.parse(JSON.stringify(S)), salveaza }; // pentru testul din browser
})();
