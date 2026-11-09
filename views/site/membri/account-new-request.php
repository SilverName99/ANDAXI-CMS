<?php
/**
 * Trimiterea unei cereri noi de cont de membru, dintr-un cont deja existent.
 *
 * Apare când cererea anterioară a fost respinsă (sau când contul nu a trimis
 * niciodată una). Adresa de email e deja confirmată, deci formularul cere doar
 * categoria, câmpurile ei și documentele — nu și date de cont.
 *
 * @var array<int, array<string, mixed>> $professions
 * @var array<string, mixed>             $professionConfig
 * @var array<string, mixed>             $accountState
 */
$professions = is_array($professions ?? null) ? $professions : [];
$professionConfig = is_array($professionConfig ?? null) ? $professionConfig : [];
$accountState = is_array($accountState ?? null) ? $accountState : [];

$wasRejected = (string) ($accountState['verification_status'] ?? '') === 'rejected';
$reviewNote = trim((string) ($accountState['review_note'] ?? ''));
?>
<style>
.req-wrap{width:100%;max-width:760px;margin:0 auto;padding:26px 18px 56px;font-family:"DM Sans",Arial,sans-serif;}
.req-back{display:inline-flex;align-items:center;gap:7px;margin-bottom:16px;color:#6f2b98;font-size:14px;font-weight:600;text-decoration:none;}
.req-back:hover{text-decoration:underline;}
.req-head{border-radius:20px;padding:26px 28px;margin-bottom:20px;color:#fff;
  background:linear-gradient(135deg,#ea4968,#6f2b98);box-shadow:0 18px 40px rgba(111,43,152,.2);}
.req-head h1{margin:0 0 6px;font-family:"Playfair Display",Georgia,serif;font-size:27px;line-height:1.2;color:#fff;}
.req-head p{margin:0;font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.88);}
.req-note{margin:0 0 20px;padding:16px 18px;border-radius:14px;background:#fdf3f5;border:1px solid #f3c9d6;border-left:5px solid #ea4968;}
.req-note strong{display:block;margin-bottom:5px;color:#a11d33;font-size:14.5px;}
.req-note p{margin:0;color:#6b7280;font-size:14px;line-height:1.6;white-space:pre-line;}
.req-card{background:#fff;border:1px solid #efe3f2;border-radius:18px;padding:24px 26px 26px;box-shadow:0 12px 32px rgba(111,43,152,.07);}
.req-card .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;}
.req-card .form-grid .field{margin:0;}
.req-card label{display:block;font-size:13px;font-weight:600;color:#4b5563;margin-bottom:6px;}
.req-card .btn{width:100%;margin-top:20px;border:0;cursor:pointer;font-family:inherit;
  background:linear-gradient(135deg,#ea4968,#6f2b98);color:#fff;font-weight:700;font-size:15px;
  padding:13px 18px;border-radius:12px;transition:transform .15s ease,opacity .15s ease;}
.req-card .btn:hover{transform:translateY(-2px);opacity:.95;}

/* Stilurile componentei de câmpuri, aceleași ca pe pagina de înregistrare. */
.profession-intro{margin:0 0 4px;color:#6b7280;font-size:13.5px;line-height:1.5;}
.profession-fieldset{margin:14px 0 0;padding:14px 16px 16px;border:1px solid #e5d9ec;border-radius:12px;background:#fdfbfe;}
.profession-fieldset legend{padding:0 8px;font-size:13px;font-weight:700;color:#6f2b98;}
.profession-fieldset input[type=email],
.profession-fieldset input[type=password],
.profession-fieldset input[type=text],
.profession-fieldset input[type=date],
.profession-fieldset input[type=number],
.profession-fieldset select,
.req-card select{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #e5d9ec;border-radius:10px;font-size:15px;background:#fff;font-family:inherit;color:#1f2937;}
.profession-fieldset textarea{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #e5d9ec;border-radius:10px;font-size:15px;background:#fff;font-family:inherit;}
.profession-fieldset input[type=file]{width:100%;box-sizing:border-box;padding:9px 11px;border:1px dashed #d9c2e6;border-radius:10px;font-size:13.5px;background:#fff;}
.profession-description{margin:0;color:#6b7280;font-size:13px;line-height:1.5;}
.profession-price{margin:8px 0 0;color:#4b5563;font-size:13px;line-height:1.5;}
.profession-hint{display:block;margin-top:5px;color:#8b8b9c;font-size:12px;line-height:1.45;}
.profession-gdpr{margin-top:16px;}
.profession-consent{display:flex;align-items:flex-start;gap:9px;margin:0;font-weight:500;color:#4b5563;font-size:13px;line-height:1.5;}
.profession-consent input[type=checkbox]{margin-top:2px;flex:0 0 auto;}
.profession-block{display:contents;}
@media(max-width:560px){.req-card .form-grid{grid-template-columns:1fr;}}
</style>

<section class="req-wrap">
    <a class="req-back" href="/contul-meu?section=activare">&larr; Înapoi la cont</a>

    <header class="req-head">
        <h1><?= $wasRejected ? 'Trimite o cerere nouă' : 'Cerere de cont de membru' ?></h1>
        <p>
            <?php if ($wasRejected): ?>
                Corectează ce a fost semnalat și trimite din nou. Nu trebuie să îți faci alt cont —
                datele tale rămân aceleași, iar adresa de email e deja confirmată.
            <?php else: ?>
                Alege categoria din care faci parte și încarcă documentele cerute.
                O verificăm și îți răspundem pe email.
            <?php endif; ?>
        </p>
    </header>

    <?php if ($wasRejected && $reviewNote !== ''): ?>
        <div class="req-note">
            <strong>Motivul respingerii anterioare</strong>
            <p><?= htmlspecialchars($reviewNote, ENT_QUOTES) ?></p>
        </div>
    <?php endif; ?>

    <article class="req-card">
        <form method="post" action="/contul-meu/cerere-noua" class="form-grid" enctype="multipart/form-data">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <?php require __DIR__ . '/profession-fields.php'; ?>
            <div style="grid-column:1/-1;">
                <button class="btn" type="submit">Trimite cererea</button>
            </div>
        </form>
    </article>
</section>
