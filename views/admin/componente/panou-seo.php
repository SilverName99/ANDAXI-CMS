<?php
/**
 * Panoul SEO al unei pagini (WP-37, B1; App\Support\Seo): „Cum apare în Google” (titlul, adresa, descrierea, cum se
 * schimbă în timp ce scrii), numărul caracterelor (60 / 155), verificările (lipsă, prea lung, același titlu sau aceeași
 * descriere pe altă pagină) și câmpurile: titlul și descrierea pentru Google, canonicalul, robots, tipul la distribuire
 * și textul de deasupra listei. Același panou în editorul paginii, al produsului, al categoriei, mărcii, etichetei, al
 * articolului și al categoriei sau etichetei de blog. Comportamentul: public/assets/js/admin/seo.js.
 *
 * Numele câmpurilor rămân cele de până acum (seo_title, seo_description, seo_canonical_url), deci formularul trimite
 * același lucru; câmpurile noi (seo_robots, seo_og_type, seo_intro_html) se scriu doar dacă sunt în formular (un
 * formular vechi nu le șterge).
 *
 * @var string $tip              tipul paginii (Seo::TIPURI)
 * @var string $ref              id-ul sau slug-ul paginii ('' la o pagină nouă)
 * @var string $id               prefixul id-urilor (unic pe pagină)
 * @var array  $valori           title, description, canonical_url, robots, og_type, intro_html
 * @var list<string> $campuri    ce câmpuri arată: titlu, descriere, canonical, robots, og, intro (implicit primele cinci)
 * @var array  $nume             numele câmpurilor, dacă diferă (titlu => seo_title …)
 * @var array  $iduri            id-urile câmpurilor, dacă pagina le cere pe ale ei (titlu => p_seo_title …)
 * @var string $form             opțional: id-ul formularului (câmpuri în afara lui, atributul form)
 * @var string $adresa           adresa publică a paginii (/produs/x), pentru previzualizare
 * @var string $prefix_adresa    opțional: prefixul adresei când slug-ul vine din alt câmp (/produs/)
 * @var string $sursa_slug       opțional: selectorul câmpului cu slug-ul
 * @var string $sursa_titlu      opțional: selectorul câmpului cu numele (titlul implicit = „{nume} | {magazin}”)
 * @var string $sursa_descriere  opțional: selectorul câmpului cu descrierea implicită
 * @var string $sursa_ref        opțional: selectorul câmpului cu id-ul (editorul produsului)
 * @var string $titlu_implicit   opțional: titlul pe care îl primește Google fără câmpul completat
 * @var string $nume_magazin     numele magazinului din titluri
 * @var list<array> $verificari  opțional: verificările făcute deja pe server (Seo::verificari)
 * @var int    $max_titlu        opțional: maxlength pentru titlu (implicit 255)
 * @var int    $max_descriere    opțional: maxlength pentru descriere (implicit fără)
 * @var string $nota             opțional: o propoziție sub previzualizare
 * @var bool   $previzualizare   opțional: false = fără „Cum apare în Google” (editorul articolului are cardul lui, BV 49c4f5b)
 */
$tipSeo = \App\Support\Seo::tipValid((string) ($tip ?? ''));
$valori = is_array($valori ?? null) ? $valori : [];
$campuri = is_array($campuri ?? null) ? $campuri : ['titlu', 'descriere', 'canonical', 'robots', 'og'];
$numeCampuri = array_merge(['titlu' => 'seo_title', 'descriere' => 'seo_description', 'canonical' => 'seo_canonical_url',
    'robots' => 'seo_robots', 'og' => 'seo_og_type', 'intro' => 'seo_intro_html'], is_array($nume ?? null) ? $nume : []);
