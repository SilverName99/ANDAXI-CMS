<?php

declare(strict_types=1);

/**
 * Variantele minificate ale CSS-ului și JavaScript-ului fix al șablonului (WP-40, B6; App\Support\SablonSite): din
 * `views/sablon-site/` în `public/assets/css/*.min.css` și `public/assets/js/sablon/*.min.js`.
 *
 *   tools/dev/php.sh tools/dev/minifica-sablon.php              # le scrie (după o schimbare a surselor)
 *   tools/dev/php.sh tools/dev/minifica-sablon.php --verifica   # nu scrie: sunt la zi? (cod 1 dacă nu)
 *
 * Determinist: aceleași surse dau aceleași fișiere, octet cu octet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\SablonSite;

$verifica = in_array('--verifica', array_slice($argv, 1), true);
$diferite = [];
foreach (SablonSite::SURSE as $nume) {
    $sursa = (string) file_get_contents(SablonSite::sursa($nume));
    $min = str_ends_with($nume, '.css') ? SablonSite::minificaCss($sursa) : SablonSite::minificaJs($sursa);
    $tinta = $radacina . '/public' . SablonSite::caleMinificata($nume);
    $acum = is_file($tinta) ? (string) file_get_contents($tinta) : null;
    if ($acum === $min) {
        echo '  la zi   ', SablonSite::caleMinificata($nume), "\n";
        continue;
    }
    $diferite[] = $nume;
    if ($verifica) {
        echo '  DIFERĂ  ', SablonSite::caleMinificata($nume), ' (rulează tools/dev/minifica-sablon.php)', "\n";
        continue;
    }
    if (!is_dir(dirname($tinta))) {
        mkdir(dirname($tinta), 0775, true);
    }
    file_put_contents($tinta, $min);
    echo '  scris   ', SablonSite::caleMinificata($nume), ' (', strlen($sursa), ' → ', strlen($min), " octeți)\n";
}
exit($verifica && $diferite !== [] ? 1 : 0);
