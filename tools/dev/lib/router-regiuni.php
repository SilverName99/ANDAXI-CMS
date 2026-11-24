<?php

declare(strict_types=1);

/**
 * Routerul de dezvoltare cu randatorii de probă ai regiunilor dinamice (poarta G-regiuni, tools/dev/test-regiuni.php): definește
 * ANDAXI_DEV_REGIUNI_PROBA (fișierul lor, tools/dev/lib/regiuni-proba.php, încărcat de App\Support\CadruCapturat\Regiuni la prima
 * regiune) și trimite cererea mai departe, la tools/dev/router.php. Doar în teste; aplicația nu se schimbă (pe un site constanta nu
 * există, deci nici randatorii de probă).
 *
 * Dosarul site-ului și docroot-ul vin din mediu (CMS_DEV_SITE_DIR, CMS_DEV_PUBLIC_DIR), ca în stub-ul din Etapa 2
 * (PLATFORMA_SITE_DIR, PLATFORMA_PUBLIC_DIR): serverul încorporat al PHP nu aplică `auto_prepend_file` scriptului router, deci
 * un preludiu dat cu `-d auto_prepend_file=` n-ar ajunge aici, iar cache-ul de pagini și sesiunile serverului de probă ar sta în
 * storage/ al codului.
 */

foreach (['PLATFORMA_SITE_DIR' => 'CMS_DEV_SITE_DIR', 'PLATFORMA_PUBLIC_DIR' => 'CMS_DEV_PUBLIC_DIR'] as $constanta => $variabila) {
    $dosar = (string) getenv($variabila);
    if ($dosar !== '' && $dosar[0] === '/' && !defined($constanta)) {
        define($constanta, $dosar);
    }
}
define('ANDAXI_DEV_REGIUNI_PROBA', __DIR__ . '/regiuni-proba.php');
return require dirname(__DIR__) . '/router.php';
