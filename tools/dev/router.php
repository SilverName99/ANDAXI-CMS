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

// /uploads/ le servește chiar index.php (la fel ca pe server); /sitemap.xml e
// mereu ruta din aplicație, chiar dacă a rămas un fișier vechi (ca în public/.htaccess).
if ($cale !== '/' && $cale !== '/sitemap.xml' && !str_starts_with($cale, '/uploads/')) {
    $fisier = realpath($radacinaPublica . $cale);
    if ($fisier !== false && is_file($fisier) && str_starts_with($fisier, realpath($radacinaPublica) . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

// /tema/… sunt fișierele publice ale temei site-ului (docs/PACHETE.md). Pe server stau în public/tema/, copiate din
// pachet (tema/public/) la instalare și la fiecare actualizare, și le servește serverul web (regula de mai sus). Aici
// nu le copiază nimeni: se servesc direct din pachetul ales (SITE_DATE_DOSAR), doar fișierele din tema/public/ și doar
// tipurile pe care tools/dev/verifica-pachet.php le primește acolo. Un fișier care lipsește ajunge la aplicație (404).
$dosarSite = trim((string) getenv('SITE_DATE_DOSAR'));
if ($dosarSite !== '' && $dosarSite[0] !== '/') {
    $dosarSite = dirname($radacinaPublica) . '/' . $dosarSite;
}
$dosarTema = $dosarSite !== '' ? realpath($dosarSite . '/tema/public') : false;
// Aplicația (App\Support\Tema::fisierPublic) pune în pagină o adresă /tema/… al cărei fișier nu e în public/tema/ doar
// dacă îl servește cineva: aici routerul, din acest dosar. Fără constantă (serverul web de pe Hostinger) nu.
define('ANDAXI_DEV_TEMA_DIN_PACHET', $dosarTema !== false ? $dosarTema : '');
if (str_starts_with($cale, '/tema/')) {
    $tipuri = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    $fisier = $dosarTema !== false ? realpath($dosarTema . '/' . substr($cale, strlen('/tema/'))) : false;
    $tip = $tipuri[strtolower(pathinfo((string) $fisier, PATHINFO_EXTENSION))] ?? null;
    if ($fisier !== false && $tip !== null && is_file($fisier) && str_starts_with($fisier, $dosarTema . DIRECTORY_SEPARATOR)) {
        header('Content-Type: ' . $tip);
        header('Content-Length: ' . (string) filesize($fisier));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($fisier)) . ' GMT');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($fisier);
        }
        return true;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $radacinaPublica . '/index.php';
require $radacinaPublica . '/index.php';
