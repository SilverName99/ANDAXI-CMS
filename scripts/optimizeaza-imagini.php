<?php

declare(strict_types=1);

/**
 * Trecerea o singură dată prin pozele existente (WP-40, SEO partea B, B5; după scriptul optimizeaza-imagini al
 * grafoanaytis): fiecare poză JPEG, PNG, WebP sau GIF din /uploads/ (implicit products, gallery, blog, demo) primește
 * variantele WebP de 160, 400, 800 și 1200 px (doar cele mai mici decât ea) și dimensiunile ei în cache, exact ca o poză
 * încărcată acum din admin (App\Support\VarianteImagini). Poza originală nu se atinge; variantele stau în
 * /uploads/_variante/. Pozele de profil ale membrilor (uploads/gallery/profile/, date personale) nu se ating.
 *
 * Rulare, prin SSH, din folderul site-ului (cu PHP-ul care are GD cu WebP: `php -m | grep -i gd`):
 *   php scripts/optimizeaza-imagini.php --plan                 câte poze, câte variante lipsesc; nu scrie nimic
 *   php scripts/optimizeaza-imagini.php --aplica               le face (de câte ori vrei: ce e la zi se sare)
 *   php scripts/optimizeaza-imagini.php --aplica --dosare=products,gallery
 *
 * Cod de ieșire: 0 gata (sau, cu --plan, nimic de făcut); 1 au rămas poze fără variante (GD le-a refuzat; lista e pe
 * ecran) sau, cu --plan, sunt variante de făcut; 2 argumente greșite sau GD fără WebP (nimic scris).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Paths;
use App\Support\VarianteImagini;

$plan = false;
$aplica = false;
$dosare = ['products', 'gallery', 'blog', 'demo'];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--plan') {
        $plan = true;
    } elseif ($argument === '--aplica') {
        $aplica = true;
    } elseif (preg_match('/^--dosare=([a-z0-9_,\/-]+)$/', $argument, $m) === 1) {
        $dosare = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    } else {
        fwrite(STDERR, "EROARE: argument necunoscut (vezi capul fișierului): --plan sau --aplica [--dosare=a,b].\n");
        exit(2);
    }
}
if ($plan === $aplica) {
    fwrite(STDERR, "EROARE: alege --plan sau --aplica.\n");
    exit(2);
}
if (!VarianteImagini::suportat()) {
    fwrite(STDERR, "EROARE: PHP-ul acesta n-are GD cu WebP (imagewebp). Nimic scris.\n");
    exit(2);
}

$poze = VarianteImagini::pozeDin($dosare);
$lipsa = 0;
$scrise = 0;
$refuzate = [];
foreach ($poze as $rel) {
    $info = @getimagesize(Paths::uploads() . '/' . $rel);
    if ($info === false) {
        $refuzate[] = $rel;
        continue;
    }
    foreach (VarianteImagini::MARIMI as $marime) {
        $varianta = Paths::uploads() . '/' . VarianteImagini::caleVarianta($rel, $marime);
        if ($marime < (int) $info[0] && (!is_file($varianta) || (int) filemtime($varianta) < (int) filemtime(Paths::uploads() . '/' . $rel))) {
            $lipsa++;
        }
    }
    if ($aplica) {
        $n = VarianteImagini::genereaza($rel);
        if ($n === null) {
            $refuzate[] = $rel;
        } else {
            $scrise += $n;
        }
    }
}
echo 'optimizeaza-imagini: ', count($poze), ' poze în ', implode(', ', $dosare), '; variante de făcut: ', $lipsa,
    $aplica ? '; scrise: ' . $scrise : '', "\n";
foreach (array_slice($refuzate, 0, 50) as $rel) {
    echo '  necitită: /uploads/', $rel, "\n";
}
if ($plan) {
    exit($lipsa === 0 ? 0 : 1);
}
exit($refuzate === [] ? 0 : 1);
