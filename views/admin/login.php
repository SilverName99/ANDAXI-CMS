<?php
// Logarea în admin (WP-35, „B — Compact”): aceeași cerere (POST /admin/login, email și password), cu marca adminului și
// culorile lui. Cadrul nelogat (views/admin/layout.php, clasa ax-admin-login) nu are meniu.
?>
<section class="ax-login" aria-labelledby="ax-login-titlu">
    <div class="ax-marca ax-login__marca">
        <span class="ax-marca-semn" aria-hidden="true">A</span>
        <span class="ax-marca-text"><span class="ax-marca-nume">ANDAXI CMS</span></span>
    </div>
    <h1 id="ax-login-titlu">Login admin</h1>
    <?php if (!empty($error)): ?>
        <p class="ax-login__eroare" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login" class="field ax-login__formular">
        <label for="ax-login-email">Email</label>
        <input type="email" id="ax-login-email" name="email" autocomplete="username" required>
        <label for="ax-login-parola">Parolă</label>
        <input type="password" id="ax-login-parola" name="password" autocomplete="current-password" required>
        <button class="btn" type="submit">Autentificare</button>
    </form>
</section>