$prefixId = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($id ?? ('seo-' . $tipSeo))) ?: 'seo';
$iduriCampuri = is_array($iduri ?? null) ? $iduri : [];
$idCamp = static fn (string $camp): string => (string) ($iduriCampuri[$camp] ?? ($prefixId . '-' . $camp));
$formAtribut = trim((string) ($form ?? '')) !== '' ? ' form="' . adm_e($form) . '"' : '';
$verificariServer = is_array($verificari ?? null) ? $verificari : [];
$valoare = static fn (string $cheie): string => trim((string) ($valori[$cheie] ?? ''));
$limitaTitlu = \App\Support\Seo::LIMITA_TITLU;
$limitaDescriere = \App\Support\Seo::LIMITA_DESCRIERE;
// Toată configurarea într-un singur atribut (JSON), citit de seo.js; elementele dinăuntru se găsesc după clasă.
$configurare = array_filter([
    'tip' => $tipSeo,
    'ref' => (string) ($ref ?? ''),
    'verifica' => \App\Support\Seo::adresaVerificare($tipSeo),
    'adresa' => (string) ($adresa ?? ''),
    'prefix' => (string) ($prefix_adresa ?? ''),
    'sursaSlug' => (string) ($sursa_slug ?? ''),
    'sursaTitlu' => (string) ($sursa_titlu ?? ''),
    'sursaDescriere' => (string) ($sursa_descriere ?? ''),
    'sursaRef' => (string) ($sursa_ref ?? ''),
    'titluImplicit' => (string) ($titlu_implicit ?? ''),
    'magazin' => (string) ($nume_magazin ?? \App\Support\Seo::numeMagazinCurent()),
], static fn (string $v): bool => $v !== '');
?>
<div class="adm-seo" data-adm-seo="<?= adm_e((string) json_encode($configurare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <?php if (($previzualizare ?? true) !== false): ?>
    <div class="adm-seo__google" aria-live="polite">
        <span class="adm-seo__eticheta">Cum apare în Google (aproximativ)</span>
        <span class="adm-seo__adresa"><?= adm_e((string) ($adresa ?? '')) ?></span>
        <span class="adm-seo__titlu"><?= adm_e($valoare('title') !== '' ? $valoare('title') : (string) ($titlu_implicit ?? '')) ?></span>
        <span class="adm-seo__text"><?= adm_e($valoare('description')) ?></span>
        <span class="adm-seo__numar"><span class="adm-seo__numar-titlu"><?= mb_strlen($valoare('title')) ?></span>/<?= $limitaTitlu ?> caractere în titlu · <span class="adm-seo__numar-descriere"><?= mb_strlen($valoare('description')) ?></span>/<?= $limitaDescriere ?> în descriere</span>
    </div>
    <?php endif; ?>
    <?php if (trim((string) ($nota ?? '')) !== ''): ?>
        <p class="adm-seo__nota"><?= adm_e($nota) ?></p>
    <?php endif; ?>
    <ul class="adm-seo__verificari" role="status">
        <?php foreach ($verificariServer as $v): ?>
            <li class="adm-seo__verificare adm-seo__verificare--<?= adm_e((string) ($v['cod'] ?? '')) ?>"><?= adm_e((string) ($v['text'] ?? '')) ?>
                <?php foreach ((array) ($v['alte'] ?? []) as $alta): ?>
                    <a href="<?= adm_e((string) ($alta['link'] ?? '')) ?>"><?= adm_e((string) ($alta['nume'] ?? '')) ?></a>
                <?php endforeach; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="adm-seo__campuri">
        <?php if (in_array('titlu', $campuri, true)): ?>
            <div class="field">
                <label for="<?= adm_e($idCamp('titlu')) ?>">Titlu pentru Google</label>
                <input type="text" class="adm-seo__camp-titlu" id="<?= adm_e($idCamp('titlu')) ?>" name="<?= adm_e($numeCampuri['titlu']) ?>"<?= $formAtribut ?>
                       value="<?= adm_e($valoare('title')) ?>" maxlength="<?= (int) ($max_titlu ?? 255) ?>"
                       placeholder="<?= adm_e((string) ($titlu_implicit ?? '') !== '' ? (string) $titlu_implicit : 'Gol = titlul paginii | numele magazinului') ?>">
            </div>
        <?php endif; ?>
        <?php if (in_array('descriere', $campuri, true)): ?>
            <div class="field">
                <label for="<?= adm_e($idCamp('descriere')) ?>">Descriere pentru Google</label>
                <textarea class="adm-seo__camp-descriere" id="<?= adm_e($idCamp('descriere')) ?>" name="<?= adm_e($numeCampuri['descriere']) ?>"<?= $formAtribut ?> rows="3"<?= isset($max_descriere) ? ' maxlength="' . (int) $max_descriere . '"' : '' ?>
                          placeholder="Cam 120–155 de caractere: ce găsește omul pe pagină."><?= adm_e($valoare('description')) ?></textarea>
            </div>
        <?php endif; ?>
        <?php if (in_array('canonical', $campuri, true)): ?>
            <div class="field">
                <label for="<?= adm_e($idCamp('canonical')) ?>">Adresa canonică (opțional)</label>
                <input type="url" id="<?= adm_e($idCamp('canonical')) ?>" name="<?= adm_e($numeCampuri['canonical']) ?>"<?= $formAtribut ?>
                       value="<?= adm_e($valoare('canonical_url')) ?>" placeholder="Gol = adresa paginii. Doar dacă aceeași pagină are două adrese.">
            </div>
        <?php endif; ?>
        <?php if (in_array('robots', $campuri, true) || in_array('og', $campuri, true)): ?>
            <div class="adm-seo__rand">
                <?php if (in_array('robots', $campuri, true)): ?>
                    <div class="field">
                        <label for="<?= adm_e($idCamp('robots')) ?>">În Google</label>
                        <select id="<?= adm_e($idCamp('robots')) ?>" name="<?= adm_e($numeCampuri['robots']) ?>"<?= $formAtribut ?>>
                            <?php foreach (\App\Support\Seo::ROBOTS as $cheie => $text): ?>
                                <option value="<?= adm_e($cheie) ?>"<?= $valoare('robots') === $cheie ? ' selected' : '' ?>><?= adm_e($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (in_array('og', $campuri, true)): ?>
                    <div class="field">
                        <label for="<?= adm_e($idCamp('og')) ?>">Tipul la distribuire (og:type)</label>
                        <select id="<?= adm_e($idCamp('og')) ?>" name="<?= adm_e($numeCampuri['og']) ?>"<?= $formAtribut ?>>
                            <?php foreach (\App\Support\Seo::OG_TIPURI as $cheie => $text): ?>
                                <option value="<?= adm_e($cheie) ?>"<?= $valoare('og_type') === $cheie ? ' selected' : '' ?>><?= adm_e($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (in_array('intro', $campuri, true)): ?>
            <div class="field">
                <label for="<?= adm_e($idCamp('intro')) ?>">Textul de deasupra listei (opțional)</label>
                <textarea id="<?= adm_e($idCamp('intro')) ?>" name="<?= adm_e($numeCampuri['intro']) ?>"<?= $formAtribut ?> rows="4"
                          placeholder="Câteva propoziții despre ce găsește omul aici (HTML simplu: paragrafe, linkuri, liste). Gol = doar lista."><?= adm_e($valoare('intro_html')) ?></textarea>
            </div>
        <?php endif; ?>
    </div>
</div>
