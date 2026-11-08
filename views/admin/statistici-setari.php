<?php
/**
 * Rapoarte → Statistici → Setări (pluginul `statistici`): „Do Not Track” / „Global Privacy Control” și câte zile se țin
 * evenimentele brute (agregatele rămân).
 *
 * @var bool $respectaDnt
 * @var int $zileBrute
 */
admin_componenta('antet-pagina', [
    'titlu' => 'Setări statistici',
    'nivel' => 1,
    'subtitlu' => 'Ce vizitatori nu se numără și cât timp se păstrează evenimentele detaliate.',
    'actiuni' => '<a class="btn btn-secondary" href="/admin/statistici">← Statistici</a>',
]);
?>
<form method="post" action="/admin/statistici/setari" class="adm-formular" id="st-setari">
    <?= \App\Support\AdminCsrf::camp() ?>
    <p><label><input type="checkbox" name="statistici_respecta_dnt" value="1"<?= $respectaDnt ? ' checked' : '' ?>> Nu număra browserele care cer „nu urmări” (Do Not Track sau Global Privacy Control)</label></p>
    <p><label for="st-zile">Evenimentele detaliate se șterg după (zile)</label><br>
        <input type="number" id="st-zile" name="statistici_zile_brute" min="7" max="400" value="<?= (int) $zileBrute ?>"></p>
    <p style="font-size:13px;color:var(--adm-text-secundar)">Agregatele (vizitele, paginile, produsele, căutările și comenzile pe zile) rămân. „Acum pe site” și refacerea unei zile au nevoie de evenimentele detaliate.</p>
    <button class="btn btn-primary" type="submit">Salvează</button>
</form>
<?php
ob_start();
?>
<p>Statisticile nu folosesc cookie-uri și nu țin nimic în browserul vizitatorului (nici localStorage, nici sessionStorage). Un vizitator e recunoscut doar în aceeași zi, după un cod calculat din adresa IP, browser și o valoare secretă schimbată în fiecare zi; valoarea zilei se șterge a doua zi, iar adresa IP și browserul nu se păstrează (doar felul browserului, al sistemului și al dispozitivului).</p>
<p>Propoziție pentru politica de confidențialitate: <em>„Pentru statistici de trafic folosim o analiză proprie, fără cookie-uri: nu păstrăm adresa IP și nu vă putem recunoaște de la o zi la alta; vedem doar, agregat, paginile vizitate, sursa vizitei (de exemplu un link de pe o rețea socială), tipul dispozitivului și browserului. Dacă browserul dumneavoastră trimite semnalul «Do Not Track» sau «Global Privacy Control», nu sunteți numărat.”</em></p>
<?php
admin_componenta('card', ['titlu' => 'Confidențialitatea', 'continut' => (string) ob_get_clean(), 'id' => 'st-confidentialitate']);
