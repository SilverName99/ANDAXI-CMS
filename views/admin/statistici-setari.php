<?php
/**
 * Rapoarte → Statistici → Setări (pluginul `statistici`): „Do Not Track” / „Global Privacy Control” și câte zile se țin
 * evenimentele brute (agregatele rămân).
 *
 * @var bool $respectaDnt
 * @var int $zileBrute
 * @var int $maxZi
 * @var int $maxMinut
 */
$tab = 'setari';
?>
<div class="st-pagina">
<?php require __DIR__ . '/statistici-cap.php'; ?>
<section class="st-sectiune">
<div class="st-sectiune__antet"><h2>Setări</h2></div>
<form method="post" action="/admin/statistici/setari" class="adm-formular" id="st-setari">
    <?= \App\Support\AdminCsrf::camp() ?>
    <p><label><input type="checkbox" name="statistici_respecta_dnt" value="1"<?= $respectaDnt ? ' checked' : '' ?>> Nu număra browserele care cer „nu urmări” (Do Not Track sau Global Privacy Control)</label></p>
    <p><label for="st-zile">Evenimentele detaliate se șterg după (zile)</label><br>
        <input type="number" id="st-zile" name="statistici_zile_brute" min="7" max="400" value="<?= (int) $zileBrute ?>"></p>
    <p><label for="st-max-zi">Cel mult evenimente pe zi (peste, restul zilei nu se mai notează)</label><br>
        <input type="number" id="st-max-zi" name="statistici_max_zi" min="1000" max="5000000" step="1000" value="<?= (int) $maxZi ?>"></p>
    <p><label for="st-max-minut">Cel mult evenimente pe minut, pe tot site-ul (de ridicat înaintea unui vârf așteptat)</label><br>
        <input type="number" id="st-max-minut" name="statistici_max_minut" min="60" max="100000" step="100" value="<?= (int) $maxMinut ?>"></p>
    <p style="font-size:13px;color:var(--adm-text-secundar)">Agregatele (vizitele, paginile, produsele, căutările și comenzile pe zile) rămân. „Acum pe site” și refacerea unei zile au nevoie de evenimentele detaliate.</p>
    <button class="btn btn-primary" type="submit">Salvează</button>
</form>
</section>
<?php
ob_start();
?>
<p>Statisticile nu folosesc cookie-uri și nu țin nimic în browserul vizitatorului (nici localStorage, nici sessionStorage). Ca vizitele unei zile să se numere, se folosește un cod calculat din adresa IP, browser și o valoare secretă schimbată în fiecare zi (ștearsă a doua zi); adresa IP și browserul nu se păstrează (doar felul browserului, al sistemului și al dispozitivului). Evenimentele detaliate se șterg după numărul de zile de mai sus; rapoartele rămân, agregate.</p>
<p>Propoziție pentru politica de confidențialitate: <em>„Pentru statistici de trafic folosim o analiză proprie, fără cookie-uri. Nu păstrăm adresa IP; pentru a număra vizitele unei zile folosim un cod care se schimbă zilnic. Datele detaliate se șterg după <?= (int) $zileBrute ?> de zile, iar rapoartele (paginile vizitate, sursa vizitei, tipul dispozitivului și al browserului) sunt agregate. Dacă browserul dumneavoastră trimite semnalul «Do Not Track» sau «Global Privacy Control», nu sunteți numărat.”</em></p>
<?php
admin_componenta('card', ['titlu' => 'Confidențialitatea', 'continut' => (string) ob_get_clean(), 'id' => 'st-confidentialitate']);
?>
</div>

