<?php
/**
 * Marketing → Campanii pe site → o campanie (pluginul campanii; App\Http\Controllers\CampaniiController): datele și
 * fereastra, pagina de deschidere (din Conținut → Pagini), produsele cu insignă și bannerul de sus, cu previzualizarea lui
 * (se schimbă cât scrii, fără salvare).
 *
 * @var array<string, mixed> $campanie
 * @var list<int> $produseAlese
 * @var list<string> $erori
 * @var list<array<string, mixed>> $pagini
 * @var list<array<string, mixed>> $produse
 * @var bool $disponibil
 */
use App\Support\Campanii;

$c = array_merge(Campanii::noua(), is_array($campanie ?? null) ? $campanie : []);
$id = (int) ($c['id'] ?? 0);
$erori = is_array($erori ?? null) ? $erori : [];
$pagini = is_array($pagini ?? null) ? $pagini : [];
$produse = is_array($produse ?? null) ? $produse : [];
$alese = array_flip(is_array($produseAlese ?? null) ? $produseAlese : []);
$areCatalog = \App\Support\Plugins::vizibil('catalog');
$stare = $id > 0 ? Campanii::stare($c) : null;
$bifat = static fn (mixed $v): string => (int) $v === 1 ? ' checked' : '';

admin_componenta('antet-pagina', [
    'titlu' => $id > 0 ? (string) $c['nume'] : 'Campanie nouă',
    'nivel' => 1,
    'subtitlu' => $stare !== null ? Campanii::STARI[$stare] . ' · ' . Campanii::interval($c) : 'Datele, pagina, produsele și bannerul campaniei.',
    'actiuni' => '<a class="btn btn-secondary" href="/admin/campanii">Toate campaniile</a>'
        . ($id > 0 && trim((string) $c['slug']) !== '' && (int) ($c['pagina_id'] ?? 0) > 0
            ? ' <a class="btn btn-secondary" href="' . adm_e(Campanii::adresaPagina((string) $c['slug'], '/')) . '" target="_blank" rel="noopener">Vezi pagina campaniei</a>'
            : ''),
]);
?>
<?php if (!$disponibil): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Tabelele campaniilor lipsesc',
    'text' => 'Rulează migrările (scripts/migreaza.php --aplica); până atunci campania nu se poate salva.']); ?>
