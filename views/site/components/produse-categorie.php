<?php
/**
 * O bandă simplă de produse: cardul duce la pagina produsului, fără preț, stele și coș (WP-19: GR-16, GR-17; din
 * grafoanaytis 84c9f6b, 8f51fe4). Folosită de {{product_produse_similare}} (șablonul paginii de produs: produsele din
 * aceeași categorie) și de {{produse_serviciu}} (o pagină de serviciu: produsele categoriei cu slug-ul ultimului
 * segment al paginii). Markup Bootstrap (card, row, col-*): stilul vine din tema site-ului (tema.json → bootstrap).
 *
 * Așteaptă: $produse (name, slug, short_description, image_url), $titlu, $subtitlu, $textLegatura, opțional $fundal
 * și $idSectiune.
 */
$produse = is_array($produse ?? null) ? $produse : [];
$titlu = trim((string) ($titlu ?? ''));
$subtitlu = trim((string) ($subtitlu ?? ''));
$textLegatura = trim((string) ($textLegatura ?? 'Vezi produsul'));
$fundal = trim((string) ($fundal ?? 'bg-body-tertiary'));
$idSectiune = trim((string) ($idSectiune ?? 'produse-categorie'));
$e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($produse === []) {
    return;
}
?>
<section id="<?= $e($idSectiune) ?>" class="py-5 <?= $e($fundal) ?>">
  <div class="container">
<?php if ($titlu !== ''): ?>
    <h2 class="display-5 fw-normal mb-2"><?= $e($titlu) ?></h2>
<?php endif; ?>
<?php if ($subtitlu !== ''): ?>
    <p class="lead mb-4"><?= $e($subtitlu) ?></p>
<?php endif; ?>

    <div class="row g-4">
      <?php foreach ($produse as $produs): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <a class="card h-100 text-decoration-none border-0 shadow-sm categorie"
             href="/produs/<?= rawurlencode((string) ($produs['slug'] ?? '')) ?>">
            <div class="ratio ratio-4x3 overflow-hidden">
              <img src="<?= $e(\App\Support\PozeOptimizate::src((string) (($produs['image_url'] ?? '') !== '' ? $produs['image_url'] : '/assets/img/product-placeholder.svg'))) ?>"
                   alt="<?= $e((string) ($produs['name'] ?? '')) ?>"
                   class="object-fit-cover" loading="lazy" decoding="async">
            </div>
            <div class="card-body">
              <h3 class="h5 mb-2"><?= $e((string) ($produs['name'] ?? '')) ?></h3>
              <p class="text-secondary small mb-3"><?= $e(strip_tags((string) ($produs['short_description'] ?? ''))) ?></p>
              <span class="btn btn-link px-0 fw-semibold text-uppercase banda-alternanta__actiune">
                <?= $e($textLegatura) ?>
                <svg class="sageata" width="18" height="14" viewBox="0 0 18 14" aria-hidden="true" focusable="false"><path d="M10.5 1 16.5 7l-6 6M0 7h16" fill="none" stroke="currentColor" stroke-width="2"/></svg>
              </span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
