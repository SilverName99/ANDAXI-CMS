<?php
/**
 * Ce vede un administrator care deschide direct adresa unei pagini dintr-un
 * plugin pe care site-ul nu îl are (GardaPluginuri, răspuns 403). Nu spune
 * ce modul e și nu propune nimic: doar că pagina nu e disponibilă.
 */
?>
<section class="panel" style="max-width:640px;">
    <h1>Modulul nu este inclus în abonament</h1>
    <p>Pagina pe care ai deschis-o face parte dintr-un modul care nu este inclus în abonamentul acestui site.</p>
    <p><a class="btn btn-secondary" href="<?= htmlspecialchars(\App\Support\Auth::defaultPathForRole(), ENT_QUOTES) ?>">Înapoi la panou</a></p>
</section>