<?php endif; ?>
<?php if ($erori !== []): ?>
<?php admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => 'Campania nu a fost salvată', 'text' => implode(' ', $erori)]); ?>
<?php endif; ?>
<?php foreach (Campanii::FONTURI as $font): ?>
<?php if ($font['css'] !== null): ?>
<link rel="stylesheet" href="<?= adm_e($font['css']) ?>">
<?php endif; ?>
<?php endforeach; ?>
<style>
.campanie-editor .form-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.campanie-editor .campanie-camp-lat{grid-column:1/-1;}
.campanie-editor .campanie-bifa{display:flex;gap:8px;align-items:flex-start;font-weight:500;}
.campanie-editor .campanie-bifa input{margin-top:3px;}
.campanie-editor .campanie-culori{display:flex;gap:8px;align-items:center;}
.campanie-editor .campanie-culori input[type=color]{width:48px;height:38px;padding:2px;}
.campanie-editor .campanie-culori input[type=text]{flex:1;min-width:0;font-family:ui-monospace,monospace;}
.campanie-editor .campanie-produse{max-height:340px;overflow:auto;border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;background:#fff;}
.campanie-editor .campanie-produse label{display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid #f1f5f9;font-weight:400;}
.campanie-editor .campanie-produse label:last-child{border-bottom:0;}
.campanie-editor .campanie-previzualizare{position:relative;height:190px;overflow:auto;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc;}
.campanie-editor .campanie-previzualizare__pagina{padding:14px 16px;color:#475569;font-size:13px;line-height:1.6;}
.campanie-editor .campanie-previzualizare__pagina p{margin:0 0 10px;}
.campanie-editor .campanie-insigna-proba{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:6px;}
@media (max-width: 760px){.campanie-editor .form-grid{grid-template-columns:minmax(0,1fr);}}
</style>
<form method="post" action="/admin/campanii/salveaza" class="campanie-editor" id="campanie-formular">
    <?= \App\Support\AdminCsrf::camp() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

<?php ob_start(); ?>
    <div class="form-grid">
        <div class="field">
            <label for="campanie-nume">Numele campaniei</label>
            <input type="text" id="campanie-nume" name="nume" maxlength="160" required value="<?= adm_e((string) $c['nume']) ?>" placeholder="ex: Săptămâna vitaminelor">
        </div>
        <div class="field">
            <label for="campanie-slug">Adresa paginii</label>
            <input type="text" id="campanie-slug" name="slug" maxlength="160" value="<?= adm_e((string) $c['slug']) ?>" placeholder="gol = din nume" spellcheck="false">
            <small class="muted">Pagina campaniei: /campanie/<span data-campanie-slug><?= adm_e((string) ($c['slug'] !== '' ? $c['slug'] : '…')) ?></span></small>
        </div>
        <div class="field">
            <label for="campanie-incepe">Începe la</label>
            <input type="datetime-local" id="campanie-incepe" name="incepe_la" value="<?= adm_e(Campanii::pentruCamp($c['incepe_la'])) ?>">
        </div>
        <div class="field">
            <label for="campanie-termina">Se termină la</label>
            <input type="datetime-local" id="campanie-termina" name="se_termina_la" value="<?= adm_e(Campanii::pentruCamp($c['se_termina_la'])) ?>">
        </div>
        <div class="field campanie-camp-lat">
            <label class="campanie-bifa"><input type="checkbox" name="pornita" value="1"<?= $bifat($c['pornita']) ?>>
                <span>Pornită: între început și sfârșit, campania se vede pe site (pagina, insignele, bannerul). În afara intervalului nu se vede nimic.</span></label>
            <small class="muted">Ora României. Pe site poate fi activă o singură campanie odată: două campanii pornite nu pot avea intervale care se suprapun.</small>
        </div>
    </div>
<?php admin_componenta('sectiune-formular', ['id' => 'campanie-date', 'titlu' => 'Campania și perioada', 'continut' => (string) ob_get_clean()]); ?>

<?php ob_start(); ?>
    <div class="form-grid">
        <div class="field">
            <label for="campanie-pagina">Pagina campaniei</label>
            <select id="campanie-pagina" name="pagina_id">
                <option value="0">— nicio pagină —</option>
                <?php foreach ($pagini as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (int) ($c['pagina_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>>
                        <?= adm_e((string) $p['title']) ?> (/<?= adm_e((string) $p['slug']) ?>)<?= (int) $p['is_published'] !== 1 ? ' — ciornă' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="muted">Conținutul se scrie în <a href="/admin/pages">Conținut → Pagini</a><?= (int) ($c['pagina_id'] ?? 0) > 0
                ? ' (<a href="/admin/pages/' . (int) $c['pagina_id'] . '/edit">editează pagina aleasă</a>)' : '' ?>.
                Lasă pagina nepublicată (ciornă): așa se vede doar prin campanie, doar cât campania e activă.</small>
        </div>
        <div class="field">
            <label for="campanie-text-inapoi">Textul butonului din stânga sus</label>
            <input type="text" id="campanie-text-inapoi" name="text_inapoi" maxlength="120" value="<?= adm_e((string) ($c['text_inapoi'] ?? '')) ?>" placeholder="<?= adm_e(Campanii::TEXT_INAPOI) ?>">
            <small class="muted">Butonul duce vizitatorul la pagina pe care o ceruse când a intrat pe site.</small>
        </div>
        <div class="field campanie-camp-lat">
            <label class="campanie-bifa"><input type="checkbox" name="pagina_la_intrare" value="1"<?= $bifat($c['pagina_la_intrare']) ?>>
                <span>Arată pagina campaniei la prima intrare pe site (o dată pe vizită)</span></label>
            <small class="muted">Doar oamenilor care vin din afara site-ului, pe o pagină obișnuită: nu roboților (Google vede site-ul ca de obicei), nu în coș, la plată sau în cont.</small>
        </div>
        <div class="field">
            <label class="campanie-bifa"><input type="checkbox" name="fara_antet_subsol" value="1"<?= $bifat($c['fara_antet_subsol'] ?? 0) ?>>
                <span>Pagina campaniei fără antetul și subsolul site-ului</span></label>
            <small class="muted">Doar la /campanie/…: fără meniul de sus, subsol, sertarul „Oferte” și coșul plutitor. Bara „Înapoi” și bannerul de cookie-uri rămân. La adresa proprie a paginii, site-ul arată ca de obicei.</small>
        </div>
        <div class="field">
            <label for="campanie-bara">Bara de sus (butonul „Înapoi”)</label>
            <select id="campanie-bara" name="bara_inchisa">
                <option value="0"<?= (int) ($c['bara_inchisa'] ?? 0) !== 1 ? ' selected' : '' ?>>Deschisă (albă)</option>
                <option value="1"<?= (int) ($c['bara_inchisa'] ?? 0) === 1 ? ' selected' : '' ?>>Închisă (neagră), pentru o pagină cu fundal închis</option>
            </select>
        </div>
    </div>
<?php admin_componenta('sectiune-formular', ['id' => 'campanie-pagina-sectiune', 'titlu' => 'Pagina de deschidere', 'continut' => (string) ob_get_clean()]); ?>

<?php if ($areCatalog): ?>
<?php ob_start(); ?>
    <div class="form-grid">
        <div class="field">
            <label for="campanie-insigna-text">Textul insignei</label>
            <input type="text" id="campanie-insigna-text" name="insigna_text" maxlength="40" value="<?= adm_e((string) ($c['insigna_text'] ?? '')) ?>" placeholder="<?= adm_e(Campanii::INSIGNA_IMPLICITA) ?>">
        </div>
        <div class="field">
            <span>Culorile insignei (fundal, text)</span>
            <div class="campanie-culori">
                <input type="color" aria-label="Fundalul insignei" data-campanie-culoare="campanie-insigna-fundal" value="<?= adm_e(Campanii::culoare((string) $c['insigna_fundal'], Campanii::IMPLICITE['insigna_fundal'])) ?>">
                <input type="text" id="campanie-insigna-fundal" name="insigna_fundal" maxlength="7" value="<?= adm_e(Campanii::culoare((string) $c['insigna_fundal'], Campanii::IMPLICITE['insigna_fundal'])) ?>" aria-label="Fundalul insignei (cod)">
                <input type="color" aria-label="Textul insignei" data-campanie-culoare="campanie-insigna-culoare" value="<?= adm_e(Campanii::culoare((string) $c['insigna_culoare'], Campanii::IMPLICITE['insigna_culoare'])) ?>">
                <input type="text" id="campanie-insigna-culoare" name="insigna_culoare" maxlength="7" value="<?= adm_e(Campanii::culoare((string) $c['insigna_culoare'], Campanii::IMPLICITE['insigna_culoare'])) ?>" aria-label="Textul insignei (cod)">
            </div>
            <div class="campanie-insigna-proba">Așa arată: <span data-campanie-insigna style="display:inline-block;padding:6px 10px;border-radius:999px;font:800 11px/1.15 'DM Sans',Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase;"></span></div>
        </div>
        <div class="field campanie-camp-lat">
            <label for="campanie-produse-cauta">Produsele campaniei (<span data-campanie-numar><?= count($alese) ?></span> alese)</label>
            <input type="search" id="campanie-produse-cauta" placeholder="Caută un produs după nume sau cod" data-campanie-cauta>
            <div class="campanie-produse" data-campanie-produse>
                <?php if ($produse === []): ?>
                    <p class="muted">Niciun produs în catalog.</p>
                <?php endif; ?>
                <?php foreach ($produse as $p): ?>
                    <?php $motivReducere = (string) ($p['motiv_reducere'] ?? ''); ?>
                    <label data-cauta="<?= adm_e(mb_strtolower((string) $p['name'] . ' ' . (string) ($p['sku'] ?? ''))) ?>"<?= $motivReducere !== '' ? ' data-are-reducere style="opacity:.6;"' : '' ?>>
                        <input type="checkbox" name="produse[]" value="<?= (int) $p['id'] ?>"<?= $motivReducere !== '' ? ' disabled' : (isset($alese[(int) $p['id']]) ? ' checked' : '') ?>>
                        <span><?= adm_e((string) $p['name']) ?><?= trim((string) ($p['sku'] ?? '')) !== '' ? ' <small class="muted adm-mono">' . adm_e((string) $p['sku']) . '</small>' : '' ?><?= (int) ($p['is_active'] ?? 1) !== 1 ? ' <small class="muted">(inactiv)</small>' : '' ?><?= $motivReducere !== '' ? ' <small class="muted">(are deja o reducere: ' . adm_e($motivReducere) . (isset($alese[(int) $p['id']]) ? '; scos din campanie la salvare' : '') . ')</small>' : '' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <small class="muted">Produsele alese primesc insigna pe pagina lor și pe cardurile din magazin și din categorii, doar cât campania e activă. Un produs care are deja o reducere (preț redus, o perioadă de reducere în curs, oferte BBD) nu se poate alege.</small>
        </div>
        <div class="field campanie-camp-lat">
            <p class="muted" style="margin:0;">Codul <code>{{campanie_produse}}</code>, scris în conținutul paginii campaniei, pune acolo produsele alese, ca o grilă de carduri (poza, numele, insigna, prețul, „Adaugă în coș”, „Vezi produsul”). Doar pe /campanie/…; la adresa proprie a paginii iese gol.</p>
        </div>
        <div class="field">
            <label for="campanie-carduri">Cardurile produselor</label>
            <select id="campanie-carduri" name="carduri_inchise">
                <option value="0"<?= (int) ($c['carduri_inchise'] ?? 0) !== 1 ? ' selected' : '' ?>>Deschise (albe)</option>
                <option value="1"<?= (int) ($c['carduri_inchise'] ?? 0) === 1 ? ' selected' : '' ?>>Închise (#161616 pe negru, text alb)</option>
            </select>
        </div>
        <div class="field">
            <label for="campanie-text-sub-pret">Text sub preț (opțional)</label>
            <input type="text" id="campanie-text-sub-pret" name="text_sub_pret" maxlength="160" value="<?= adm_e((string) ($c['text_sub_pret'] ?? '')) ?>" placeholder="ex: 2 bucăți: {pret*1.5}">
            <small class="muted"><code>{pret}</code> = prețul produsului; <code>{pret*1.5}</code> = prețul înmulțit cu 1,5. Gol = fără rând.</small>
        </div>
    </div>
<?php admin_componenta('sectiune-formular', ['id' => 'campanie-produse-sectiune', 'titlu' => 'Produsele cu insignă', 'continut' => (string) ob_get_clean()]); ?>
<?php endif; ?>

<?php ob_start(); ?>
    <div class="form-grid">
        <div class="field campanie-camp-lat">
            <label class="campanie-bifa"><input type="checkbox" name="banner_pornit" value="1" data-campanie-banner-pornit<?= $bifat($c['banner_pornit']) ?>>
                <span>Arată bannerul în capul paginilor site-ului</span></label>
        </div>
        <div class="field campanie-camp-lat">
            <label for="campanie-banner-text">Textul bannerului</label>
            <input type="text" id="campanie-banner-text" name="banner_text" maxlength="300" value="<?= adm_e((string) ($c['banner_text'] ?? '')) ?>" placeholder="ex: -20% la vitamine până duminică">
        </div>
        <div class="field">
            <label for="campanie-banner-link">Link (opțional)</label>
            <input type="text" id="campanie-banner-link" name="banner_link" maxlength="500" value="<?= adm_e((string) ($c['banner_link'] ?? '')) ?>" placeholder="gol = pagina campaniei" spellcheck="false">
            <small class="muted">Gol: bannerul duce la pagina campaniei (cât ea e pornită), iar butonul „Înapoi” de acolo întoarce vizitatorul la pagina de pe care a apăsat. Altfel: o adresă a site-ului (/categorie/vitamine) sau https://….</small>
        </div>
        <div class="field">
            <span>Culorile bannerului (fundal, text)</span>
            <div class="campanie-culori">
                <input type="color" aria-label="Fundalul bannerului" data-campanie-culoare="campanie-banner-fundal" value="<?= adm_e(Campanii::culoare((string) $c['banner_fundal'], Campanii::IMPLICITE['banner_fundal'])) ?>">
                <input type="text" id="campanie-banner-fundal" name="banner_fundal" maxlength="7" value="<?= adm_e(Campanii::culoare((string) $c['banner_fundal'], Campanii::IMPLICITE['banner_fundal'])) ?>" aria-label="Fundalul bannerului (cod)">
                <input type="color" aria-label="Textul bannerului" data-campanie-culoare="campanie-banner-culoare" value="<?= adm_e(Campanii::culoare((string) $c['banner_culoare'], Campanii::IMPLICITE['banner_culoare'])) ?>">
                <input type="text" id="campanie-banner-culoare" name="banner_culoare" maxlength="7" value="<?= adm_e(Campanii::culoare((string) $c['banner_culoare'], Campanii::IMPLICITE['banner_culoare'])) ?>" aria-label="Textul bannerului (cod)">
            </div>
        </div>
        <div class="field">
            <label for="campanie-banner-font">Fontul</label>
            <select id="campanie-banner-font" name="banner_font">
                <?php foreach (Campanii::FONTURI as $cheie => $font): ?>
                    <option value="<?= adm_e($cheie) ?>" data-familie="<?= adm_e($font['familie']) ?>"<?= (string) $c['banner_font'] === $cheie ? ' selected' : '' ?>><?= adm_e($font['eticheta']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="campanie-banner-marime">Mărimea textului (px)</label>
            <input type="number" id="campanie-banner-marime" name="banner_marime" min="<?= Campanii::MARIME_MIN ?>" max="<?= Campanii::MARIME_MAX ?>" step="1" value="<?= Campanii::marime($c['banner_marime']) ?>">
        </div>
        <div class="field campanie-camp-lat">
            <label class="campanie-bifa"><input type="checkbox" name="banner_lipit" value="1" data-campanie-banner-lipit<?= $bifat($c['banner_lipit']) ?>>
                <span>Rămâne lipit sus când pagina se derulează</span></label>
        </div>
        <div class="field campanie-camp-lat">
            <span>Previzualizare (derulează în chenar)</span>
            <div class="campanie-previzualizare" data-campanie-previzualizare>
                <?= Campanii::htmlBanner(array_merge($c, ['banner_text' => trim((string) $c['banner_text']) !== '' ? $c['banner_text'] : 'Textul bannerului']), false, Campanii::linkImplicitBanner($c, '/')) ?>
                <div class="campanie-previzualizare__pagina" aria-hidden="true">
                    <p><strong>Antetul site-ului</strong></p>
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <p>Conținutul paginii, rândul <?= $i ?>.</p>
                    <?php endfor; ?>
                </div>
            </div>
            <small class="muted" data-campanie-banner-nota></small>
        </div>
    </div>
<?php admin_componenta('sectiune-formular', ['id' => 'campanie-banner-sectiune', 'titlu' => 'Bannerul de sus', 'continut' => (string) ob_get_clean()]); ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
        <button class="btn" type="submit">Salvează campania</button>
        <a class="btn btn-light" href="/admin/campanii">Renunță</a>
    </div>
</form>
<?php if ($id > 0): ?>
<form method="post" action="/admin/campanii/<?= $id ?>/sterge" onsubmit="return confirm(<?= adm_e((string) json_encode('Ștergi campania „' . (string) $c['nume'] . '”? Pagina din Conținut → Pagini rămâne.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) ?>);">
    <?= \App\Support\AdminCsrf::camp() ?>
    <button class="btn btn-secondary" type="submit">Șterge campania</button>
</form>
<?php endif; ?>
<script>
(function () {
    var form = document.getElementById('campanie-formular');
    if (!form) { return; }
    var $ = function (sel) { return form.querySelector(sel); };
    var hex = function (v, implicit) {
        v = String(v || '').trim().toLowerCase();
        if (/^#[0-9a-f]{6}$/.test(v)) { return v; }
        var m = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(v);
        return m ? '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3] : implicit;
    };
    // Perechile culoare / cod: oricare se schimbă, cealaltă o urmează.
    form.querySelectorAll('[data-campanie-culoare]').forEach(function (alegator) {
        var text = document.getElementById(alegator.getAttribute('data-campanie-culoare'));
        alegator.addEventListener('input', function () { text.value = alegator.value; actualizeaza(); });
        text.addEventListener('input', function () { var v = hex(text.value, ''); if (v) { alegator.value = v; } actualizeaza(); });
    });
    var banner = form.querySelector('[data-campanie-previzualizare] [data-campanie-banner]');
    var insigna = form.querySelector('[data-campanie-insigna]');
    var nota = form.querySelector('[data-campanie-banner-nota]');
    function actualizeaza() {
        if (banner) {
            var text = ($('#campanie-banner-text').value || '').trim() || 'Textul bannerului';
            var link = ($('#campanie-banner-link').value || '').trim()
                || ($('[name="pagina_la_intrare"]').checked && $('#campanie-pagina').value !== '0' ? '/campanie/' : '');
            var tinta = banner.querySelector('.campanie-banner__text');
            tinta.textContent = '';
            var nod = tinta;
            if (link !== '') {
                nod = document.createElement('a');
                nod.href = '#';
                nod.setAttribute('aria-disabled', 'true');
                nod.style.cssText = 'color:inherit;text-decoration:underline;text-underline-offset:3px;';
                nod.addEventListener('click', function (e) { e.preventDefault(); });
                tinta.appendChild(nod);
            }
            nod.textContent = text;
            banner.style.background = hex($('#campanie-banner-fundal').value, '<?= Campanii::IMPLICITE['banner_fundal'] ?>');
            banner.style.color = hex($('#campanie-banner-culoare').value, '<?= Campanii::IMPLICITE['banner_culoare'] ?>');
            var optiune = $('#campanie-banner-font').selectedOptions[0];
            banner.style.fontFamily = optiune ? optiune.getAttribute('data-familie') : 'inherit';
            var marime = parseInt($('#campanie-banner-marime').value, 10);
            marime = isNaN(marime) ? 15 : Math.max(<?= Campanii::MARIME_MIN ?>, Math.min(<?= Campanii::MARIME_MAX ?>, marime));
            banner.style.fontSize = marime + 'px';
            var lipit = $('[data-campanie-banner-lipit]').checked;
            banner.style.position = lipit ? 'sticky' : 'relative';
            banner.style.top = lipit ? '0' : '';
            banner.style.zIndex = lipit ? '1040' : '5';
            var pornit = $('[data-campanie-banner-pornit]').checked;
            banner.style.opacity = pornit ? '1' : '.45';
            nota.textContent = (pornit ? 'Bannerul e pornit' : 'Bannerul e oprit (nu apare pe site)')
                + (lipit ? '; rămâne lipit sus la derulare.' : '; pleacă odată cu pagina la derulare.');
        }
        if (insigna) {
            insigna.textContent = ($('#campanie-insigna-text').value || '').trim() || '<?= Campanii::INSIGNA_IMPLICITA ?>';
            insigna.style.background = hex($('#campanie-insigna-fundal').value, '<?= Campanii::IMPLICITE['insigna_fundal'] ?>');
            insigna.style.color = hex($('#campanie-insigna-culoare').value, '<?= Campanii::IMPLICITE['insigna_culoare'] ?>');
        }
        var slug = $('#campanie-slug').value.trim() || $('#campanie-nume').value.trim();
        var tintaSlug = form.querySelector('[data-campanie-slug]');
        if (tintaSlug) {
            tintaSlug.textContent = slug.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || '…';
        }
    }
    form.addEventListener('input', actualizeaza);
    form.addEventListener('change', actualizeaza);
    // Căutarea în lista produselor și numărul celor alese.
    var cauta = form.querySelector('[data-campanie-cauta]');
    var lista = form.querySelector('[data-campanie-produse]');
    if (cauta && lista) {
        cauta.addEventListener('input', function () {
            var q = cauta.value.trim().toLowerCase();
            lista.querySelectorAll('label[data-cauta]').forEach(function (rand) {
                rand.hidden = q !== '' && rand.getAttribute('data-cauta').indexOf(q) === -1;
            });
        });
        cauta.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
        lista.addEventListener('change', function () {
            form.querySelector('[data-campanie-numar]').textContent = String(lista.querySelectorAll('input:checked').length);
        });
    }
    actualizeaza();
})();
</script>
