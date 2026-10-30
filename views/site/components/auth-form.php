<?php
/**
 * {{auth_form}} (WP-25: VT-C5, din vertij 833baa9): formularul de intrare și de înregistrare pe o pagină din constructor.
 * Se completează doar pe paginile cu adresa „login” și „register” (acolo îl trimite controllerul; pe alte pagini codul
 * rămâne gol). Aceleași câmpuri și aceeași capcană anti-robot ca pagina contului (capcana ascunsă cu display:none, ca în
 * codul comun, nu poziționată în afara ecranului ca la vertij); câmpurile unui plugin (de exemplu profesia, la `membri`)
 * vin prin punctul `inregistrare.formular`. Culorile: culoarea principală a temei site-ului (`tema.json`).
 */
$activeView = in_array((string) ($activeView ?? 'login'), ['login', 'register'], true) ? (string) $activeView : 'login';
$next = (string) ($next ?? '/contul-meu');
$registrationFields = is_array($registrationFields ?? null) ? $registrationFields : [
    'first_name' => true, 'last_name' => true, 'email' => true, 'phone' => true, 'password' => true, 'password_confirm' => true,
];
$registerAntiBotToken = (string) ($registerAntiBotToken ?? '');
$registerAntiBotRenderedAt = (int) ($registerAntiBotRenderedAt ?? 0);
$accent = \App\Support\Tema::culoarePrincipala();
?>
<style>
.auth-form{--auth-accent:<?= htmlspecialchars($accent, ENT_QUOTES) ?>;max-width:520px;margin:0 auto;}
.auth-form__tabs{display:flex;gap:8px;margin:6px auto 18px;}
.auth-form__tabs a{flex:1;text-align:center;padding:11px 14px;border-radius:999px;font-weight:700;text-decoration:none;color:var(--auth-accent);background:#f3f4f6;border:1.5px solid transparent;}
.auth-form__tabs a.active{background:var(--auth-accent);color:#fff;}
.auth-form .panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 16px 40px rgba(15,23,42,.06);padding:26px 26px 24px;margin:0;}
.auth-form h3{color:#1f2937;font-size:22px;margin:0 0 6px;}
.auth-form label{display:block;font-size:13px;font-weight:600;color:#4b5563;margin:14px 0 6px;}
.auth-form input[type=email],.auth-form input[type=text],.auth-form input[type=password],.auth-form select{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;background:#fff;}
.auth-form input:focus,.auth-form select:focus{outline:none;border-color:var(--auth-accent);}
.auth-form .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;}
.auth-form .btn{width:100%;margin-top:20px;border:0;cursor:pointer;background:var(--auth-accent);color:#fff;font-weight:700;font-size:15px;padding:13px 18px;border-radius:12px;}
.auth-form a{color:var(--auth-accent);font-weight:600;}
@media(max-width:560px){.auth-form .form-grid{grid-template-columns:1fr;}}
</style>
<div class="auth-form" data-auth-form>
    <div class="auth-form__tabs">
        <a class="<?= $activeView === 'login' ? 'active' : '' ?>" href="/login">Login</a>
        <a class="<?= $activeView === 'register' ? 'active' : '' ?>" href="/register">Înregistrare</a>
    </div>
    <article class="panel" style="<?= $activeView === 'login' ? '' : 'display:none;' ?>" data-auth-view="login">
        <h3>Autentificare</h3>
        <form method="post" action="/login" class="field">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
            <label>Email</label>
            <input type="email" name="email" required>
            <label>Parolă</label>
            <input type="password" name="password" required>
            <button class="btn" type="submit">Login</button>
        </form>
        <p style="margin-top:10px;"><a href="/contul-meu/resetare-parola">Ai uitat parola?</a></p>
    </article>
    <article class="panel" style="<?= $activeView === 'register' ? '' : 'display:none;' ?>" data-auth-view="register">
        <h3>Creează cont</h3>
        <form method="post" action="/register" class="form-grid">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
            <input type="hidden" name="register_form_token" value="<?= htmlspecialchars($registerAntiBotToken, ENT_QUOTES) ?>">
            <input type="hidden" name="register_form_rendered_at" value="<?= $registerAntiBotRenderedAt > 0 ? $registerAntiBotRenderedAt : time() ?>">
            <div class="field" style="display:none;" aria-hidden="true">
                <label for="auth-form-hp-website">Website</label>
                <input id="auth-form-hp-website" type="text" name="register_hp_website" value="" tabindex="-1" autocomplete="off">
            </div>
            <?php if (!empty($registrationFields['first_name'])): ?>
                <div class="field"><label>Prenume</label><input type="text" name="first_name" required></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['last_name'])): ?>
                <div class="field"><label>Nume</label><input type="text" name="last_name" required></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['email'])): ?>
                <div class="field"><label>Email</label><input type="email" name="email" required></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['phone'])): ?>
                <div class="field"><label>Telefon</label><input type="text" name="phone"></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['birth_date'])): ?>
                <div class="field"><label>Data nașterii</label><?php $birthDateValue = ''; include __DIR__ . '/birth-date-field.php'; ?></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['gender'])): ?>
                <div class="field"><label>Gen</label><select name="gender"><option value="">Selectează</option><option value="feminin">Feminin</option><option value="masculin">Masculin</option></select></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['password'])): ?>
                <div class="field"><label>Parolă</label><input type="password" name="password" minlength="8" required></div>
            <?php endif; ?>
            <?php if (!empty($registrationFields['password_confirm'])): ?>
                <div class="field"><label>Confirmă parola</label><input type="password" name="password_confirm" minlength="8" required></div>
            <?php endif; ?>
<?= \App\Support\Hooks::html('inregistrare.formular') ?>
            <div style="grid-column:1/-1;">
                <button class="btn" type="submit">Creează cont</button>
            </div>
        </form>
    </article>
</div>
