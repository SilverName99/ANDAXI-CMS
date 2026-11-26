<?php
/**
 * Builderul emailurilor (newslettere, șabloanele lor, emailurile comenzilor), varianta „C – clasic, curățat” aprobată pe
 * 26 nov 2026: bara de sus (Înapoi, numele, starea, Anulează / Refă, Editez / Desktop / Mobil, Salvează, Continuă), paleta
 * din stânga (Magazin, Conținut, Așezare, cu căutare), emailul în mijloc exact cum pleacă (blocurile lipite, fără spații
 * gri între ele; blocul ales are chenar, eticheta lui și ↑ ↓ Duplică Șterge), panoul din dreapta cu Conținut / Aspect /
 * Afișare. Desktop / Mobil arată emailul randat de server (aceeași cale ca trimiterea), într-un cadru izolat nou la
 * fiecare clic, la 680 / 375 px (680 = lățimea emailului; sub 641 px emailul trece singur pe mărimile de telefon). Textele și titlurile se scriu direct pe pânză (bara mică B / I / U / link / culoare).
 * Formatul blocurilor (blocks_json) e același: NewsletterService::normalizeBlocks / renderHtmlFromBlocks.
 *
 * @var array $builder
 */
$bType          = $builder['type'] ?? 'template';
$bId            = (int) ($builder['id'] ?? 0);
$bRef           = (string) ($builder['ref'] ?? '');
$bName          = (string) ($builder['name'] ?? '');
$bSubject       = (string) ($builder['subject'] ?? '');
$bBlocks        = $builder['blocks'] ?? [];
$bIsActive      = (bool) ($builder['is_active'] ?? true);
$bBackUrl       = (string) ($builder['back_url'] ?? '/admin/emails/newsletters');
$bGallery       = $builder['gallery_images'] ?? [];
$bEcommerceMeta = $builder['ecommerce_meta'] ?? null;
$bRecipientMode = (string) ($builder['recipient_mode'] ?? 'client');
$bAdminRecip    = (string) ($builder['admin_recipients'] ?? '');
$bTokens        = $builder['available_tokens'] ?? [];
$bBlocksJson     = json_encode($bBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
$bShowStarter    = empty($bBlocks);
$bNewsletterLists = $builder['newsletter_lists'] ?? [];
$bListId         = (int) ($builder['list_id'] ?? 0);
$bListIds        = $builder['list_ids'] ?? ($bListId > 0 ? [$bListId] : []);
$bCampaignStatus = (string) ($builder['status'] ?? 'draft');
// Șabloanele cu înfățișarea magazinului (NewsletterSabloane) și datele pașilor
// newsletterului (Destinatari, Verificare, Trimite / Programează).
$bSabloane       = is_array($builder['sabloane'] ?? null) ? $builder['sabloane'] : [];
$bPoateModifica  = $bType !== 'campaign' || (bool) ($builder['poate_modifica'] ?? true);
$bPasInitial     = $bType === 'campaign' ? max(1, min(4, (int) ($_GET['pas'] ?? ($bPoateModifica ? 1 : 4)))) : 1;
$bCuCatalog      = (bool) ($builder['cu_catalog'] ?? false);
$bCuMagazin      = (bool) ($builder['cu_magazin'] ?? false);
$bDateSubsol     = is_array($builder['date_subsol'] ?? null) ? $builder['date_subsol'] : ['lipsa' => []];
$bLipsaFirma     = array_values((array) ($bDateSubsol['lipsa'] ?? []));
// Salvarea automată: doar unde nu pleacă nimic la clienți cât se lucrează (o ciornă de newsletter, un șablon de newsletter
// deja creat). Emailurile comenzilor sunt active pe loc, deci se salvează doar cu „Salvează”.
$bSalvareAutomata = $bPoateModifica && (($bType === 'campaign' && $bCampaignStatus === 'draft') || ($bType === 'template' && $bId > 0));
require_once __DIR__ . '/../componente/_incarca.php';
$adminCssNl      = @filemtime(\App\Support\Paths::public('assets/css/admin-newsletter.css')) ?: 1;
$adminJsNl       = @filemtime(\App\Support\Paths::public('assets/js/admin/newsletter-pasi.js')) ?: 1;
$ebJson = static fn ($v): string => (string) (json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null');
$stareCampanie = \App\Support\NewsletterService::STARI[$bCampaignStatus] ?? $bCampaignStatus;
$clasaStare = ['scheduled' => 'programat', 'sending' => 'se-trimite', 'paused' => 'oprit', 'sent' => 'trimis'][$bCampaignStatus] ?? 'ciorna';
$eticheteStareBara = $bType === 'campaign' ? $stareCampanie : ($bType === 'ecommerce' ? 'Email comandă' : 'Șablon');

// Iconițele paletei (machetă, desen propriu): fiecare bloc are un SVG 24×24 cu linie de 1.8.
$ebIcon = static function (string $cheie): string {
    $desen = [
        'logo' => '<rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M8 15l2.5-6 2.5 6"></path><path d="M9 13h3"></path><path d="M15.5 9v6"></path>',
        'menu' => '<rect x="3" y="4" width="18" height="16" rx="3"></rect><path d="M7 9h10"></path><path d="M7 12h10"></path><path d="M7 15h6"></path>',
        'products' => '<path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8z"></path><path d="M9 8V6.5a3 3 0 0 1 6 0V8"></path>',
        'coupon' => '<path d="M3 9V7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2a3 3 0 0 0 0 6v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a3 3 0 0 0 0-6z"></path><path d="M9.5 14.5l5-5"></path><circle cx="10" cy="10" r=".6"></circle><circle cx="14" cy="14" r=".6"></circle>',
        'header' => '<path d="M5 5v14"></path><path d="M13 5v14"></path><path d="M5 12h8"></path><path d="M17 10l2-1.5V19"></path>',
        'text' => '<path d="M4 6h16"></path><path d="M4 10h16"></path><path d="M4 14h16"></path><path d="M4 18h10"></path>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="3"></rect><circle cx="9" cy="9.5" r="1.8"></circle><path d="M21 16l-5-5-9 9"></path>',
        'button' => '<rect x="2.5" y="6" width="17" height="9" rx="4.5"></rect><path d="M14 13l6.5 2.5-2.7 1.1-1.1 2.7z"></path>',
        'video' => '<rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M10 9.5v5l4.5-2.5z"></path>',
        'quote' => '<path d="M9 7H6a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h3v1a3 3 0 0 1-3 3"></path><path d="M19 7h-3a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h3v1a3 3 0 0 1-3 3"></path>',
        'columns_2' => '<rect x="3" y="5" width="8" height="14" rx="2"></rect><rect x="13" y="5" width="8" height="14" rx="2"></rect>',
        'columns_3' => '<rect x="2.5" y="5" width="5.3" height="14" rx="1.6"></rect><rect x="9.35" y="5" width="5.3" height="14" rx="1.6"></rect><rect x="16.2" y="5" width="5.3" height="14" rx="1.6"></rect>',
        'divider' => '<path d="M3 12h18"></path><path d="M7 7h10"></path><path d="M7 17h10"></path>',
        'spacer' => '<path d="M4 4h16"></path><path d="M4 20h16"></path><path d="M12 7v10"></path><path d="M9 9.5L12 7l3 2.5"></path><path d="M9 14.5l3 2.5 3-2.5"></path>',
        'columns_2_6040' => '<rect x="3" y="5" width="10" height="14" rx="2"></rect><rect x="15" y="5" width="6" height="14" rx="2"></rect>',
        'columns_2_4060' => '<rect x="3" y="5" width="6" height="14" rx="2"></rect><rect x="11" y="5" width="10" height="14" rx="2"></rect>',
        'columns_2_7030' => '<rect x="3" y="5" width="12" height="14" rx="2"></rect><rect x="17" y="5" width="4" height="14" rx="2"></rect>',
        'columns_2_3070' => '<rect x="3" y="5" width="4" height="14" rx="2"></rect><rect x="9" y="5" width="12" height="14" rx="2"></rect>',
        'social' => '<circle cx="6" cy="12" r="2.5"></circle><circle cx="18" cy="6" r="2.5"></circle><circle cx="18" cy="18" r="2.5"></circle><path d="M8.3 10.8l7.4-3.6"></path><path d="M8.3 13.2l7.4 3.6"></path>',
    ][$cheie] ?? '<rect x="4" y="4" width="16" height="16" rx="3"></rect>';
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $desen . '</svg>';
};
// Grupurile paletei: [tip, eticheta, cuvinte pentru căutare]. „Mai mult” păstrează blocurile vechi (secțiunile 60/40…, social).
$ebGrupuri = [
    ['cheie' => 'magazin', 'titlu' => 'Magazin', 'blocuri' => array_values(array_filter([
        ['logo', 'Logo', 'sigla antet'],
        ['menu', 'Meniu', 'categorii linkuri navigare'],
        $bCuCatalog ? ['products', 'Produse', 'produs recomandate vandute noi reduse'] : null,
        $bCuMagazin ? ['coupon', 'Cod reducere', 'cupon voucher discount'] : null,
    ]))],
    ['cheie' => 'continut', 'titlu' => 'Conținut', 'blocuri' => [
        ['header', 'Titlu', 'header antet titlul'],
        ['text', 'Text', 'paragraf scris'],
        ['image', 'Imagine', 'poza foto banner'],
        ['button', 'Buton', 'link apasa'],
        ['video', 'Video', 'tiktok youtube vimeo'],
        ['quote', 'Citat', 'parere client'],
    ]],
    ['cheie' => 'asezare', 'titlu' => 'Așezare', 'blocuri' => [
        ['columns_2', '2 coloane', 'sectiune 50'],
        ['columns_3', '3 coloane', 'sectiune 33'],
        ['divider', 'Separator', 'linie'],
        ['spacer', 'Spațiu', 'gol distanta'],
    ]],
];
$ebMaiMult = [
    ['columns_2_6040', 'Coloane 60 / 40', 'sectiune'],
    ['columns_2_4060', 'Coloane 40 / 60', 'sectiune'],
    ['columns_2_7030', 'Coloane 70 / 30', 'sectiune'],
    ['columns_2_3070', 'Coloane 30 / 70', 'sectiune'],
    ['social', 'Social media', 'facebook instagram retele'],
];
?>
<?php // Stilul și scriptul componentelor le încarcă views/admin/layout.php (WP-31: admin.css, js/admin/componente.js). ?>
<link rel="stylesheet" href="/assets/css/admin-newsletter.css?v=<?= (int) $adminCssNl ?>">
<style>
/* ── Builderul emailurilor, varianta C ───────────────────────────────── */
.eb-wrap{--eb-albastru:var(--ax-accent,#1d4ed8);display:flex;flex-direction:column;height:calc(100vh - 52px);margin:-8px -16px -24px;overflow:hidden;background:#f3f4f6;color:#1f2937;}
.eb-topbar{height:60px;flex-shrink:0;display:flex;align-items:center;gap:12px;padding:0 16px;background:#fff;border-bottom:1px solid #e5e7eb;}
.eb-back{text-decoration:none;color:#4b5563;font-size:14px;padding:8px 6px;border-radius:8px;white-space:nowrap;}
.eb-back:hover{background:#f3f4f6;color:#111827;}
.eb-title{display:flex;align-items:center;gap:10px;min-width:0;}
.eb-title strong{font-size:16px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px;}
.eb-insigna{font-size:12px;padding:4px 8px;border-radius:999px;background:#f3f4f6;color:#4b5563;white-space:nowrap;}
.eb-spatiu{flex:1;}
.eb-stare-salvare{font-size:12px;color:#6b7280;white-space:nowrap;display:flex;align-items:center;gap:6px;}
.eb-stare-salvare::before{content:"";width:8px;height:8px;border-radius:50%;background:#16a34a;}
.eb-stare-salvare[data-stare="nesalvat"]::before{background:#f59e0b;}
.eb-stare-salvare[data-stare="salveaza"]::before{background:#3b82f6;}
.eb-stare-salvare[data-stare="eroare"]::before{background:#dc2626;}
.eb-stare-salvare[data-stare="eroare"]{color:#b91c1c;}
.eb-dirty-dot{display:none;}
.eb-history-btns{display:flex;gap:6px;}
.eb-history-btns button,.eb-btn-icon{width:44px;height:44px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;cursor:pointer;font-size:18px;color:#374151;}
.eb-history-btns button:disabled{opacity:.4;cursor:default;}
.eb-history-btns button:not(:disabled):hover{background:#f9fafb;}
.eb-preview-tabs{display:flex;background:#f3f4f6;border-radius:10px;padding:4px;gap:4px;}
.eb-preview-tabs button{height:36px;padding:0 14px;border:none;border-radius:8px;background:transparent;color:#4b5563;font-size:13px;cursor:pointer;}
.eb-preview-tabs button.active{background:#fff;color:#111827;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.12);}
.eb-btn-salveaza{height:44px;padding:0 16px;border:1px solid #d1d5db;background:#fff;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;color:#1f2937;}
.eb-btn-salveaza:hover{background:#f9fafb;}
.eb-btn-continua{height:44px;padding:0 18px;border:none;background:var(--eb-albastru);color:var(--ax-accent-text,#fff);border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;}
.eb-wrap button:focus-visible,.eb-wrap a:focus-visible,.eb-wrap input:focus-visible,.eb-wrap select:focus-visible,.eb-wrap textarea:focus-visible,.eb-wrap [tabindex]:focus-visible{outline:2px solid var(--eb-albastru);outline-offset:2px;}
.eb-avertisment{margin:0;padding:8px 16px;background:#fffbeb;border-bottom:1px solid #fde68a;color:#92400e;font-size:13px;}
.eb-avertisment a{color:#92400e;font-weight:600;}

.eb-body{flex:1;display:flex;min-height:0;}
/* Paleta */
.eb-palette{width:260px;flex-shrink:0;background:#fff;border-right:1px solid #e5e7eb;overflow:auto;overscroll-behavior:contain;padding:16px;box-sizing:border-box;display:flex;flex-direction:column;gap:18px;}
.eb-palette .eb-cauta-bloc{flex-shrink:0;height:40px;min-height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;font-size:13px;width:100%;box-sizing:border-box;}
.eb-grup{display:flex;flex-direction:column;gap:8px;}
.eb-grup[hidden]{display:none;}
.eb-grup-titlu{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin:0;}
.eb-grila{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
.eb-block-btn{height:78px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;font-size:12px;font-weight:500;color:#374151;cursor:grab;padding:4px;text-align:center;line-height:1.2;}
.eb-block-btn:hover{border-color:#c7d2fe;background:#fafaff;}
.eb-block-btn[hidden]{display:none;}
.eb-block-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.eb-grup--magazin .eb-block-icon{background:#ecfdf3;color:#15803d;}
.eb-grup--continut .eb-block-icon{background:#eef4ff;color:#1d4ed8;}
.eb-grup--asezare .eb-block-icon,.eb-mai-mult .eb-block-icon{background:#f4f4f5;color:#52525b;}
.eb-mai-mult summary{font-size:12px;color:#4b5563;cursor:pointer;padding:6px 0;}
.eb-mai-mult .eb-grila{margin-top:8px;}
.eb-sablon-btn{height:40px;border:1px dashed #c7d2fe;background:#f8faff;border-radius:10px;font-size:13px;color:#1e40af;cursor:pointer;}

/* Pânza: emailul exact cum pleacă (680 px, lățimea emailului deschis pe calculator), blocurile lipite */
.eb-center{flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden;}
.eb-canvas-scroll{position:relative;flex:1;overflow:auto;overscroll-behavior:contain;padding:20px 28px 40px;}
.eb-canvas-scroll[hidden]{display:none;}
.eb-panza-interior{width:680px;margin:0 auto;}
.eb-subiect{display:flex;align-items:center;gap:8px;width:100%;margin:0 0 12px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:13px;color:#4b5563;cursor:pointer;text-align:left;box-sizing:border-box;}
.eb-subiect strong{color:#111827;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eb-subiect .eb-subiect-gol{color:#b45309;}
.eb-email{position:relative;width:678px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);font-family:Arial,sans-serif;color:#1f2937;line-height:normal;font-size:16px;}
/* Ce face clientul de email (stilurile browserului), nu stilurile adminului */
.eb-email,.eb-email *,.eb-subsol-email,.eb-subsol-email *{box-sizing:content-box;}
.eb-email a,.eb-subsol-email a{color:revert;}
.eb-email p,.eb-subsol-email p{margin:revert;}
.eb-email img{max-width:revert;}
.eb-email table{font-size:inherit;}
.eb-bloc{position:relative;outline:0 solid transparent;}
.eb-bloc:hover{outline:1px dashed #93c5fd;outline-offset:-1px;z-index:1;}
.eb-bloc.is-sel{outline:2px solid var(--eb-albastru);outline-offset:-2px;z-index:2;}
.eb-bloc:focus-visible{outline:2px solid var(--eb-albastru);outline-offset:-2px;}
.eb-bloc-gol{padding:22px 16px;text-align:center;color:#6b7280;font-size:13px;font-family:inherit;background:repeating-linear-gradient(45deg,#fafafa,#fafafa 8px,#f3f4f6 8px,#f3f4f6 16px);}
.eb-eticheta{position:absolute;top:0;left:0;z-index:5;background:var(--eb-albastru);color:var(--ax-accent-text,#fff);font:600 11px/1.4 var(--ax-font,system-ui,sans-serif);padding:3px 8px;border-radius:0 0 6px 0;pointer-events:none;letter-spacing:0;}
.eb-sub > .eb-eticheta{border-radius:0 0 6px 0;font-size:10.5px;padding:2px 6px;}
.eb-unelte{position:absolute;top:4px;right:4px;z-index:6;display:flex;gap:4px;font:12px/1 var(--ax-font,system-ui,sans-serif);}
.eb-unelte button{background:#fff;border:1px solid #d1d5db;border-radius:6px;font-size:11.5px;padding:4px 7px;min-height:26px;cursor:pointer;color:#111827;box-shadow:0 1px 2px rgba(0,0,0,.08);}
.eb-unelte button:disabled{opacity:.4;cursor:default;}
.eb-unelte button.eb-sterge{border-color:#fecaca;color:#b91c1c;}
.eb-unelte .eb-trage{cursor:grab;}
.eb-unelte--sub{top:auto;bottom:-30px;right:0;}
.eb-sub.is-sel{z-index:7;}
.eb-sub{position:relative;}
.eb-sub:hover{outline:1px dashed #93c5fd;outline-offset:-1px;}
.eb-sub.is-sel{outline:2px solid var(--eb-albastru);outline-offset:-2px;}
.eb-col-cell{min-height:56px;}
.eb-col-gol{display:block;padding:16px 6px;margin:2px;border:1px dashed #cbd5e1;border-radius:6px;color:#94a3b8;font:12px/1.4 var(--ax-font,system-ui,sans-serif);text-align:center;}
.eb-col-cell.col-drag-over{outline:2px dashed #34d399;outline-offset:-2px;background:rgba(16,185,129,.06);}
.eb-indicator{position:absolute;left:0;right:0;height:4px;margin-top:-2px;background:var(--eb-albastru);border-radius:2px;z-index:8;pointer-events:none;display:none;box-shadow:0 0 0 2px rgba(255,255,255,.8);}
.eb-email.is-drag-fisier{outline:2px dashed var(--eb-albastru);outline-offset:4px;}
[data-edit][contenteditable="true"]{cursor:text;}
[data-edit][contenteditable="true"]:focus{outline:none;box-shadow:inset 0 0 0 2px rgba(29,78,216,.25);}
.eb-subsol-email{position:relative;margin-top:0;border-radius:10px;cursor:pointer;}
.eb-subsol-email.is-sel{outline:2px solid var(--eb-albastru);outline-offset:2px;}
.eb-subsol-email:hover{outline:1px dashed #93c5fd;outline-offset:2px;}
.eb-empty-state{padding:40px 20px;text-align:center;color:#6b7280;font:13px/1.6 var(--ax-font,system-ui,sans-serif);}
/* Bara de text de pe pânză */
.eb-bara-text{position:absolute;z-index:30;display:flex;gap:4px;padding:4px;background:#111827;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.25);}
.eb-bara-text[hidden]{display:none;}
.eb-bara-text button,.eb-bara-text label{min-width:32px;height:32px;border:none;border-radius:6px;background:transparent;color:#fff;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;}
.eb-bara-text button:hover,.eb-bara-text label:hover{background:#374151;}
.eb-bara-text input[type=color]{width:20px;height:20px;padding:0;border:0;background:none;cursor:pointer;}

/* Previzualizarea (Desktop 680 / Mobil 375): emailul randat de server, în cadru izolat */
.eb-preview-pane{flex:1;overflow:auto;overscroll-behavior:contain;padding:20px 28px 40px;display:flex;justify-content:flex-start;align-items:flex-start;}
.eb-preview-pane[hidden]{display:none;}
.eb-preview-frame{flex-shrink:0;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);}
.eb-preview-frame.mobile-preview{border-radius:20px;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.eb-preview-frame iframe{display:block;width:100%;min-height:500px;border:none;}
.eb-preview-loading{padding:48px;text-align:center;color:#6b7280;font-size:13px;}

/* Panoul din dreapta */
.eb-props{width:340px;flex-shrink:0;background:#fff;border-left:1px solid #e5e7eb;display:flex;flex-direction:column;min-height:0;}
.eb-props-antet{padding:16px 18px 0;display:flex;flex-direction:column;gap:12px;}
.eb-props-titlu{font-size:17px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px;}
.eb-props-titlu button{margin-left:auto;font-size:12px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;color:#374151;}
.eb-taburi{display:flex;border-bottom:1px solid #e5e7eb;}
.eb-taburi[hidden]{display:none;}
.eb-taburi button{height:40px;padding:0 12px;border:none;border-bottom:2px solid transparent;background:transparent;color:#4b5563;font-size:13px;cursor:pointer;}
.eb-taburi button[aria-selected="true"]{border-bottom-color:var(--eb-albastru);color:var(--eb-albastru);font-weight:600;}
.eb-props-body{flex:1;overflow:auto;overscroll-behavior:contain;padding:16px 18px 28px;display:flex;flex-direction:column;gap:14px;}
.eb-props-body[hidden]{display:none;}
.eb-camp{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#4b5563;}
.eb-camp > span{font-size:13px;color:#4b5563;}
.eb-camp input[type=text],.eb-camp input[type=url],.eb-camp input[type=number],.eb-camp input[type=search],.eb-camp select,.eb-camp textarea{height:44px;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;font-size:14px;color:#111827;background:#fff;box-sizing:border-box;width:100%;}
.eb-camp textarea{height:auto;min-height:96px;padding:10px 12px;line-height:1.5;resize:vertical;}
.eb-camp input[type=range]{width:100%;accent-color:var(--eb-albastru);}
.eb-camp small,.eb-nota{font-size:12px;color:#6b7280;line-height:1.45;}
.eb-rand{display:flex;gap:8px;align-items:center;}
.eb-rand > *{min-width:0;}
.eb-rand > select,.eb-rand > input{flex:1;}
.eb-rand > .eb-buton-lat,.eb-rand > .eb-mic{flex-shrink:0;}
.eb-grila2{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.eb-segment{display:flex;background:#f3f4f6;border-radius:10px;padding:4px;gap:4px;}
.eb-segment button{flex:1;height:36px;border:none;border-radius:8px;background:transparent;color:#4b5563;font-size:13px;cursor:pointer;}
.eb-segment button[aria-pressed="true"]{background:#fff;color:#111827;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.12);}
.eb-culori{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.eb-culori button{width:36px;height:36px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer;padding:0;}
.eb-culori button[aria-pressed="true"]{outline:2px solid var(--eb-albastru);outline-offset:2px;}
.eb-culori input[type=color]{width:36px;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:2px;background:#fff;cursor:pointer;}
.eb-culori input[type=text]{width:96px;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:0 8px;font-size:13px;}
.eb-bifa{display:flex;gap:10px;align-items:center;font-size:14px;min-height:36px;color:#1f2937;cursor:pointer;}
.eb-bifa input{width:18px;height:18px;}
.eb-drop-poza{border:2px dashed #93c5fd;background:#eff6ff;border-radius:12px;padding:20px 14px;text-align:center;font-size:13px;color:#1e40af;line-height:1.5;cursor:pointer;}
.eb-drop-poza.is-peste{background:#dbeafe;border-color:var(--eb-albastru);}
.eb-drop-poza button{background:none;border:none;padding:0;color:#1e40af;text-decoration:underline;cursor:pointer;font-size:13px;}
.eb-drop-poza img{display:block;max-width:100%;max-height:120px;margin:0 auto 8px;border-radius:6px;}
.eb-drop-stare{font-size:12px;min-height:16px;color:#1e40af;}
.eb-drop-stare.is-eroare{color:#b91c1c;}
.eb-lista{display:flex;flex-direction:column;gap:6px;}
.eb-lista-rand{display:flex;gap:6px;align-items:center;padding:6px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;}
.eb-lista-rand input{height:36px !important;font-size:13px !important;}
.eb-lista-rand img{width:40px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;background:#f3f4f6;}
.eb-lista-rand .eb-lista-text{flex:1;min-width:0;font-size:13px;color:#1f2937;line-height:1.3;}
.eb-lista-rand .eb-lista-text small{display:block;color:#6b7280;}
.eb-mic{min-width:32px;height:32px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;color:#374151;flex-shrink:0;padding:0 8px;}
.eb-mic:disabled{opacity:.35;cursor:default;}
.eb-mic.eb-sterge{border-color:#fecaca;color:#b91c1c;}
.eb-buton-lat{height:40px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;color:#1f2937;padding:0 12px;}
.eb-buton-lat:hover{background:#f9fafb;}
.eb-sep{border:none;border-top:1px solid #f3f4f6;margin:2px 0;}
.eb-avert-mic{padding:10px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12.5px;line-height:1.5;}
.eb-info-mic{padding:10px 12px;border-radius:8px;background:#f8fafc;border:1px solid #e5e7eb;color:#475569;font-size:12.5px;line-height:1.5;}
.eb-tokens-list{display:flex;flex-wrap:wrap;gap:4px;}
.eb-token-btn{padding:4px 7px;font-size:11px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;cursor:pointer;color:#1e40af;font-family:monospace;}

/* Ferestrele */
.eb-modal-overlay,.eb-toast{--eb-albastru:var(--ax-accent,#1d4ed8);}
.eb-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;}
.eb-modal{background:#fff;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.18);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;max-width:calc(100vw - 32px);}
.eb-modal-header{padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.eb-modal-header h3{margin:0;font-size:16px;}
.eb-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;line-height:1;width:44px;height:44px;}
.eb-modal-body{padding:16px 18px;overflow-y:auto;flex:1;}
/* Alege un șablon: o fereastră mare (pe telefon, tot ecranul), pasul 1 galeria, pasul 2 previzualizarea */
.eb-sab{width:min(1180px,calc(100vw - 32px));height:min(860px,calc(100vh - 32px));max-height:none;border-radius:16px;color:#1f2937;}
.eb-sab-pas{display:flex;flex-direction:column;min-height:0;flex:1;}
.eb-sab-pas[hidden]{display:none;}
.eb-sab-antet{display:flex;align-items:center;gap:14px;padding:16px 16px 12px 24px;border-bottom:1px solid #e5e7eb;flex-shrink:0;}
.eb-sab-antet-text{flex:1;min-width:0;}
.eb-sab-antet h3{margin:0;font-size:18px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eb-sab-antet p{margin:2px 0 0;font-size:13px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eb-sab-inapoi{height:40px;padding:0 12px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;font-size:13px;color:#374151;cursor:pointer;white-space:nowrap;flex-shrink:0;}
.eb-sab-inapoi:hover{background:#f9fafb;}
.eb-sab-unelte{display:flex;align-items:center;gap:12px;padding:12px 24px;border-bottom:1px solid #f3f4f6;flex-shrink:0;flex-wrap:wrap;}
.eb-sab-taburi{display:flex;background:#f3f4f6;border-radius:10px;padding:4px;gap:4px;}
.eb-sab-taburi button{height:34px;padding:0 14px;border:none;border-radius:8px;background:transparent;color:#4b5563;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.eb-sab-taburi button span{font-size:11px;color:#6b7280;background:#e5e7eb;border-radius:999px;padding:1px 7px;}
.eb-sab-taburi button[aria-selected="true"]{background:#fff;color:#111827;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.12);}
.eb-sab-taburi button[aria-selected="true"] span{background:#eef4ff;color:var(--eb-albastru);}
.eb-sab-cauta{margin-left:auto;height:40px;width:240px;max-width:100%;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;font-size:13px;box-sizing:border-box;}
.eb-sab-corp{flex:1;overflow:auto;overscroll-behavior:contain;padding:8px 24px 28px;background:#f9fafb;}
.eb-sab-sectiune[hidden]{display:none;}
.eb-sab-sectiune-titlu{margin:16px 0 10px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;}
.eb-sab-grila{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(196px,1fr));gap:16px;}
.eb-sab-card{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;transition:border-color .15s,box-shadow .15s,transform .15s;}
.eb-sab-card[hidden]{display:none;}
.eb-sab-card:hover{border-color:#c7d2fe;box-shadow:0 6px 20px rgba(15,23,42,.10);transform:translateY(-2px);}
.eb-sab-mini{position:relative;aspect-ratio:680/620;overflow:hidden;background:#f4f6f8;border-bottom:1px solid #f3f4f6;pointer-events:none;}
.eb-sab-mini iframe{position:absolute;top:0;left:0;width:680px;height:620px;border:0;transform-origin:0 0;transform:scale(var(--eb-sab-scala,.3));pointer-events:none;background:#f4f6f8;}
.eb-sab-mini::after{content:"";position:absolute;left:0;right:0;bottom:0;height:36px;background:linear-gradient(rgba(244,246,248,0),#f4f6f8);}
.eb-sab-mini-stare{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#9ca3af;}
.eb-sab-mini--gol{display:flex;align-items:center;justify-content:center;color:#94a3b8;background:#f8fafc;}
.eb-sab-mini--gol::after{display:none;}
.eb-sab-gol{width:46%;height:auto;}
.eb-sab-alege{all:unset;box-sizing:border-box;display:flex;flex-direction:column;gap:3px;padding:12px 14px 14px;cursor:pointer;text-align:left;}
.eb-sab-alege::after{content:"";position:absolute;inset:0;border-radius:12px;}
.eb-sab-alege:focus-visible::after{outline:2px solid var(--eb-albastru);outline-offset:-2px;}
.eb-sab-card:has(.eb-sab-alege:focus-visible){border-color:var(--eb-albastru);}
.eb-sab-nume{font-size:14px;font-weight:600;color:#111827;}
.eb-sab-desc{font-size:12.5px;line-height:1.4;color:#6b7280;}
.eb-sab-niciunul{margin:32px 0;text-align:center;color:#6b7280;font-size:14px;}
.eb-sab-moduri{flex-shrink:0;}
.eb-sab-scena{flex:1;overflow:auto;overscroll-behavior:contain;padding:24px;background:#f3f4f6;display:flex;align-items:flex-start;}
.eb-sab-cadru{margin:0 auto;max-width:100%;}
.eb-sab-cadru iframe{display:block;border:none;max-width:100%;}
.eb-sab-subsol{flex-shrink:0;border-top:1px solid #e5e7eb;padding:12px 24px;background:#fff;}
.eb-sab-actiuni,.eb-sab-intrebare{display:flex;align-items:center;gap:10px;}
.eb-sab-actiuni[hidden],.eb-sab-intrebare[hidden]{display:none;}
.eb-sab-nota{flex:1;margin:0;font-size:12.5px;color:#6b7280;}
.eb-sab-intrebare{padding:8px 10px 8px 14px;margin:-4px -10px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;}
.eb-sab-intrebare p{flex:1;margin:0;font-size:13px;color:#78350f;line-height:1.45;}
.eb-sab-intrebare p span{display:block;font-size:12px;color:#92400e;}
/* Mesajul de după alegere, cu „Anulează” */
.eb-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9100;display:flex;align-items:center;gap:14px;max-width:calc(100vw - 32px);box-sizing:border-box;padding:10px 10px 10px 16px;background:#111827;color:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);font-size:13.5px;}
.eb-toast[hidden]{display:none;}
.eb-toast button{height:36px;padding:0 12px;border:1px solid #4b5563;background:transparent;color:#fff;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;}
.eb-toast button:hover{background:#374151;}
.eb-sab button:focus-visible,.eb-sab input:focus-visible,.eb-toast button:focus-visible{outline:2px solid var(--eb-albastru,#1d4ed8);outline-offset:2px;}
@media (max-width:760px){
  .eb-sab-fundal{align-items:stretch;}
  .eb-sab{width:100vw;height:100%;max-width:none;border-radius:0;}
  .eb-sab-antet{flex-wrap:wrap;padding:12px 12px 10px 16px;gap:10px;}
  #eb-sab-previzualizare .eb-sab-antet-text{order:3;flex-basis:100%;}
  #eb-sab-previzualizare .eb-sab-moduri{margin-left:auto;}
  .eb-sab-unelte{padding:10px 16px;}
  .eb-sab-cauta{margin-left:0;width:100%;}
  .eb-sab-corp{padding:4px 16px 20px;}
  .eb-sab-grila{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
  .eb-sab-scena{padding:12px;}
  .eb-sab-subsol{padding:10px 16px;}
  .eb-sab-nota{display:none;}
  .eb-sab-actiuni{justify-content:flex-end;}
  .eb-sab-intrebare{flex-wrap:wrap;}
  .eb-sab-intrebare p{flex-basis:100%;}
}
@media (prefers-reduced-motion:reduce){.eb-sab-card{transition:none;}.eb-sab-card:hover{transform:none;}}
.eb-gallery-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.eb-gallery-img{border:2px solid #e5e7eb;border-radius:6px;overflow:hidden;cursor:pointer;aspect-ratio:1;padding:0;background:#fff;}
.eb-gallery-img:hover,.eb-gallery-img.is-ales{border-color:var(--eb-albastru,#1d4ed8);}
.eb-gallery-img img{width:100%;height:100%;object-fit:cover;display:block;}
.eb-modal-url-input{width:100%;height:44px;padding:0 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;}
@media (max-width:1500px){.eb-preview-tabs button{padding:0 10px;}.eb-title strong{max-width:200px;}}
@media (max-width:1400px){.eb-stare-salvare span{display:none;}}
</style>

<div class="eb-wrap" id="eb-wrap">

  <!-- ── Bara de sus ── -->
  <div class="eb-topbar">
    <a href="<?= htmlspecialchars($bBackUrl, ENT_QUOTES) ?>" class="eb-back" id="eb-back-link">← Înapoi</a>
    <div class="eb-title">
      <strong id="eb-title-display"><?= htmlspecialchars($bName, ENT_QUOTES) ?></strong>
      <span class="eb-insigna" id="eb-insigna-stare"><?= htmlspecialchars($eticheteStareBara, ENT_QUOTES) ?></span>
    </div>
    <div class="eb-spatiu"></div>
    <span class="eb-stare-salvare" id="eb-stare-salvare" data-stare="salvat" role="status" aria-live="polite"><span>Salvat</span></span>
    <span class="eb-dirty-dot" id="eb-dirty-dot"></span>
    <div class="eb-history-btns">
      <button type="button" id="btn-undo" disabled aria-label="Anulează (Ctrl+Z)" title="Anulează (Ctrl+Z)">↶</button>
      <button type="button" id="btn-redo" disabled aria-label="Refă (Ctrl+Y)" title="Refă (Ctrl+Y)">↷</button>
    </div>
    <div class="eb-preview-tabs" role="group" aria-label="Vizualizare">
      <button type="button" data-preview="edit" class="active" aria-pressed="true">Editez</button>
      <button type="button" data-preview="desktop" aria-pressed="false">Desktop</button>
      <button type="button" data-preview="mobile" aria-pressed="false">Mobil</button>
    </div>
    <button type="button" id="btn-save" class="eb-btn-salveaza">Salvează</button>
    <?php if ($bType === 'campaign'): ?>
    <button type="button" class="eb-btn-continua" id="nlp-continua-1" data-mergi-la="2">Continuă la destinatari →</button>
    <?php endif; ?>
  </div>

  <!-- ── Formularul salvării (ascuns) ── -->
  <form id="eb-form" method="post" action="/admin/emails/builder" style="display:none;">
    <input type="hidden" name="builder_type"        value="<?= htmlspecialchars($bType, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_id"          id="eb-id-input" value="<?= $bId ?>">
    <input type="hidden" name="builder_ref"         value="<?= htmlspecialchars($bRef, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_blocks_json" id="eb-blocks-input" value="<?= htmlspecialchars($bBlocksJson, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_name"        id="eb-name-input"   value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_subject"     id="eb-subject-input" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>">
    <?php if ($bIsActive): ?><input type="hidden" name="builder_is_active" id="eb-active-input" value="1"><?php endif; ?>
    <?php if ($bType === 'ecommerce'): ?>
    <input type="hidden" name="builder_recipient_mode"    id="eb-recip-mode-input" value="<?= htmlspecialchars($bRecipientMode, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_admin_recipients"  id="eb-recip-admin-input" value="<?= htmlspecialchars($bAdminRecip, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($bType === 'campaign'): ?>
    <input type="hidden" name="builder_list_ids" id="eb-list-ids-input" value="<?= htmlspecialchars(implode(',', $bListIds), ENT_QUOTES) ?>">
    <?php endif; ?>
  </form>

  <?php if ($bType === 'campaign'): ?>
  <!-- ── Pașii newsletterului ── -->
  <?php
  admin_componenta('bara-pasi', [
      'id' => 'eb-wizard-bar',
      'curent' => $bPasInitial,
      'pasi' => [
          ['nr' => 1, 'eticheta' => 'Conținut'],
          ['nr' => 2, 'eticheta' => 'Destinatari'],
          ['nr' => 3, 'eticheta' => 'Verificare'],
          ['nr' => 4, 'eticheta' => 'Trimite / Programează'],
      ],
      'dreapta' => '<span class="adm-stare adm-stare--' . $clasaStare . '" id="eb-campaign-status-badge">' . adm_e($stareCampanie) . '</span>',
  ]);
  ?>
  <?php if (!$bPoateModifica): ?>
  <div style="padding:10px 14px 0;background:var(--ax-suprafata);">
    <?php admin_componenta('mesaj', [
        'tip' => 'info',
        'titlu' => 'Newsletterul a început să plece, așa că nu se mai poate modifica.',
        'text' => 'Poți să te uiți la el. Pentru o variantă nouă, folosește „Duplică” din lista de newslettere.',
    ]); ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($bType !== 'ecommerce' && $bLipsaFirma !== []): ?>
  <p class="eb-avertisment" id="eb-avertisment-firma" role="note">
    Subsolul automat al newsletterului nu are încă: <?= htmlspecialchars(implode(', ', $bLipsaFirma), ENT_QUOTES) ?>.
    Emailurile comerciale trebuie să spună cine le trimite.
    <?php if (\App\Support\Auth::canAccessPath('/admin/settings/store')): ?>
    <a href="/admin/settings/store?tab=general#setari-firma">Completează datele firmei</a>
    <?php else: ?>
    Cere administratorului general să completeze Setări site → Datele firmei.
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <!-- ── Corpul ── -->
  <div class="eb-body">

    <!-- ── Paleta ── -->
    <nav class="eb-palette" aria-label="Blocuri">
      <input type="search" class="eb-cauta-bloc" id="eb-cauta-bloc" aria-label="Caută bloc" placeholder="Caută un bloc…" autocomplete="off">
      <?php foreach ($ebGrupuri as $grup): if ($grup['blocuri'] === []) { continue; } ?>
      <div class="eb-grup eb-grup--<?= $grup['cheie'] ?>" data-grup="<?= $grup['cheie'] ?>">
        <h3 class="eb-grup-titlu"><?= htmlspecialchars($grup['titlu'], ENT_QUOTES) ?></h3>
        <div class="eb-grila">
          <?php foreach ($grup['blocuri'] as [$tipBloc, $etichetaBloc, $cuvinte]): ?>
          <button type="button" class="eb-block-btn" draggable="true" data-block-type="<?= $tipBloc ?>" data-cauta="<?= htmlspecialchars(mb_strtolower($etichetaBloc . ' ' . $cuvinte), ENT_QUOTES) ?>" aria-label="Adaugă blocul <?= htmlspecialchars($etichetaBloc, ENT_QUOTES) ?>">
            <span class="eb-block-icon"><?= $ebIcon($tipBloc) ?></span><?= htmlspecialchars($etichetaBloc, ENT_QUOTES) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <details class="eb-mai-mult eb-grup" data-grup="mai-mult">
        <summary>Mai mult (secțiuni 60 / 40…, social media)</summary>
        <div class="eb-grila">
          <?php foreach ($ebMaiMult as [$tipBloc, $etichetaBloc, $cuvinte]): ?>
          <button type="button" class="eb-block-btn" draggable="true" data-block-type="<?= $tipBloc ?>" data-cauta="<?= htmlspecialchars(mb_strtolower($etichetaBloc . ' ' . $cuvinte), ENT_QUOTES) ?>" aria-label="Adaugă blocul <?= htmlspecialchars($etichetaBloc, ENT_QUOTES) ?>">
            <span class="eb-block-icon"><?= $ebIcon($tipBloc) ?></span><?= htmlspecialchars($etichetaBloc, ENT_QUOTES) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </details>
      <?php if ($bShowStarter || ($bSabloane !== [] && $bPoateModifica)): ?>
      <button type="button" class="eb-sablon-btn" onclick="ebShowStarterModal()"><?= $bSabloane !== [] ? 'Alege un șablon' : 'Template de start' ?></button>
      <?php endif; ?>
    </nav>

    <!-- ── Pânza ── -->
    <div class="eb-center">
      <div class="eb-canvas-scroll" id="eb-canvas-scroll">
        <div class="eb-panza-interior" id="eb-canvas-inner">
          <button type="button" class="eb-subiect" id="eb-subiect-bara" aria-label="Subiectul emailului: deschide setările emailului">
            <span>Subiect:</span><strong id="eb-subiect-text"></strong>
          </button>
          <div class="eb-email" id="eb-canvas" role="list" aria-label="Emailul: blocurile, în ordine"></div>
          <div class="eb-subsol-email" id="eb-subsol" tabindex="0" role="button" aria-label="Subsolul automat al emailului"<?= $bType === 'ecommerce' ? ' hidden' : '' ?>></div>
        </div>
        <div class="eb-bara-text" id="eb-bara-text" role="toolbar" aria-label="Formatarea textului" hidden>
          <button type="button" data-cmd="bold" aria-label="Îngroșat (Ctrl+B)" title="Îngroșat"><b>B</b></button>
          <button type="button" data-cmd="italic" aria-label="Cursiv (Ctrl+I)" title="Cursiv"><i>I</i></button>
          <button type="button" data-cmd="underline" aria-label="Subliniat (Ctrl+U)" title="Subliniat"><u>U</u></button>
          <button type="button" data-cmd="link" aria-label="Pune un link" title="Link">🔗</button>
          <label title="Culoarea textului" aria-label="Culoarea textului">A<input type="color" id="eb-bara-culoare" value="#1d4ed8" aria-label="Culoarea textului"></label>
          <button type="button" data-cmd="removeFormat" aria-label="Scoate formatarea" title="Scoate formatarea">⌫</button>
        </div>
      </div>
      <div class="eb-preview-pane" id="eb-preview-pane" hidden>
        <div class="eb-preview-frame" id="eb-preview-frame">
          <div class="eb-preview-loading" id="eb-preview-loading">Se generează previzualizarea...</div>
          <iframe id="eb-preview-iframe" title="Preview email" sandbox="allow-scripts" style="display:none;"></iframe>
        </div>
      </div>
    </div>

    <!-- ── Panoul din dreapta ── -->
    <aside class="eb-props" aria-label="Setările blocului ales">
      <div class="eb-props-antet">
        <h2 class="eb-props-titlu" id="eb-props-titlu">Emailul</h2>
        <div class="eb-taburi" role="tablist" id="eb-taburi" aria-label="Setările blocului" hidden>
          <button type="button" role="tab" id="eb-tab-continut" data-tab="continut" aria-selected="true" aria-controls="eb-props-body">Conținut</button>
          <button type="button" role="tab" id="eb-tab-aspect" data-tab="aspect" aria-selected="false" aria-controls="eb-props-body" tabindex="-1">Aspect</button>
          <button type="button" role="tab" id="eb-tab-afisare" data-tab="afisare" aria-selected="false" aria-controls="eb-props-body" tabindex="-1">Afișare</button>
        </div>
      </div>
      <div class="eb-props-body" id="eb-props-email">
        <?php if ($bType === 'campaign'): ?>
        <label class="eb-camp"><span>Subiect (îl văd abonații în inbox)</span>
          <input type="text" id="eb-set-subject" maxlength="190" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>" placeholder="ex. Reduceri de toamnă la ceaiuri">
        </label>
        <label class="eb-camp"><span>Nume intern (doar pentru tine)</span>
          <input type="text" id="eb-set-name" maxlength="190" value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
        </label>
        <?php else: ?>
        <?php if ($bType !== 'ecommerce'): ?>
        <label class="eb-camp"><span>Nume template</span>
          <input type="text" id="eb-set-name" value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
        </label>
        <?php endif; ?>
        <label class="eb-camp"><span>Subiect email</span>
          <input type="text" id="eb-set-subject" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>">
        </label>
        <?php if ($bType === 'ecommerce' && $bEcommerceMeta !== null): ?>
        <label class="eb-camp"><span>Destinatari</span>
          <select id="eb-set-recip-mode">
            <option value="client"       <?= $bRecipientMode === 'client' ? 'selected' : '' ?>>Doar client</option>
            <option value="admin"        <?= $bRecipientMode === 'admin' ? 'selected' : '' ?>>Doar admin</option>
            <option value="client_admin" <?= $bRecipientMode === 'client_admin' ? 'selected' : '' ?>>Client + admin</option>
          </select>
        </label>
        <label class="eb-camp" id="eb-admin-recip-wrap" <?= in_array($bRecipientMode, ['admin','client_admin']) ? '' : 'style="display:none"' ?>><span>Email-uri admin (separate prin virgulă)</span>
          <input type="text" id="eb-set-admin-recip" value="<?= htmlspecialchars($bAdminRecip, ENT_QUOTES) ?>">
        </label>
        <?php endif; ?>
        <?php if (!empty($bTokens)): ?>
        <div class="eb-camp"><span>Token-uri disponibile</span>
          <div class="eb-tokens-list" id="eb-tokens-list">
            <?php foreach ($bTokens as $tok): ?>
            <button type="button" class="eb-token-btn" data-token="<?= htmlspecialchars($tok, ENT_QUOTES) ?>" title="Click pentru a copia"><?= htmlspecialchars($tok, ENT_QUOTES) ?></button>
            <?php endforeach; ?>
          </div>
          <small>Click pe un token pentru a-l copia în clipboard</small>
        </div>
        <?php endif; ?>
        <label class="eb-bifa"><input type="checkbox" id="eb-set-active" <?= $bIsActive ? 'checked' : '' ?>> Template activ</label>
        <?php endif; ?>
        <p class="eb-nota">Alege un bloc din email ca să-l schimbi, sau adaugă unul din stânga (clic sau trage-l în email). Textele și titlurile se scriu direct în email.</p>
      </div>
      <div class="eb-props-body" id="eb-props-body" role="tabpanel" aria-labelledby="eb-tab-continut" hidden></div>
    </aside>

  </div><!-- /eb-body -->

  <?php if ($bType === 'campaign'): ?>
  <?php
  // Pașii 2–4: fiecare e un fișier mic din emails/newsletter/, cu componentele
  // din views/admin/componente/ (vezi docs/newsletter-faza-d.md).
  $nlId = $bId;
  $nlListe = $bNewsletterLists;
  $nlListeAlese = array_values(array_map('intval', $bListIds));
  $nlPoateModifica = $bPoateModifica;
  $nlEmailTest = (string) ($builder['email_test'] ?? '');
  $nlStare = $bCampaignStatus;
  $nlProgramat = (string) ($builder['scheduled_at'] ?? '');
  $nlTrimisLa = (string) ($builder['sent_at'] ?? '');
  $nlEroare = (string) ($builder['last_error'] ?? '');
  $nlTotaluri = is_array($builder['totaluri'] ?? null) ? $builder['totaluri'] : [];
  $nlRamase = (int) ($builder['ramase'] ?? 0);
  $nlTotalDestinatari = (int) ($builder['total_recipients'] ?? 0);
  include __DIR__ . '/newsletter/pas-destinatari.php';
  include __DIR__ . '/newsletter/pas-verificare.php';
  include __DIR__ . '/newsletter/pas-trimitere.php';
  ?>
  <?php endif; ?>

</div><!-- /eb-wrap -->

<!-- ── Alege un șablon: pasul 1, galeria (miniaturile sunt emailul randat de server, micșorat); pasul 2, previzualizarea ── -->
<?php
// Șabloanele simple (blocurile lor sunt în builder-script.php, EB_STARTERI, aceleași ca înainte de 26 nov 2026).
$ebStarteri = array_values(array_filter([
    ['blank', 'Blank', 'Pornești de la zero, doar cu un titlu.'],
    ['welcome', 'Bun venit', 'Un titlu, mesajul de bun venit și un buton.'],
    ['promo', 'Promoție', 'Titlul ofertei, o imagine, textul și un buton.'],
    ['newsletter', 'Newsletter', 'Salutul, un articol cu buton, separatoare.'],
    \App\Support\Plugins::vizibil('magazin') ? ['order', 'Email comandă', 'Confirmarea comenzii, cu produsele și totalul.'] : null,
    ['simple', 'Simplu', 'Titlu, text, un separator și o notă la final.'],
]));
$ebCardSablon = static function (string $cheie, string $nume, string $descriere, string $grup): string {
    $id = 'eb-sab-d-' . preg_replace('/[^a-z0-9_-]/i', '-', $cheie);
    $gol = $cheie === 'blank';
    return '<li class="eb-sab-card" data-grup="' . $grup . '" data-cauta="' . htmlspecialchars(mb_strtolower($nume . ' ' . $descriere), ENT_QUOTES) . '">'
        . '<div class="eb-sab-mini' . ($gol ? ' eb-sab-mini--gol' : '') . '" aria-hidden="true"' . ($gol ? '' : ' data-mini="' . htmlspecialchars($cheie, ENT_QUOTES) . '"') . '>'
        . ($gol
            ? '<svg class="eb-sab-gol" viewBox="0 0 120 150" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
              . '<rect x="10" y="8" width="100" height="134" rx="8" stroke-dasharray="5 5"></rect><path d="M26 30h68"></path><path d="M26 42h44"></path>'
              . '<circle cx="60" cy="88" r="15"></circle><path d="M60 81v14"></path><path d="M53 88h14"></path></svg>'
            : '<span class="eb-sab-mini-stare">Se pregătește…</span>')
        . '</div>'
        . '<button type="button" class="eb-sab-alege" data-sablon="' . htmlspecialchars($cheie, ENT_QUOTES) . '" tabindex="-1" aria-label="' . htmlspecialchars($nume, ENT_QUOTES) . '" aria-describedby="' . $id . '">'
        . '<span class="eb-sab-nume">' . htmlspecialchars($nume, ENT_QUOTES) . '</span>'
        . '<span class="eb-sab-desc" id="' . $id . '">' . htmlspecialchars($descriere, ENT_QUOTES) . '</span>'
        . '</button></li>';
};
$ebNrMagazin = count($bSabloane);
?>
<div class="eb-modal-overlay eb-sab-fundal" id="eb-starter-modal" style="display:none;">
  <div class="eb-modal eb-sab" role="dialog" aria-modal="true" aria-labelledby="eb-starter-titlu" data-pas="galerie">
    <!-- Pasul 1: galeria -->
    <section class="eb-sab-pas" id="eb-sab-galerie" aria-labelledby="eb-starter-titlu">
      <header class="eb-sab-antet">
        <div class="eb-sab-antet-text">
          <h3 id="eb-starter-titlu">Alege un șablon</h3>
          <p>Vezi cum arată fiecare, apoi îl schimbi bloc cu bloc.</p>
        </div>
        <button type="button" class="eb-modal-close" data-sab-inchide aria-label="Închide">✕</button>
      </header>
      <div class="eb-sab-unelte">
        <?php if ($ebNrMagazin > 0): ?>
        <div class="eb-sab-taburi" role="tablist" aria-label="Grupurile de șabloane">
          <button type="button" role="tab" data-sab-grup="toate" aria-selected="true" aria-controls="eb-sab-lista">Toate <span><?= $ebNrMagazin + count($ebStarteri) ?></span></button>
          <button type="button" role="tab" data-sab-grup="magazin" aria-selected="false" aria-controls="eb-sab-lista" tabindex="-1">Magazin <span><?= $ebNrMagazin ?></span></button>
          <button type="button" role="tab" data-sab-grup="simple" aria-selected="false" aria-controls="eb-sab-lista" tabindex="-1">Simple <span><?= count($ebStarteri) ?></span></button>
        </div>
        <?php endif; ?>
        <input type="search" class="eb-sab-cauta" id="eb-sab-cauta" placeholder="Caută un șablon…" aria-label="Caută un șablon" autocomplete="off">
      </div>
      <div class="eb-sab-corp" id="eb-sab-lista" role="region" aria-label="Șabloanele">
        <?php if ($ebNrMagazin > 0): ?>
        <div class="eb-sab-sectiune" data-sectiune="magazin">
          <h4 class="eb-sab-sectiune-titlu">Cu logo-ul și culorile magazinului</h4>
          <ul class="eb-sab-grila">
            <?php foreach ($bSabloane as $cheieSablon => $sablon): ?>
            <?= $ebCardSablon('magazin:' . $cheieSablon, (string) ($sablon['titlu'] ?? ''), (string) ($sablon['descriere'] ?? ''), 'magazin') ?>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <div class="eb-sab-sectiune" data-sectiune="simple">
          <h4 class="eb-sab-sectiune-titlu">Simple</h4>
          <ul class="eb-sab-grila">
            <?php foreach ($ebStarteri as [$cheieStart, $numeStart, $descStart]): ?>
            <?= $ebCardSablon($cheieStart, $numeStart, $descStart, 'simple') ?>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="eb-sab-niciunul" id="eb-sab-niciunul" hidden>Niciun șablon nu se potrivește căutării.</p>
      </div>
    </section>
    <!-- Pasul 2: previzualizarea șablonului ales -->
    <section class="eb-sab-pas" id="eb-sab-previzualizare" aria-labelledby="eb-sab-titlu" hidden>
      <header class="eb-sab-antet">
        <button type="button" class="eb-sab-inapoi" id="eb-sab-inapoi">← Înapoi la șabloane</button>
        <div class="eb-sab-antet-text">
          <h3 id="eb-sab-titlu"></h3>
          <p id="eb-sab-descriere"></p>
        </div>
        <div class="eb-preview-tabs eb-sab-moduri" role="group" aria-label="Previzualizarea șablonului">
          <button type="button" data-sab-mod="desktop" class="active" aria-pressed="true">Desktop</button>
          <button type="button" data-sab-mod="mobile" aria-pressed="false">Mobil</button>
        </div>
        <button type="button" class="eb-modal-close" data-sab-inchide aria-label="Închide">✕</button>
      </header>
      <div class="eb-sab-scena" id="eb-sab-scena">
        <div class="eb-preview-frame eb-sab-cadru" id="eb-sab-cadru">
          <div class="eb-preview-loading" id="eb-sab-incarcare" role="status">Se generează previzualizarea…</div>
          <iframe id="eb-sab-iframe" title="Previzualizarea șablonului" sandbox="allow-scripts" style="display:none;"></iframe>
        </div>
      </div>
      <footer class="eb-sab-subsol">
        <div class="eb-sab-actiuni" id="eb-sab-actiuni">
          <p class="eb-sab-nota">După alegere, textele, pozele și culorile se schimbă direct în email.</p>
          <button type="button" class="eb-btn-continua" id="eb-sab-foloseste">Folosește acest șablon</button>
        </div>
        <div class="eb-sab-intrebare" id="eb-sab-intrebare" role="alertdialog" aria-labelledby="eb-sab-intrebare-text" aria-describedby="eb-sab-intrebare-nota" hidden>
          <p><strong id="eb-sab-intrebare-text">Șablonul înlocuiește conținutul de acum. Continui?</strong>
            <span id="eb-sab-intrebare-nota">Poți reveni oricând cu „Anulează” (Ctrl+Z).</span></p>
          <button type="button" class="eb-btn-salveaza" id="eb-sab-renunta">Renunță</button>
          <button type="button" class="eb-btn-continua" id="eb-sab-inlocuieste">Da, înlocuiește</button>
        </div>
      </footer>
    </section>
  </div>
</div>
<div class="eb-toast" id="eb-toast" role="status" aria-live="polite" hidden><span id="eb-toast-text"></span><button type="button" id="eb-toast-anuleaza">Anulează</button></div>

<!-- ── Alege imagine ── -->
<div class="eb-modal-overlay" id="eb-img-modal" style="display:none;">
  <div class="eb-modal" style="width:620px;" role="dialog" aria-modal="true" aria-labelledby="eb-img-titlu">
    <div class="eb-modal-header">
      <h3 id="eb-img-titlu">Alege imagine</h3>
      <button type="button" class="eb-modal-close" onclick="ebCloseImgModal()" aria-label="Închide">✕</button>
    </div>
    <div class="eb-modal-body">
      <div class="eb-drop-poza" id="eb-img-drop" tabindex="0" role="button" aria-describedby="eb-img-drop-stare">
        <strong>Trage poza aici</strong><br>sau <button type="button" data-alege-fisier>alege din calculator</button> · lipește cu Ctrl+V
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden multiple>
        <div class="eb-drop-stare" id="eb-img-drop-stare" aria-live="polite"></div>
      </div>
      <input type="text" class="eb-modal-url-input" id="eb-img-url-input" placeholder="Sau lipește URL imagine direct..." aria-label="Adresa imaginii" style="margin-top:12px;">
      <div style="margin:12px 0 8px;font-size:12px;color:#6b7280;text-align:center;">sau alege din galerie</div>
      <input type="search" id="eb-gallery-search" class="eb-modal-url-input" placeholder="Caută imagini după titlu..." aria-label="Caută în galerie" style="margin-bottom:8px;">
      <div class="eb-gallery-grid" id="eb-gallery-grid">
        <?php foreach ($bGallery as $img): ?>
        <button type="button" class="eb-gallery-img" data-url="<?= htmlspecialchars((string)($img['image_url']??''), ENT_QUOTES) ?>" title="<?= htmlspecialchars((string)($img['alt_text']??$img['title']??''), ENT_QUOTES) ?>">
          <img src="<?= htmlspecialchars((string)($img['image_url']??''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string)($img['alt_text']??''), ENT_QUOTES) ?>" loading="lazy">
        </button>
        <?php endforeach; ?>
        <?php if (empty($bGallery)): ?>
        <p id="eb-gallery-gol" style="grid-column:1/-1;text-align:center;color:#6b7280;padding:20px;">Nicio imagine în galerie. Trage o poză mai sus ca s-o adaugi.</p>
        <?php endif; ?>
      </div>
      <div id="eb-gallery-pager" style="display:none;justify-content:center;gap:12px;margin-top:10px;align-items:center;"></div>
      <div style="margin-top:12px;text-align:right;display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-secondary" onclick="ebCloseImgModal()">Anulează</button>
        <button type="button" class="btn" onclick="ebConfirmImgUrl()">Folosește imaginea</button>
      </div>
    </div>
  </div>
</div>

<script>
<?php
// Datele paginii pentru script: tot ce vine din bază trece prin json_encode cu JSON_HEX_* (nu poate închide <script>).
$ebDate = [
    'tip' => $bType,
    'id' => $bId,
    'blocuri' => $bBlocks,
    'poateModifica' => $bPoateModifica,
    'salvareAutomata' => $bSalvareAutomata,
    'csrf' => (string) ($builder['csrf'] ?? ''),
    'logo' => $builder['logo_site'] ?? ['url' => '', 'alt' => ''],
    'culori' => $builder['culori_site'] ?? [],
    'categorii' => $builder['categorii'] ?? [],
    'cupoane' => $builder['cupoane'] ?? [],
    'cuCatalog' => $bCuCatalog,
    'cuMagazin' => $bCuMagazin,
    'subsol' => (string) ($builder['subsol_html'] ?? ''),
    'firma' => $bDateSubsol,
    'poateSetari' => \App\Support\Auth::canAccessPath('/admin/settings/store'),
    'etichetaButonProdus' => $bCuCatalog ? 'Vezi produsul' : '',
    'sabloane' => array_map(fn($x) => $x['blocks'] ?? [], $bSabloane),
    'arataStart' => ($bShowStarter || !empty($builder['nou'])) && $bPoateModifica,
];
?>
window.EB_DATE = <?= $ebJson($ebDate) ?>;
</script>
<?php include __DIR__ . '/builder-script.php'; ?>
<?php if ($bType === 'campaign'): ?>
<script src="/assets/js/admin/newsletter-pasi.js?v=<?= (int) $adminJsNl ?>" defer
        data-id="<?= $bId ?>" data-pas="<?= $bPasInitial ?>" data-poate-modifica="<?= $bPoateModifica ? '1' : '0' ?>"></script>
<?php endif; ?>
