<?php
/** Setări pentru conturile profesionale: upload, GDPR și textele afișate vizitatorilor. */
$settings = is_array($settings ?? null) ? $settings : [];
$value = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Setări conturi profesionale</h1>
            <p>
                Reguli pentru documentele încărcate la înregistrare și textele pe care le văd
                vizitatorii când ajung pe o pagină restricționată.
            </p>
        </div>
        <div>
            <a class="btn btn-secondary" href="/admin/accounts/verifications">Cereri</a>
        </div>
    </div>

    <form method="post" action="/admin/accounts/settings" class="form-grid">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <h2 style="grid-column:1/-1;margin-bottom:0;">Documente și securitate</h2>

        <div class="field">
            <label>Mărime maximă per fișier (MB)</label>
            <input type="number" step="0.5" min="0.5" name="account_upload_max_mb"
                   value="<?= htmlspecialchars($value('account_upload_max_mb', '8'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Număr maxim de fișiere per cerere</label>
            <input type="number" min="1" name="account_upload_max_files"
                   value="<?= htmlspecialchars($value('account_upload_max_files', '10'), ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Extensii acceptate (separate prin virgulă)</label>
            <input type="text" name="account_upload_allowed_ext"
                   value="<?= htmlspecialchars($value('account_upload_allowed_ext', 'pdf,jpg,jpeg,png,webp'), ENT_QUOTES) ?>">
            <small style="color:#64748b;">
                Pe lângă extensie, se verifică și conținutul real al fișierului.
                Serverul poate impune o limită mai mică prin <code>upload_max_filesize</code>.
            </small>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Email pentru notificarea cererilor noi</label>
            <input type="email" name="account_verification_notify_email"
                   value="<?= htmlspecialchars($value('account_verification_notify_email'), ENT_QUOTES) ?>"
                   placeholder="ex: contact@exemplu.ro">
            <small style="color:#64748b;">
                Lasă gol dacă nu vrei notificări. Emailul se trimite după confirmarea adresei de către utilizator.
            </small>
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Acord GDPR la înregistrare</h2>

        <div class="field" style="grid-column:1/-1;">
            <label>Text bifă acord</label>
            <input type="text" name="account_gdpr_label"
                   value="<?= htmlspecialchars($value('account_gdpr_label'), ENT_QUOTES) ?>">
            <small style="color:#64748b;">Lasă gol pentru a nu cere bifa (nerecomandat).</small>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Link către pagina de politică</label>
            <input type="text" name="account_gdpr_url"
                   value="<?= htmlspecialchars($value('account_gdpr_url'), ENT_QUOTES) ?>"
                   placeholder="/politica-de-confidentialitate">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text introductiv în formularul de înregistrare</label>
            <textarea name="account_registration_intro" rows="2"><?= htmlspecialchars($value('account_registration_intro'), ENT_QUOTES) ?></textarea>
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Identitatea emailurilor</h2>

        <div class="field">
            <label>Nume expeditor</label>
            <input type="text" name="email_brand_name"
                   value="<?= htmlspecialchars($value('email_brand_name'), ENT_QUOTES) ?>"
                   placeholder="<?= htmlspecialchars($value('order_email_from_name', ''), ENT_QUOTES) ?>">
            <small style="color:#64748b;">
                Numele afișat în antetul și subsolul emailurilor de cont. Gol = se folosește numele
                setat la <a href="/admin/emails/sender">Emailuri &rarr; Expeditor</a>.
            </small>
        </div>
        <div class="field">
            <label>Logo în emailuri</label>
            <input type="text" name="email_logo_url"
                   value="<?= htmlspecialchars($value('email_logo_url'), ENT_QUOTES) ?>"
                   placeholder="/uploads/gallery/logo.png">
            <small style="color:#64748b;">
                Calea către logo din Galerie. Gol = se afișează numele organizației în locul imaginii.
            </small>
        </div>
        <div class="field">
            <label>Mărime maximă poză de profil (MB)</label>
            <input type="number" step="0.5" min="0.5" name="account_avatar_max_mb"
                   value="<?= htmlspecialchars($value('account_avatar_max_mb', '3'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Folder galerie pentru poze de profil</label>
            <input type="text" name="account_avatar_folder"
                   value="<?= htmlspecialchars($value('account_avatar_folder', 'Poze de profil'), ENT_QUOTES) ?>">
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Butonul de cont din header</h2>

        <div class="field" style="grid-column:1/-1;">
            <label>Text buton când vizitatorul este autentificat</label>
            <input type="text" name="header_account_label" maxlength="60"
                   value="<?= htmlspecialchars($value('header_account_label', 'Contul meu'), ENT_QUOTES) ?>">
            <small style="color:#64748b;">
                Butonul din header (ex. „Log in membrii”) își schimbă textul în acesta după autentificare,
                iar link-ul duce către contul utilizatorului. Lasă gol pentru a păstra textul original.
            </small>
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Cotizație și cod de membru</h2>

        <div class="field">
            <label>Perioade oferite la plată (luni, separate prin virgulă)</label>
            <input type="text" name="account_activation_durations"
                   value="<?= htmlspecialchars($value('account_activation_durations', '1,3,6,12'), ENT_QUOTES) ?>">
            <small style="color:#64748b;">
                Prețul fiecărei perioade se calculează proporțional din cotizația setată pe categorie.
                Ex: cotizație 200 lei / 12 luni &rarr; o lună costă 16,67 lei.
            </small>
        </div>
        <div class="field">
            <label>Prefix cod de membru</label>
            <input type="text" name="account_member_code_prefix" maxlength="10"
                   value="<?= htmlspecialchars($value('account_member_code_prefix', \App\Support\ProfessionAccounts::PREFIX_COD_IMPLICIT), ENT_QUOTES) ?>">
            <small style="color:#64748b;">
                Codurile existente nu se schimbă; prefixul se aplică doar conturilor noi.
            </small>
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Secțiunea „Activare cont”</h2>

        <div class="field">
            <label>Titlu secțiune</label>
            <input type="text" name="account_activation_title"
                   value="<?= htmlspecialchars($value('account_activation_title', 'Activează contul'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Etichetă buton activare</label>
            <input type="text" name="account_activation_button"
                   value="<?= htmlspecialchars($value('account_activation_button', 'Activează contul'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Etichetă buton reactivare</label>
            <input type="text" name="account_activation_renew_button"
                   value="<?= htmlspecialchars($value('account_activation_renew_button', 'Reactivează contul'), ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text pentru conturile aprobate, dar neactivate</label>
            <textarea name="account_activation_intro" rows="2"><?= htmlspecialchars($value('account_activation_intro'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text pentru conturile active</label>
            <textarea name="account_activation_active_text" rows="2"><?= htmlspecialchars($value('account_activation_active_text'), ENT_QUOTES) ?></textarea>
            <small style="color:#64748b;">
                Poți folosi <code>{{data_expirare}}</code> și <code>{{zile_ramase}}</code>.
            </small>
        </div>

        <h2 style="grid-column:1/-1;margin-bottom:0;">Pagina de conținut restricționat</h2>

        <div class="field" style="grid-column:1/-1;">
            <label>Titlu</label>
            <input type="text" name="restricted_page_title"
                   value="<?= htmlspecialchars($value('restricted_page_title'), ENT_QUOTES) ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Vizitator nelogat</label>
            <textarea name="restricted_guest_text" rows="2"><?= htmlspecialchars($value('restricted_guest_text'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Cont creat, email neconfirmat</label>
            <textarea name="restricted_awaiting_email_text" rows="2"><?= htmlspecialchars($value('restricted_awaiting_email_text'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Cerere în verificare</label>
            <textarea name="restricted_pending_text" rows="2"><?= htmlspecialchars($value('restricted_pending_text'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Cerere respinsă</label>
            <textarea name="restricted_rejected_text" rows="2"><?= htmlspecialchars($value('restricted_rejected_text'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Aprobat, dar neactivat (fără plată)</label>
            <textarea name="restricted_inactive_text" rows="2"><?= htmlspecialchars($value('restricted_inactive_text'), ENT_QUOTES) ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Activare expirată</label>
            <textarea name="restricted_expired_text" rows="2"><?= htmlspecialchars($value('restricted_expired_text'), ENT_QUOTES) ?></textarea>
        </div>

        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările</button>
        </div>
    </form>
</section>
