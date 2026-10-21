<?php

declare(strict_types=1);

/**
 * Stub-ul din public_html/ al unui site, în așezarea din Etapa 2 (docs/DEZVOLTARE.md, „Unde scrie codul”; analiza
 * infra, §4.2–4.3). E același pe fiecare site și nu se editează niciodată: ce ține de un site stă în ../site/ (.env,
 * storage/, RELEASE, MENTENANTA), codul în versiunea scrisă în RELEASE.
 *
 *  1. ../site/RELEASE: versiunea codului („2026.11.2-e4f5a6b”), citită ca text (nu cu include: OPcache nu poate servi
 *     un indicator vechi). Lipsă sau de altă formă → 503, o pagină statică.
 *  2. ../site/MENTENANTA există (mentenanța deploy-ului, pentru o migrare care oprește scrierile) → 503,
 *     Retry-After: 120 și mentenanta.html, în afară de /webhook/, /api/erp/ și /_andaxi/: confirmările de plată,
 *     ERP-ul și pingul merg mai departe. E alta decât „Revenim în curând” din admin (App\Support\Maintenance) și
 *     merge și când baza sau codul nou sunt stricate.
 *  3. Versiunea: ../site/releases/<versiune>/ dacă există (o găzduire care nu lasă un site să citească în afara
 *     dosarului lui), altfel ~/platforma/releases/<versiune>/, comună contului. Lipsă → 503 static.
 *  4. PLATFORMA_SITE_DIR (../site) și PLATFORMA_PUBLIC_DIR (acest dosar), citite de App\Support\Paths, apoi
 *     public/index.php al versiunii.
 *
 * Nu lasă nicio variabilă în urmă (totul într-o funcție), ca public/index.php să pornească la fel ca pe un site
 * de azi. Îl verifică tools/dev/test-paths.php, pe o găzduire simulată.
 */

require (static function (): string {
    $site = dirname(__DIR__) . '/site';
    $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $indisponibil = static function (int $secunde) use ($metoda): never {
        http_response_code(503);
        header('Retry-After: ' . $secunde);
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        if ($metoda !== 'HEAD') {
            $pagina = @file_get_contents(__DIR__ . '/mentenanta.html');
            echo is_string($pagina) && $pagina !== '' ? $pagina
                : "<!doctype html>\n<html lang=\"ro\"><meta charset=\"utf-8\"><title>Revenim în câteva minute</title>"
                    . "<p>Site-ul se actualizează. Încearcă din nou peste câteva minute.</p></html>\n";
        }
        exit;
    };

    $versiune = trim((string) @file_get_contents($site . '/RELEASE'));
    if (preg_match('/^[0-9][0-9.]*-[0-9a-f]{7}$/', $versiune) !== 1) {
        $indisponibil(300);
    }

    if (is_file($site . '/MENTENANTA')) {
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $trece = false;
        foreach (['/webhook/', '/api/erp/', '/_andaxi/'] as $prefix) {
            if (str_starts_with($cale, $prefix)) {
                $trece = true;
                break;
            }
        }
        if (!$trece) {
            $indisponibil(120);
        }
    }

    $index = null;
    foreach ([$site . '/releases/' . $versiune, dirname(__DIR__, 3) . '/platforma/releases/' . $versiune] as $dosar) {
        if (@is_file($dosar . '/public/index.php')) {
            $index = $dosar . '/public/index.php';
            break;
        }
    }
    if ($index === null) {
        $indisponibil(300);
    }

    define('PLATFORMA_SITE_DIR', $site);
    define('PLATFORMA_PUBLIC_DIR', __DIR__);
    return $index;
})();
