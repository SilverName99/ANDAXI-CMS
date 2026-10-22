<?php

declare(strict_types=1);

/**
 * Doar pentru autotestul G-vizual (tools/dev/capturi-publice.sh --autotest): același router ca tools/dev/router.php,
 * dar `/assets/css/app.css` vine dintr-o copie cu o singură regulă schimbată (CMS_VIZUAL_APP_CSS). Așa „codul curent”
 * al testului diferă de „baza” lui doar prin acea regulă, iar comparația stilurilor calculate trebuie s-o raporteze.
 */

$cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$copie = (string) getenv('CMS_VIZUAL_APP_CSS');
if ($cale === '/assets/css/app.css' && $copie !== '' && is_file($copie)) {
    header('Content-Type: text/css; charset=utf-8');
    readfile($copie);
    return true;
}
return require dirname(__DIR__) . '/router.php';
