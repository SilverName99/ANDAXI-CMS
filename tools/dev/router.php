<?php

declare(strict_types=1);

/**
 * Router pentru serverul încorporat al PHP (`php -S`), folosit doar în
 * dezvoltare. Face ce fac pe Hostinger cele două .htaccess: fișierele care
 * există în public/ se servesc direct, orice altceva ajunge la public/index.php.
 *
 * Pornire: tools/dev/server.sh (docroot = public/).
 */

$cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$radacinaPublica = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

// /uploads/ le servește chiar index.php (la fel ca pe server).
if ($cale !== '/' && !str_starts_with($cale, '/uploads/')) {
    $fisier = realpath($radacinaPublica . $cale);
    if ($fisier !== false && is_file($fisier) && str_starts_with($fisier, realpath($radacinaPublica) . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $radacinaPublica . '/index.php';
require $radacinaPublica . '/index.php';
