<?php
/**
 * Pagina deschisă de linkul de confirmare a adresei (revizuirea WP-25, App\Http\Controllers\ConfirmareEmailController):
 * doar butonul confirmă (POST), nu deschiderea linkului.
 */
$token = (string) ($token ?? '');
$pentruComenzi = (bool) ($pentruComenzi ?? false);
?>
<section class="panel account-reset-panel">
    <h1 style="margin-top:0;">Confirmă adresa de email</h1>
    <p><?= $pentruComenzi
        ? 'Apasă butonul ca să confirmi că adresa de email a contului îți aparține. Comenzile făcute fără cont cu această adresă vor apărea în contul tău.'
        : 'Scrie parola cu care ai creat contul și apasă butonul, ca să confirmi că adresa de email îți aparține. Dacă nu tu ai creat contul, nu face nimic: fără parolă, contul nu se confirmă.' ?></p>
    <form method="post" action="/cont/confirmare/<?= htmlspecialchars($token, ENT_QUOTES) ?>" class="field" style="max-width:480px;">
<?php if (!$pentruComenzi): ?>
        <label for="confirmare-parola">Parola contului</label>
        <input id="confirmare-parola" type="password" name="password" autocomplete="current-password" required>
<?php endif; ?>
        <button class="btn" type="submit">Confirmă adresa</button>
    </form>
</section>
