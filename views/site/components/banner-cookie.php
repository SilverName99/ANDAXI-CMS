<?php
/**
 * Bannerul de cookie-uri (Admin → Setări → Cookie și popup; docs/PLUGINURI.md, „Bannerul de cookie-uri”), inclus de
 * views/layout.php la începutul lui <body>, ca primul Tab să ajungă la el.
 *
 * Categoriile vin din serviciile active (ServiciiCookie): o categorie fără nimic activ nu apare, iar fără nicio categorie
 * nu e nimic de cerut (nici banner, nici butonul din colț). Cele trei butoane („Accept toate”, „Refuz”, „Setări”) au aceeași
 * mărime și formă; primele două, aceeași culoare (Setări → Cookies, altfel culoarea temei). După o alegere rămâne doar
 * butonul mic din colț, care redeschide bannerul. Comportamentul e în /assets/js/consimtamant.js; fără JavaScript bannerul
 * nu se arată (nimic nu pornește oricum fără acord).
 *
 * Variabile: $designSettings, $db, $floatingCartEnabled, $floatingCartConfig, $chatActiv (din layout).
 */
$serviciiCookie = \App\Support\ServiciiCookie::servicii($designSettings, $db instanceof \PDO ? $db : null);
$categoriiCookie = \App\Support\ServiciiCookie::categoriiActive($designSettings, null, $serviciiCookie);
if ((string) ($designSettings['cookie_banner_enabled'] ?? '1') !== '1' || $categoriiCookie === []) {
    return;
}
$raspunsCookie = \App\Support\CookieConsent::aRaspuns($categoriiCookie);
$alegereCookie = \App\Support\CookieConsent::alegere();
$acceptateCookie = $alegereCookie['acceptate'] ?? [];
$textCookie = trim((string) ($designSettings['cookie_banner_text'] ?? ''));
if ($textCookie === '') {
    $textCookie = \App\Support\ServiciiCookie::TEXT_IMPLICIT;
}
$linkPoliticaCookie = trim((string) ($designSettings['cookie_politica_url'] ?? ''));
$linkConfidentialitate = trim((string) ($designSettings['cookie_banner_policy_url'] ?? ''));
$culoareCookie = \App\Support\ServiciiCookie::culoareButoane($designSettings);
$culoareTextCookie = \App\Support\ServiciiCookie::culoareTextPe($culoareCookie);
// Butonul din colț: în stânga, dacă acolo nu stau coșul plutitor sau bulina de chat; altfel în dreapta; ocupate amândouă,
// în stânga, deasupra lor.
$stangaOcupata = ($floatingCartEnabled && ($floatingCartConfig['position'] ?? 'right') === 'left')
    || ($chatActiv && \App\Support\ChatLive::pozitie($designSettings) === 'bl');
$dreaptaOcupata = $floatingCartEnabled && ($floatingCartConfig['position'] ?? 'right') === 'right'
    || ($chatActiv && \App\Support\ChatLive::pozitie($designSettings) === 'br');
