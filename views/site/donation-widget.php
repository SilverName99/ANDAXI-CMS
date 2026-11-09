<?php
/** @var array<string,mixed> $donationConfig */
$dc = is_array($donationConfig ?? null) ? $donationConfig : [];
$campTitle = (string) ($dc['campaign_title'] ?? 'Susține misiunea noastră!');
$campText = (string) ($dc['campaign_text'] ?? '');
$campImage = (string) ($dc['campaign_image'] ?? '');
$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!-- Sertar SUSȚINE -->
<button class="dw-tab" data-dw-open aria-label="Susține">
  <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 21s-6.7-4.3-9.3-8.1C.9 10.3 1.6 6.9 4.3 5.6c2-1 4.2-.3 5.4 1.3L12 9l2.3-2.1c1.2-1.6 3.4-2.3 5.4-1.3 2.7 1.3 3.4 4.7 1.6 7.3C18.7 16.7 12 21 12 21z"/></svg>
  <span>SUSȚINE</span>
</button>

<div class="dw-overlay" data-dw-overlay hidden>
  <div class="dw-dialog">
    <button class="dw-close" data-dw-close aria-label="Închide">&times;</button>

    <!-- PAS SUMĂ: două carduri -->
    <div class="dw-amount-view" data-dw-view="amount">
      <div class="dw-card dw-media">
        <?php if ($campImage !== ''): ?><img src="<?= $esc($campImage) ?>" alt="<?= $esc($campTitle) ?>"><?php endif; ?>
        <div class="dw-media-body">
          <h3><?= $esc($campTitle) ?></h3>
          <?php if ($campText !== ''): ?><p><?= nl2br($esc($campText)) ?></p><?php endif; ?>
          <p class="dw-media-thanks">În numele nostru, îți mulțumim pentru orice contribuție!</p>
        </div>
      </div>

      <div class="dw-card dw-form">
        <div class="dw-head">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
          <span>Donație securizată</span>
        </div>
        <div class="dw-toggle" data-dw-freq-wrap>
          <button type="button" class="dw-freq is-active" data-freq="once">O singură dată</button>
          <button type="button" class="dw-freq" data-freq="monthly">❤ Lunar</button>
        </div>
        <div class="dw-amounts" data-dw-amounts></div>
        <div class="dw-other">
          <input type="number" min="1" step="1" placeholder="Altă sumă" data-dw-other>
          <span class="dw-cur" data-dw-cur>RON</span>
        </div>
        <label class="dw-lbl">Destinație</label>
        <select class="dw-select" data-dw-designation></select>
        <button type="button" class="dw-primary" data-dw-to-details>Continuă</button>
      </div>
    </div>

    <!-- PAȘII următori: card unic -->
    <div class="dw-wizard" data-dw-view="wizard" hidden>
      <div class="dw-card dw-wizard-card">
        <div class="dw-wizard-head">
          <button type="button" class="dw-back" data-dw-back aria-label="Înapoi">‹</button>
          <span data-dw-step-title>Datele tale</span>
        </div>

        <!-- Upsell lunar -->
        <div class="dw-step" data-step="upsell" hidden>
          <p class="dw-upsell-text">Vrei să transformi contribuția de <strong data-dw-upsell-amount></strong> într-o donație lunară? Sprijinul constant ne ajută să ne concentrăm mai bine pe misiunea noastră.</p>
          <button type="button" class="dw-primary dw-primary--pink" data-dw-upsell-yes>❤ Donează <span data-dw-upsell-amount2></span>/lună</button>
          <button type="button" class="dw-link-btn" data-dw-upsell-no>Nu, păstrează donația unică</button>
        </div>

        <!-- Detalii -->
        <div class="dw-step" data-step="details" hidden>
          <div class="dw-fields">
            <input type="text" placeholder="Prenume" data-dw-first>
            <input type="text" placeholder="Nume" data-dw-last>
          </div>
          <input type="email" placeholder="Adresă de email" data-dw-email>
          <label class="dw-check"><input type="checkbox" data-dw-newsletter><span>Abonează-te la newsletter</span></label>
          <label class="dw-check"><input type="checkbox" data-dw-consent><span>Sunt de acord cu <a href="/politica-de-confidentialitate" target="_blank">Politica de confidențialitate</a></span></label>
          <p class="dw-err" data-dw-details-err hidden></p>
          <button type="button" class="dw-primary" data-dw-to-payment>Continuă</button>
        </div>

        <!-- Plată -->
        <div class="dw-step" data-step="payment" hidden>
          <div class="dw-pay-methods-note">Alege metoda de plată</div>
          <div class="dw-payment-element" data-dw-payment-element></div>
          <label class="dw-check dw-cover"><input type="checkbox" data-dw-cover><span>Acoperă comisioanele de tranzacție</span></label>
          <div class="dw-total"><span>Total</span><strong data-dw-total>0</strong></div>
          <p class="dw-err" data-dw-pay-err hidden></p>
          <button type="button" class="dw-primary dw-primary--pink" data-dw-donate>Donează</button>
        </div>

        <!-- Mulțumire -->
        <div class="dw-step dw-thanks" data-step="thanks" hidden>
          <div class="dw-thanks-icon">❤</div>
          <h3>Îți mulțumim!</h3>
          <p data-dw-thanks-text></p>
          <button type="button" class="dw-primary" data-dw-close>Închide</button>
        </div>
      </div>
    </div>

    <!-- Linkuri jos -->
    <div class="dw-links">
      <div class="dw-link-wrap">
        <button type="button" class="dw-link" data-dw-pop>Donația mea este sigură?</button>
        <div class="dw-pop" hidden>
          <strong>Donația mea este sigură?</strong>
          <p>Da, folosim tehnologie SSL standard pentru a-ți proteja informațiile. Plățile sunt procesate de Stripe, folosit de unele dintre cele mai mari companii din lume.</p>
          <p>Datele tale de card nu ajung niciodată pe serverele noastre — sunt trimise direct, criptat, către serverele PCI-conforme ale Stripe.</p>
        </div>
      </div>
      <span class="dw-links-sep">·</span>
      <div class="dw-link-wrap">
        <button type="button" class="dw-link" data-dw-pop>Pot anula donația recurentă?</button>
        <div class="dw-pop" hidden>
          <strong>Pot anula donația recurentă?</strong>
          <p>Desigur. Rămâi mereu în control deplin asupra donației lunare și o poți modifica sau anula oricând, contactându-ne.</p>
        </div>
      </div>
      <span class="dw-links-sep">·</span>
      <div class="dw-link-wrap">
        <button type="button" class="dw-link" data-dw-pop>Raportează o problemă</button>
        <div class="dw-pop dw-pop--form" hidden>
          <strong>Raportează o problemă tehnică</strong>
          <p class="dw-pop-sub">Descrie pasul la care ai ajuns și ce nu a funcționat.</p>
          <textarea maxlength="500" data-dw-report placeholder="Ce nu a funcționat?"></textarea>
          <label class="dw-check"><input type="checkbox" data-dw-report-ok><span>Confirm că nu am inclus date personale sau de plată.</span></label>
          <div class="dw-report-actions">
            <button type="button" class="dw-report-send" data-dw-report-send disabled>Trimite</button>
            <button type="button" class="dw-report-cancel" data-dw-pop-close>Anulează</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.dw-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:9998;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;background:linear-gradient(160deg,#ea4968,#c13c93);color:#fff;border:0;padding:18px 10px;border-radius:14px 0 0 14px;box-shadow:-6px 8px 22px rgba(193,60,147,.35);transition:padding-right .15s ease;}
.dw-tab:hover{padding-right:16px;}
.dw-tab span{writing-mode:vertical-rl;transform:rotate(180deg);font-weight:800;letter-spacing:.12em;font-size:14px;}
body.dw-open .dw-tab{opacity:0;pointer-events:none;}
.dw-overlay{position:fixed;inset:0;z-index:9999;background:rgba(20,15,25,.55);display:flex;align-items:center;justify-content:center;padding:24px 20px;overflow:auto;}
.dw-overlay[hidden]{display:none;}
.dw-dialog{position:relative;width:min(900px,100%);}
.dw-close{position:absolute;top:-6px;right:-6px;z-index:5;width:34px;height:34px;border-radius:50%;border:0;background:#fff;color:#333;font-size:22px;line-height:1;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.2);}
.dw-amount-view{display:flex;gap:18px;align-items:stretch;}
.dw-amount-view[hidden],.dw-wizard[hidden],.dw-step[hidden],.dw-pop[hidden]{display:none !important;}
.dw-card{background:#fff;border-radius:22px !important;overflow:hidden;box-shadow:0 26px 60px rgba(0,0,0,.28);}
.dw-media{flex:0 0 42%;display:flex;flex-direction:column;}
.dw-media img{width:100%;height:230px;object-fit:cover;display:block;}
.dw-media-body{padding:20px 22px;}
.dw-media-body h3{margin:0 0 10px;font-family:"Playfair Display",serif;font-size:20px;color:#15202b;}
.dw-media-body p{margin:0 0 10px;color:#4b5563;font-size:14px;line-height:1.6;}
.dw-media-thanks{font-style:italic;color:#c13c93;}
.dw-form{flex:1;padding:24px;display:flex;flex-direction:column;gap:12px;min-width:0;}
.dw-head{display:flex;align-items:center;gap:8px;font-weight:800;font-size:18px;color:#15202b;}
.dw-toggle{display:flex;gap:8px;background:#f3f4f6;padding:5px;border-radius:12px;}
.dw-freq{flex:1;border:0;background:transparent;padding:10px;border-radius:9px;font-weight:700;color:#6b7280;cursor:pointer;}
.dw-freq.is-active{background:#fff;color:#ea4968;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.dw-amounts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.dw-amt{border:1.5px solid #f0d7de;background:#fff;padding:12px 6px;border-radius:12px;font-weight:700;color:#15202b;cursor:pointer;transition:.12s;}
.dw-amt:hover{border-color:#ea4968;}
.dw-amt.is-active{border-color:#ea4968;background:#fdeef2;color:#ea4968;box-shadow:0 0 0 3px rgba(234,73,104,.12);}
.dw-other{display:flex;align-items:center;border:1.5px solid #f0d7de;border-radius:12px;overflow:hidden;}
.dw-other input{flex:1;border:0;padding:13px 14px;font-size:15px;outline:none;}
.dw-cur{padding:0 14px;color:#6b7280;font-weight:700;}
.dw-lbl{font-size:12px;color:#9ca3af;margin-bottom:-6px;}
.dw-select{border:1.5px solid #f0d7de;border-radius:12px;padding:12px 14px;font-size:15px;background:#fff;}
.dw-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.dw-fields input,.dw-step input[type=email]{border:1.5px solid #f0d7de;border-radius:12px;padding:12px 14px;font-size:15px;outline:none;width:100%;box-sizing:border-box;}
.dw-primary{background:#2f6fed;color:#fff;border:0;padding:15px;border-radius:12px;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 10px 22px rgba(47,111,237,.24);}
.dw-primary:hover{filter:brightness(1.05);}
.dw-primary:disabled{opacity:.6;cursor:not-allowed;}
.dw-primary--pink{background:linear-gradient(90deg,#ea4968,#c13c93);box-shadow:0 10px 22px rgba(193,60,147,.3);}
.dw-wizard{max-width:460px;margin:0 auto;}
.dw-wizard-card{padding:22px 24px;display:flex;flex-direction:column;}
.dw-wizard-head{display:flex;align-items:center;gap:10px;justify-content:center;position:relative;border-bottom:1px solid #eee;padding-bottom:14px;margin-bottom:16px;font-weight:800;color:#15202b;}
.dw-back{position:absolute;left:0;background:none;border:0;font-size:26px;line-height:1;color:#111;cursor:pointer;padding:0 6px;}
.dw-step{display:flex;flex-direction:column;gap:12px;}
.dw-upsell-text{text-align:center;color:#4b5563;line-height:1.6;margin:14px 0 24px;}
.dw-link-btn{background:none;border:0;color:#374151;text-decoration:underline;cursor:pointer;font-size:14px;}
.dw-check{display:flex;gap:9px;align-items:flex-start;font-size:14px;color:#374151;line-height:1.4;cursor:pointer;}
.dw-check input{margin-top:2px;}
.dw-cover{margin-top:4px;}
.dw-pay-methods-note{font-size:13px;color:#6b7280;font-weight:600;}
.dw-payment-element{min-height:40px;}
.dw-total{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #eee;padding-top:12px;font-size:15px;color:#15202b;}
.dw-total strong{font-size:18px;}
.dw-err{color:#dc2626;font-size:13px;margin:0;}
.dw-thanks{align-items:center;text-align:center;padding:34px 10px;}
.dw-thanks-icon{width:64px;height:64px;border-radius:50%;background:#fdeef2;color:#ea4968;font-size:30px;display:flex;align-items:center;justify-content:center;}
.dw-thanks h3{margin:0;font-family:"Playfair Display",serif;font-size:24px;color:#15202b;}
.dw-thanks p{margin:0;color:#4b5563;}
.dw-links{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:16px;}
.dw-links-sep{color:rgba(255,255,255,.5);}
.dw-link-wrap{position:relative;}
.dw-link{background:none;border:0;color:rgba(255,255,255,.85);font-size:13px;font-weight:600;cursor:pointer;padding:4px 2px;}
.dw-link:hover{color:#fff;text-decoration:underline;}
.dw-pop{position:absolute;bottom:calc(100% + 12px);left:50%;transform:translateX(-50%);width:290px;max-width:80vw;background:#fff;border-radius:14px;padding:16px 18px;text-align:left;box-shadow:0 18px 44px rgba(0,0,0,.25);z-index:6;}
.dw-pop::after{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);border:8px solid transparent;border-top-color:#fff;}
.dw-pop strong{display:block;margin-bottom:8px;color:#15202b;font-size:14px;}
.dw-pop p{margin:0 0 8px;color:#4b5563;font-size:13px;line-height:1.55;}
.dw-pop p:last-child{margin-bottom:0;}
.dw-pop-sub{color:#c13c93 !important;}
.dw-pop--form textarea{width:100%;min-height:88px;border:1.5px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:13px;resize:vertical;outline:none;box-sizing:border-box;}
.dw-report-actions{display:flex;gap:8px;margin-top:10px;}
.dw-report-send{background:#2f6fed;color:#fff;border:0;padding:9px 16px;border-radius:9px;font-weight:700;cursor:pointer;}
.dw-report-send:disabled{opacity:.5;cursor:not-allowed;}
.dw-report-cancel{background:#fff;border:1.5px solid #e5e7eb;color:#374151;padding:9px 16px;border-radius:9px;font-weight:700;cursor:pointer;}
@media(max-width:720px){.dw-amount-view{flex-direction:column;}.dw-media{flex-basis:auto;}.dw-media img{height:150px;}.dw-fields{grid-template-columns:1fr;}}

/* Mod Exemplu (demo) */
.dw-demo-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);z-index:6;background:#f59e0b;color:#fff;font-size:11px;font-weight:800;letter-spacing:.1em;padding:5px 14px;border-radius:999px;box-shadow:0 6px 16px rgba(245,158,11,.4);white-space:nowrap;}
.dw-demo-note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12.5px;font-weight:600;text-align:center;padding:8px 10px;border-radius:10px;margin-bottom:12px;}
.dw-demo-tabs{display:flex;gap:8px;margin-bottom:12px;}
.dw-demo-tab{flex:1;border:1.5px solid #e5e7eb;background:#fff;padding:11px;border-radius:10px;font-weight:600;color:#374151;cursor:pointer;}
.dw-demo-tab.is-active{border-color:#2f6fed;box-shadow:0 0 0 3px rgba(47,111,237,.12);}
.dw-demo-fields{display:flex;flex-direction:column;gap:10px;}
.dw-demo-fields input{border:1.5px solid #e5e7eb;border-radius:10px;padding:13px 14px;font-size:15px;color:#6b7280;background:#f9fafb;}
.dw-demo-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
</style>
