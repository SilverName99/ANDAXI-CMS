<?php
/**
 * Un card de produs, așa cum apare în catalog (WP-19: C14e, din Biovitality 1d4bee2). Stătea direct în
 * shop-catalog.php; scos aici ca să-l folosească și {{products_by_template:…}} fără o a doua variantă care apoi ar
 * diverge. Markup-ul e cel al catalogului de azi (lățimea/înălțimea pozei, pozele refăcute, pluginurile), octet cu octet.
 *
 * Așteaptă: $product (rândul normalizat și îmbogățit al produsului), $descriereImplicita, $arePreturi, $poateCumpara,
 * $areRecenzii. Fără preț și fără coș (catalog de prezentare) partea de jos a cardului lipsește cu totul, nu rămâne o
 * cutie goală (WP-19: GR-16).
 */
?>
        <?php
        $name = trim((string) ($product['name'] ?? 'Produs'));
        $slug = trim((string) ($product['slug'] ?? ''));
        $desc = trim((string) ($product['short_description'] ?? ''));
        $price = (float) ($product['price'] ?? 0);
        $basePrice = (float) ($product['base_price'] ?? $price);
        $hasSale = (bool) ($product['has_sale_price'] ?? false);
        $image = trim((string) ($product['image_url'] ?? ''));
        if ($image === '') {
            $image = '/assets/img/product-placeholder.svg';
        }
        // Pozele mari refăcute (A8) se afișează din fișierul nou, mai mic.
        $image = \App\Support\PozeOptimizate::src($image);
        $reviewsCount = max(0, (int) ($product['reviews_count'] ?? 0));
        $reviewsAverage = max(0.0, min(5.0, (float) ($product['reviews_average'] ?? 0)));
        $roundedStars = (int) round($reviewsAverage);
        // Precomanda nu e „epuizat": marfa vine, doar mai târziu. Fără rândul
        // ăsta, produsul bifat rămânea fără buton în listă, deși pe fișa lui
        // se putea comanda.
        $ePrecomanda = \App\Support\Precomanda::estePrecomanda($product);
        $outOfStock = (int) ($product['out_of_stock'] ?? 0) === 1 && !$ePrecomanda;
        $hasBbdOffers = \App\Support\BbdOferte::cereAlegere($product);
        $discountMode = (string) ($product['discount_badge_mode'] ?? 'percent') === 'value' ? 'value' : 'percent';
        $discount = 0;
        $discountValue = 0.0;
        if ($basePrice > 0 && $price < $basePrice) {
            $discount = (int) round((($basePrice - $price) / $basePrice) * 100);
            $discountValue = $basePrice - $price;
        }
        $discountValueLabel = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.');
        ?>
        <article class="bv-popular-card shop-catalog-v2__card" data-product-category="<?= htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES) ?>">
          <div class="bv-popular-card__media-wrap">
            <a class="bv-popular-card__media" href="/produs/<?= rawurlencode($slug) ?>">
              <img class="bv-popular-card__img" src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES) ?>"<?= \App\Support\ImageDimensions::atribute($image) ?> loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
<?php if ($arePreturi): ?>
              <?php if ($discount > 0): ?>
                <?php if ($discountMode === 'value'): ?>
                  <span class="bv-popular-card__bubble bv-popular-card__bubble--value">-<?= $discountValueLabel ?> lei</span>
                <?php else: ?>
                  <span class="bv-popular-card__bubble">-<?= $discount ?>%</span>
                <?php endif; ?>
              <?php endif; ?>
<?php endif; ?>
              <?php if ($hasBbdOffers): ?>
                <span class="bv-popular-card__bubble bv-popular-card__bubble--offer">OFERTE PREȚ</span>
              <?php endif; ?>
            </a>
          </div>

          <div class="bv-popular-card__body">
            <p class="bv-popular-card__desc"><?= htmlspecialchars($desc !== '' ? $desc : $descriereImplicita, ENT_QUOTES) ?></p>
            <h3 class="bv-popular-card__name"><a href="/produs/<?= rawurlencode($slug) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a></h3>

<?php if ($areRecenzii): ?>
            <div class="bv-popular-card__rating">
              <span class="bv-popular-card__stars" aria-label="Rating <?= number_format($reviewsAverage, 1) ?> din 5">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <svg class="bv-popular-card__star" viewBox="0 0 24 24" fill="<?= $i <= $roundedStars ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3.8l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.8 1-5.8-4.2-4.1 5.8-.8L12 3.8z"/></svg>
                <?php endfor; ?>
              </span>
              <span class="bv-popular-card__reviews">(<?= $reviewsCount ?>)</span>
            </div>
<?php endif; ?>

<?php if ($arePreturi || $poateCumpara): ?>
            <div class="bv-popular-card__bottom">
<?php if ($arePreturi): ?>
              <p class="bv-popular-card__price">
                <?= number_format($price, 2) ?> lei
                <?php if ($hasSale && $basePrice > $price): ?>
                  <span class="bv-popular-card__old"><?= number_format($basePrice, 2) ?> lei</span>
                <?php endif; ?>
              </p>
<?php endif; ?>
<?php if ($poateCumpara): ?>
              <?php if ($outOfStock): ?>
                <span class="bv-popular-card__stock-out">Stoc epuizat</span>
              <?php elseif ($ePrecomanda): ?>
                <a class="bv-popular-card__cart-btn bv-popular-card__cart-btn--choose"
                   href="/produs/<?= rawurlencode($slug) ?>"
                   title="Se livrează după ce marfa ajunge la noi"
                   aria-label="Precomandă">
                  Precomandă
                </a>
              <?php elseif ($hasBbdOffers): ?>
                <?php /* Produsul are oferte cu dată de expirare, deci nu poate fi
                        adăugat fără să fie aleasă una. Butonul de coș trimitea
                        formularul fără alegere: vizitatorul era întors la pagina
                        produsului, iar el credea că l-a pus în coș. Aici îl ducem
                        direct acolo unde alege. */ ?>
                <a class="bv-popular-card__cart-btn bv-popular-card__cart-btn--choose"
                   href="/produs/<?= rawurlencode($slug) ?>" aria-label="Alege oferta">
                  Alege oferta
                </a>
              <?php else: ?>
                <form method="post" action="/cos/adauga/<?= (int) ($product['id'] ?? 0) ?>">
                  <button type="submit" class="bv-popular-card__cart-btn" aria-label="Adaugă în coș">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H7"/></svg>
                  </button>
                </form>
              <?php endif; ?>
<?php endif; ?>
            </div>
<?php endif; ?>
          </div>
        </article>
