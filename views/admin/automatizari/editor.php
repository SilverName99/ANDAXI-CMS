<?php
/**
 * Editorul vizual al unei automatizări (pluginul automatizari; macheta „Automatizări – editorul de flux”, aprobată):
 * bara de sus (← Automatizări, numele, starea, numărătorile „au intrat / în curs / au terminat”, Oprește / Pornește, Salvează),
 * pânza cu punctele (declanșatorul, apoi pașii unul sub altul, „+” între ei cu fereastra pașilor pe grupuri și căutare,
 * „Dacă / altfel” cu ramurile Da / Nu alături) și panoul din dreapta cu setările pasului ales. Scriptul:
 * public/assets/js/admin/automatizari-editor.js (datele vin în JSON-ul de mai jos; nimic din ele nu ajunge în HTML nescăpat).
 *
 * @var array<string, mixed> $date
 */
$autJson = (string) (json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}');
$autJs = @filemtime(\App\Support\Paths::public('assets/js/admin/automatizari-editor.js')) ?: 1;
$stareEticheta = \App\Support\Automatizari\Flux::STARI[(string) $date['stare']] ?? (string) $date['stare'];
?>
<style>
.af{--af-albastru:#1d4ed8;--af-linie:#9ca3af;display:flex;flex-direction:column;height:calc(100vh - 52px);margin:-8px -16px -24px;overflow:hidden;background:#f3f4f6;color:#1f2937;}
.af-bara{height:64px;flex-shrink:0;display:flex;align-items:center;gap:14px;padding:0 20px;background:#fff;border-bottom:1px solid #e5e7eb;}
.af-inapoi{text-decoration:none;color:#4b5563;font-size:14px;padding:8px 6px;border-radius:8px;white-space:nowrap;}
.af-inapoi:hover{background:#f3f4f6;color:#111827;}
.af-nume{font:inherit;font-size:16px;font-weight:600;color:#111827;border:1px solid transparent;border-radius:8px;padding:6px 8px;min-width:120px;max-width:380px;background:transparent;}
.af-nume:hover{border-color:#e5e7eb;}
.af-nume:focus{border-color:var(--af-albastru);outline:none;box-shadow:0 0 0 3px rgba(29,78,216,.15);}
.af-stare{font-size:12px;padding:4px 10px;border-radius:999px;font-weight:600;white-space:nowrap;}
.af-stare[data-stare="activa"]{background:#d1fae5;color:#065f46;}
.af-stare[data-stare="oprita"]{background:#fee2e2;color:#991b1b;}
.af-stare[data-stare="ciorna"]{background:#f3f4f6;color:#4b5563;}
.af-spatiu{flex:1;}
.af-numere{display:flex;gap:20px;font-size:12px;color:#6b7280;margin-right:12px;}
.af-numere span{display:flex;flex-direction:column;align-items:center;}
.af-numere strong{font-size:16px;color:#111827;font-variant-numeric:tabular-nums;}
.af-salvare{font-size:12px;color:#6b7280;white-space:nowrap;display:flex;align-items:center;gap:6px;}
.af-salvare::before{content:"";width:8px;height:8px;border-radius:50%;background:#16a34a;}
.af-salvare[data-stare="nesalvat"]::before{background:#f59e0b;}
.af-salvare[data-stare="salveaza"]::before{background:#3b82f6;}
.af-salvare[data-stare="eroare"]::before{background:#dc2626;}
.af-salvare[data-stare="eroare"]{color:#b91c1c;}
.af-btn{height:44px;padding:0 16px;border:1px solid #d1d5db;background:#fff;border-radius:10px;font:inherit;font-size:14px;cursor:pointer;color:#1f2937;}
.af-btn:hover{background:#f9fafb;}
.af-btn--principal{border:none;background:var(--af-albastru);color:#fff;font-weight:600;padding:0 18px;}
.af-btn--principal:hover{background:#1e40af;}
.af-btn--contur{border-color:var(--af-albastru);color:var(--af-albastru);font-weight:600;}
.af-btn--pericol{border-color:#fecaca;color:#b91c1c;}
.af-btn--pericol:hover{background:#fef2f2;}
.af-btn:focus-visible,.af-card:focus-visible,.af-plus:focus-visible,.af-alege button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px;}
.af-corp{flex:1;display:flex;min-height:0;}
.af-panza{flex:1;overflow:auto;position:relative;background-color:#f3f4f6;background-image:radial-gradient(#d1d5db 1px,transparent 1px);background-size:20px 20px;}
.af-probleme{position:sticky;top:12px;z-index:4;margin:12px auto 0;max-width:620px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:10px 14px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.06);}
.af-probleme[hidden]{display:none;}
.af-flux{display:flex;flex-direction:column;align-items:center;padding:36px 40px 80px;min-width:max-content;}
.af-linie{width:2px;height:20px;background:var(--af-linie);flex-shrink:0;}
.af-linie--lunga{height:28px;}
.af-card{width:340px;min-height:72px;display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border-radius:12px;cursor:pointer;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);font:inherit;text-align:left;color:inherit;box-sizing:border-box;}
.af-card:hover{border-color:#c7d2fe;}
.af-card[aria-pressed="true"]{border:2px solid var(--af-albastru);box-shadow:0 4px 14px rgba(29,78,216,.18);padding:11px 13px;}
.af-ramura .af-card{width:300px;}
.af-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-ic svg{width:20px;height:20px;}
.af-text{display:flex;flex-direction:column;flex:1;min-width:0;}
.af-tip{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
.af-titlu{font-size:15px;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.af-sub{font-size:12px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.af-insigna{font-size:12px;color:#6b7280;background:#f3f4f6;border-radius:999px;padding:4px 8px;white-space:nowrap;flex-shrink:0;}
.af-plus{width:32px;height:32px;border-radius:50%;border:2px solid var(--af-albastru);background:#fff;color:var(--af-albastru);font-size:18px;line-height:1;cursor:pointer;flex-shrink:0;padding:0;}
.af-plus:hover,.af-plus[aria-expanded="true"]{background:var(--af-albastru);color:#fff;}
.af-ramuri{display:flex;gap:80px;align-items:flex-start;}
.af-ramura{display:flex;flex-direction:column;align-items:center;}
.af-pastila{font-size:12px;font-weight:700;border-radius:999px;padding:3px 10px;}
.af-pastila--da{color:#065f46;background:#d1fae5;}
.af-pastila--nu{color:#9a3412;background:#ffedd5;}
.af-gata{width:260px;min-height:64px;border:1px dashed var(--af-linie);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#6b7280;background:rgba(255,255,255,.7);text-align:center;padding:0 12px;box-sizing:border-box;}
.af-capat{width:28px;height:28px;border-radius:50%;background:#6b7280;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.af-alege{position:absolute;z-index:6;width:520px;background:#fff;border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.16);padding:14px;display:flex;flex-direction:column;gap:12px;box-sizing:border-box;}
.af-alege[hidden]{display:none;}
.af-alege input[type=search]{height:42px;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;font:inherit;font-size:14px;}
.af-alege-grup{display:flex;flex-direction:column;gap:6px;}
.af-alege-grup > span{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;}
.af-alege-grila{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;}
.af-alege-grila button{min-height:52px;border:1px solid #e5e7eb;background:#fafafa;border-radius:10px;display:flex;align-items:center;gap:8px;padding:6px 10px;text-align:left;font:inherit;font-size:13px;cursor:pointer;color:#1f2937;}
.af-alege-grila button:hover{border-color:var(--af-albastru);background:#eff6ff;}
.af-alege-ic{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-alege-ic svg{width:15px;height:15px;}
.af-alege-gol{font-size:13px;color:#6b7280;}
.af-panou{width:360px;flex-shrink:0;background:#fff;border-left:1px solid #e5e7eb;display:flex;flex-direction:column;min-height:0;}
.af-panou-antet{padding:18px 20px 10px;border-bottom:1px solid #e5e7eb;display:flex;flex-direction:column;gap:2px;}
.af-panou-antet span{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.af-panou-antet strong{font-size:18px;font-weight:600;}
.af-panou-corp{flex:1;overflow:auto;padding:18px 20px;display:flex;flex-direction:column;gap:14px;overscroll-behavior:contain;}
.af-camp{font-size:13px;color:#4b5563;display:flex;flex-direction:column;gap:6px;}
.af-camp select,.af-camp input[type=text],.af-camp input[type=email],.af-camp input[type=number],.af-camp input[type=search],.af-camp textarea{height:44px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font:inherit;font-size:14px;color:#111827;background:#fff;box-sizing:border-box;width:100%;}
.af-camp textarea{height:96px;padding:8px 10px;resize:vertical;}
.af-rand{display:flex;gap:8px;align-items:flex-end;}
.af-rand > *{flex:1;}
.af-rand > .af-ingust{flex:0 0 90px;}
.af-bifa{display:flex;gap:10px;align-items:center;font-size:14px;min-height:40px;}
.af-bifa input{width:18px;height:18px;flex-shrink:0;}
.af-nota{font-size:12px;color:#6b7280;line-height:1.5;margin:0;}
.af-nota code{font-size:12px;background:#f3f4f6;padding:1px 4px;border-radius:4px;}
.af-caseta{font-size:12px;line-height:1.5;border-radius:10px;padding:10px 12px;background:#eff6ff;color:#1e3a8a;border:1px solid #dbeafe;}
.af-caseta--atentie{background:#fffbeb;color:#92400e;border-color:#fde68a;}
.af-lista-bife{max-height:200px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;display:flex;flex-direction:column;gap:2px;}
.af-lista-bife label{display:flex;gap:8px;align-items:center;font-size:13px;min-height:32px;color:#1f2937;}
.af-jetoane{display:flex;flex-wrap:wrap;gap:6px;}
.af-jeton{display:inline-flex;align-items:center;gap:6px;background:#eef2ff;color:#3730a3;border-radius:999px;padding:4px 6px 4px 10px;font-size:12px;}
.af-jeton button{border:none;background:transparent;color:inherit;cursor:pointer;font-size:14px;width:24px;height:24px;border-radius:50%;}
.af-jeton button:hover{background:#e0e7ff;}
.af-rezultate{display:flex;flex-direction:column;gap:4px;}
.af-rezultate button{text-align:left;border:1px solid #e5e7eb;background:#fff;border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;cursor:pointer;min-height:40px;}
.af-rezultate button:hover{border-color:var(--af-albastru);}
.af-miniatura{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;}
.af-miniatura-cadru{height:180px;overflow:hidden;position:relative;background:#f4f6f8;}
.af-miniatura-cadru iframe{width:680px;height:720px;border:0;transform:scale(.47);transform-origin:0 0;pointer-events:none;background:#fff;}
.af-miniatura-gol{height:120px;display:flex;align-items:center;justify-content:center;font-weight:600;color:#6b7280;background:#f9fafb;}
.af-miniatura-subiect{padding:10px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;}
.af-numere-pas{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.af-numere-pas span{display:flex;flex-direction:column;align-items:center;font-size:11px;color:#6b7280;background:#f9fafb;border-radius:8px;padding:8px 4px;text-align:center;}
.af-numere-pas strong{font-size:16px;color:#111827;font-variant-numeric:tabular-nums;}
.af-panou-subsol{padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;}
.af-panou-subsol .af-btn{height:40px;padding:0 12px;}
.af-mesaj{font-size:12px;min-height:16px;}
.af-mesaj[data-ton="ok"]{color:#166534;}
.af-mesaj[data-ton="eroare"]{color:#b91c1c;}
.af-vizibil-ascuns{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;}
@media (max-width:1100px){.af-numere{display:none;}.af-panou{width:320px;}}
@media (max-width:900px){.af-corp{flex-direction:column;}.af-panou{width:auto;border-left:0;border-top:1px solid #e5e7eb;max-height:50vh;}.af-alege{width:calc(100vw - 32px);max-width:520px;}}
</style>

<div class="af" id="af">
  <div class="af-bara">
    <a href="/admin/automatizari" class="af-inapoi" id="af-inapoi">← Automatizări</a>
    <label class="af-vizibil-ascuns" for="af-nume">Numele automatizării</label>
    <input type="text" class="af-nume" id="af-nume" maxlength="190" value="<?= adm_e((string) $date['nume']) ?>">
    <span class="af-stare" id="af-stare" data-stare="<?= adm_e((string) $date['stare']) ?>"><?= adm_e($stareEticheta) ?></span>
    <div class="af-spatiu"></div>
    <span class="af-salvare" id="af-salvare" data-stare="salvat" role="status" aria-live="polite">Salvat</span>
    <div class="af-numere" aria-label="Numărătorile automatizării">
      <span><strong id="af-nr-intrate"><?= (int) $date['numere']['intrate'] ?></strong>au intrat</span>
      <span><strong id="af-nr-curs"><?= (int) $date['numere']['in_procesare'] ?></strong>în curs</span>
      <span><strong id="af-nr-iesite"><?= (int) $date['numere']['iesite'] ?></strong>au terminat</span>
    </div>
    <button type="button" class="af-btn" id="af-porneste"><?= (string) $date['stare'] === 'activa' ? 'Oprește' : 'Pornește' ?></button>
    <button type="button" class="af-btn af-btn--principal" id="af-salveaza">Salvează</button>
  </div>
  <div class="af-corp">
    <main class="af-panza" id="af-panza" aria-label="Fluxul automatizării">
      <div class="af-probleme" id="af-probleme" role="status" hidden></div>
      <div class="af-flux" id="af-flux"></div>
      <div class="af-alege" id="af-alege" role="dialog" aria-label="Adaugă un pas" hidden>
        <input type="search" id="af-alege-cauta" aria-label="Caută un pas" placeholder="Caută un pas…" autocomplete="off">
        <div id="af-alege-grupuri"></div>
      </div>
    </main>
    <aside class="af-panou" aria-labelledby="af-panou-titlu">
      <div class="af-panou-antet">
        <span id="af-panou-tip">Declanșator</span>
        <strong id="af-panou-titlu">—</strong>
      </div>
      <div class="af-panou-corp" id="af-panou-corp"></div>
      <div class="af-panou-subsol" id="af-panou-subsol" hidden></div>
    </aside>
  </div>
</div>
<script type="application/json" id="af-date"><?= $autJson ?></script>
<script src="/assets/js/admin/automatizari-editor.js?v=<?= (int) $autJs ?>" defer></script>