$parteTab = !$stangaOcupata ? 'stanga' : (!$dreaptaOcupata ? 'dreapta' : 'stanga-sus');
$numeServicii = [];
foreach ($serviciiCookie as $serviciu) {
    $numeServicii[$serviciu['categorie']][] = $serviciu['nume'];
}
$dateCookie = [
    'nume' => \App\Support\CookieConsent::nume(),
    'versiune' => \App\Support\CookieConsent::VERSIUNE,
    'litere' => \App\Support\CookieConsent::LITERE,
    'active' => $categoriiCookie,
    // Ce a primit pagina: valoarea cookie-ului văzută de server și categoriile acceptate (o alegere care le schimbă reîncarcă).
    'valoare' => $alegereCookie !== null ? (string) ($_COOKIE[\App\Support\CookieConsent::nume()] ?? '') : '',
    'acceptate' => $acceptateCookie,
    'raspuns' => $raspunsCookie,
    'zile' => (int) round(\App\Support\CookieConsent::DURATA / 86400),
];
$escCookie = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
?>
<style>
.ax-cc{position:fixed;left:0;right:0;bottom:0;z-index:99998;padding:12px;pointer-events:none;font-size:15px;line-height:1.5;color:#1f2937;}
.ax-cc[hidden]{display:none!important;}
.ax-cc__cutie{pointer-events:auto;position:relative;box-sizing:border-box;max-width:1100px;max-height:calc(100vh - 24px);overflow-y:auto;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 -6px 32px rgba(15,23,42,.18);padding:18px 20px;}
.ax-cc__cutie:focus{outline:3px solid <?= $culoareCookie ?>;outline-offset:2px;}
.ax-cc__titlu{display:block;margin:0 0 4px;font-size:16px;color:#0f172a;}
.ax-cc__text{margin:0;color:#334155;}
.ax-cc__text a,.ax-cc__cat a{color:inherit;text-decoration:underline;}
.ax-cc__setari{margin:14px 0 0;}
.ax-cc__setari[hidden]{display:none;}
.ax-cc__categorii{list-style:none;margin:0;padding:0;display:grid;gap:8px;}
.ax-cc__cat{border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;}
.ax-cc__cat-cap{display:flex;align-items:center;justify-content:space-between;gap:12px;font-weight:700;color:#0f172a;cursor:pointer;}
.ax-cc__cat-cap--fix{cursor:default;}
.ax-cc__cat p{margin:4px 0 0;font-size:14px;color:#475569;}
.ax-cc__mereu{font-size:13px;font-weight:600;color:#475569;white-space:nowrap;}
.ax-cc__cat input{position:absolute;opacity:0;width:1px;height:1px;margin:0;}
.ax-cc__comutator{flex:0 0 auto;position:relative;width:44px;height:26px;border-radius:13px;background:#cbd5e1;transition:background .15s;}
.ax-cc__comutator::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .15s;}
.ax-cc__cat input:checked+.ax-cc__comutator{background:<?= $culoareCookie ?>;}
.ax-cc__cat input:checked+.ax-cc__comutator::after{transform:translateX(18px);}
.ax-cc__cat input:focus-visible+.ax-cc__comutator{outline:3px solid #0f172a;outline-offset:2px;}
.ax-cc__butoane{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 0;}
.ax-cc__buton{flex:1 1 0;min-width:150px;min-height:46px;box-sizing:border-box;padding:10px 16px;border:2px solid <?= $culoareCookie ?>;border-radius:10px;font:inherit;font-weight:700;cursor:pointer;}
.ax-cc__buton[hidden]{display:none;}
.ax-cc__buton--plin{background:<?= $culoareCookie ?>;color:<?= $culoareTextCookie ?>;}
.ax-cc__buton--contur{background:#fff;color:#0f172a;}
.ax-cc__buton:focus-visible,.ax-cc__inchide:focus-visible,.ax-cc-tab:focus-visible{outline:3px solid #0f172a;outline-offset:2px;}
.ax-cc__inchide{position:absolute;top:8px;right:8px;width:36px;height:36px;border:0;border-radius:50%;background:#f1f5f9;color:#334155;font-size:20px;line-height:1;cursor:pointer;}
.ax-cc__inchide[hidden]{display:none;}
.ax-cc-tab{position:fixed;bottom:16px;z-index:1100;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;padding:0;border:1px solid #cbd5e1;border-radius:50%;background:#fff;color:#334155;box-shadow:0 4px 14px rgba(15,23,42,.18);cursor:pointer;}
.ax-cc-tab[hidden]{display:none;}
.ax-cc-tab--stanga{left:16px;}
.ax-cc-tab--dreapta{right:16px;}
.ax-cc-tab--stanga-sus{left:16px;bottom:96px;}
@media (max-width:560px){.ax-cc{padding:0;}.ax-cc__cutie{border-radius:14px 14px 0 0;max-height:100vh;padding:16px;}.ax-cc__buton{flex-basis:100%;}}
@media print{.ax-cc,.ax-cc-tab{display:none!important;}}
</style>
<div id="cookie-banner" class="ax-cc" role="region" aria-labelledby="ax-cc-titlu" data-ax-cc-banner<?= $raspunsCookie ? ' hidden' : '' ?>>
    <div class="ax-cc__cutie" tabindex="-1">
        <strong class="ax-cc__titlu" id="ax-cc-titlu">Cookie-uri</strong>
        <p class="ax-cc__text" id="ax-cc-text"><?= $escCookie($textCookie) ?><?php
        if ($linkPoliticaCookie !== ''): ?> <a href="<?= $escCookie($linkPoliticaCookie) ?>">Politica de cookie-uri</a><?php endif;
        if ($linkConfidentialitate !== '' && $linkConfidentialitate !== $linkPoliticaCookie): ?><?= $linkPoliticaCookie !== '' ? ' ·' : '' ?> <a href="<?= $escCookie($linkConfidentialitate) ?>">Politica de confidențialitate</a><?php endif; ?></p>
        <div class="ax-cc__setari" id="ax-cc-setari" hidden>
            <ul class="ax-cc__categorii">
                <li class="ax-cc__cat">
                    <div class="ax-cc__cat-cap ax-cc__cat-cap--fix"><span><?= $escCookie(\App\Support\ServiciiCookie::CATEGORII['necesare']['eticheta']) ?></span><span class="ax-cc__mereu">Mereu active</span></div>
                    <p><?= $escCookie(\App\Support\ServiciiCookie::CATEGORII['necesare']['descriere']) ?></p>
                </li>
<?php foreach ($categoriiCookie as $categorie): ?>
                <li class="ax-cc__cat">
                    <label class="ax-cc__cat-cap" for="ax-cc-c-<?= $categorie ?>"><span><?= $escCookie(\App\Support\ServiciiCookie::CATEGORII[$categorie]['eticheta']) ?></span><input type="checkbox" role="switch" id="ax-cc-c-<?= $categorie ?>" data-ax-cc-categorie="<?= $categorie ?>" aria-describedby="ax-cc-d-<?= $categorie ?>"<?= in_array($categorie, $acceptateCookie, true) ? ' checked' : '' ?>><span class="ax-cc__comutator" aria-hidden="true"></span></label>
                    <p id="ax-cc-d-<?= $categorie ?>"><?= $escCookie(\App\Support\ServiciiCookie::CATEGORII[$categorie]['descriere']) ?> Aici: <?= $escCookie(implode(', ', array_unique($numeServicii[$categorie] ?? []))) ?>.</p>
                </li>
<?php endforeach; ?>
            </ul>
        </div>
        <div class="ax-cc__butoane">
            <button type="button" class="ax-cc__buton ax-cc__buton--plin" data-ax-cc="toate">Accept toate</button>
            <button type="button" class="ax-cc__buton ax-cc__buton--plin" data-ax-cc="refuz">Refuz</button>
            <button type="button" class="ax-cc__buton ax-cc__buton--contur" data-ax-cc="setari" aria-expanded="false" aria-controls="ax-cc-setari">Setări</button>
            <button type="button" class="ax-cc__buton ax-cc__buton--contur" data-ax-cc="salveaza" hidden>Salvează alegerea</button>
        </div>
        <button type="button" class="ax-cc__inchide" data-ax-cc="inchide" aria-label="Închide fără să schimbi alegerea" hidden>&times;</button>
    </div>
</div>
<button type="button" class="ax-cc-tab ax-cc-tab--<?= $parteTab ?>" data-ax-cc="deschide" aria-controls="cookie-banner" aria-expanded="false" aria-label="Setări cookie-uri" title="Setări cookie-uri"<?= $raspunsCookie ? '' : ' hidden' ?>>
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.3A9 9 0 1 1 11.7 3a3 3 0 0 0 3.8 3.8 3 3 0 0 0 3.7 3.7 3 3 0 0 0 1.8 1.8z"/><circle cx="8.5" cy="10.5" r="1"/><circle cx="12" cy="15.5" r="1"/><circle cx="16" cy="14" r="1"/><circle cx="9" cy="16" r=".6"/></svg>
</button>
<noscript><style>.ax-cc,.ax-cc-tab{display:none!important;}</style></noscript>
<script type="application/json" id="ax-cc-date"><?= json_encode($dateCookie, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/consimtamant.js?v=<?= (int) (@filemtime(\App\Support\Paths::public('assets/js/consimtamant.js')) ?: 1) ?>" defer></script>
