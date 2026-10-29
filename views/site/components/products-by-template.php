<?php
/**
 * Produsele care folosesc un anumit șablon de produs (WP-19: C14e, din Biovitality 1d4bee2), puse în pagină prin
 * {{products_by_template:slug-sablon|limit=…|link=…|text=…|titlu=…|subtitlu=…}}. Cardurile sunt exact cele din catalog
 * (product-card.php, cu stilurile din product-card-styles.php), ca secțiunea să nu arate ca un corp străin. Sub ele
 * poate veni un banner către pagina unde stau toate.
 *
 * Așteaptă: $products, $descriereImplicita, $sectionTitle, $sectionSubtitle, $bannerUrl, $bannerText.
 */
$products = is_array($products ?? null) ? $products : [];
$descriereImplicita = (string) ($descriereImplicita ?? '');
$sectionTitle = trim((string) ($sectionTitle ?? ''));
$sectionSubtitle = trim((string) ($sectionSubtitle ?? ''));
$bannerUrl = trim((string) ($bannerUrl ?? ''));
$bannerText = trim((string) ($bannerText ?? ''));
// Pluginurile, ca în catalog: fără magazin cardurile n-au preț și coș; stelele doar cu recenziile.
$arePreturi = \App\Support\Plugins::vizibil('magazin');
$poateCumpara = \App\Support\Plugins::permiteInceput('magazin');
$areRecenzii = \App\Support\Plugins::vizibil('recenzii');
if ($products === []) {
    return;
}
?>
<style>
<?php include __DIR__ . '/product-card-styles.php'; ?>
.bv-tpl-products{background:#fff;padding:26px 0 10px;}
.bv-tpl-products__head{width:min(1200px,92%);margin:0 auto 16px;text-align:center;}
.bv-tpl-products__title{margin:0;color:#152238;font:700 30px/1.15 Georgia,"Times New Roman",serif;}
.bv-tpl-products__subtitle{margin:8px 0 0;color:#566479;font:400 15px/1.5 "DM Sans",Arial,sans-serif;}
.bv-tpl-products__grid{width:min(1200px,92%);margin:0 auto;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;}
.bv-tpl-products__banner{
    width:min(1200px,92%);margin:18px auto 0;padding:16px 22px;
    display:flex;align-items:center;justify-content:center;gap:10px;
    border-radius:16px;background:linear-gradient(135deg,#eef7f0,#e3f0e8);
    color:#0f7b53;text-decoration:none;
    font:700 15px/1.2 "DM Sans",Arial,sans-serif;
    transition:background .2s ease,transform .2s ease;
}
.bv-tpl-products__banner:hover{background:linear-gradient(135deg,#e3f0e8,#d6e9dd);transform:translateY(-1px);}
.bv-tpl-products__banner svg{width:18px;height:18px;display:block;}
.bv-tpl-products .bv-popular__grid > .bv-popular-card{border-radius:22px !important;overflow:hidden !important;clip-path:inset(0 round 22px);}
.bv-tpl-products .bv-popular__grid > .bv-popular-card .bv-popular-card__media-wrap{border-radius:22px 22px 0 0 !important;overflow:hidden !important;}
.bv-tpl-products .bv-popular__grid > .bv-popular-card .bv-popular-card__media{border-radius:14px !important;overflow:hidden !important;}
@media (max-width:1000px){
    .bv-tpl-products__grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .bv-tpl-products__title{font-size:26px;}
}
@media (max-width:560px){
    .bv-tpl-products__grid{grid-template-columns:1fr;}
}
</style>
<section class="bv-tpl-products">
<?php if ($sectionTitle !== '' || $sectionSubtitle !== ''): ?>
    <div class="bv-tpl-products__head">
<?php if ($sectionTitle !== ''): ?>
        <h2 class="bv-tpl-products__title"><?= htmlspecialchars($sectionTitle, ENT_QUOTES) ?></h2>
<?php endif; ?>
<?php if ($sectionSubtitle !== ''): ?>
        <p class="bv-tpl-products__subtitle"><?= htmlspecialchars($sectionSubtitle, ENT_QUOTES) ?></p>
<?php endif; ?>
    </div>
<?php endif; ?>
    <div class="bv-popular__grid bv-tpl-products__grid">
      <?php foreach ($products as $product): ?>
<?php include __DIR__ . '/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
<?php if ($bannerUrl !== ''): ?>
    <a class="bv-tpl-products__banner" href="<?= htmlspecialchars($bannerUrl, ENT_QUOTES) ?>">
        <span><?= htmlspecialchars($bannerText !== '' ? $bannerText : 'Vezi toate', ENT_QUOTES) ?></span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </a>
<?php endif; ?>
</section>
