<?php

declare(strict_types=1);

/**
 * CDN-ul Hostinger simulat (hcdn), în fața unei copii a site-ului de verificare, pentru tools/dev/test-verificare.php:
 *
 *   CDN_SIMULAT_DOSAR=<dosar> php -S 127.0.0.1:<port> tools/dev/lib/cdn-simulat.php
 *
 * `<dosar>/tinta` are adresa copiei (http://127.0.0.1:<port>), `<dosar>/mod` felul răspunsului la `/robots.txt` (citite la
 * fiecare cerere, deci testul le schimbă fără să repornească nimic):
 * - `hcdn`: ce răspunde CDN-ul Hostinger pe un domeniu temporar (21 oct 2026): textul lui, `server: hcdn`, fără antetele
 *   site-ului; cererea nu ajunge la copie;
 * - `text-strain`: tot `server: hcdn`, alt text („User-agent: *” / „Allow: /”);
 * - `alt-server`: textul hcdn, cu `server: nginx`;
 * - `cu-antetele-site`: textul hcdn, cu `X-Robots-Tag` și `Cache-Control: no-store` ca ale porții (un site care ar răspunde așa);
 * - `trece`: și `/robots.txt` merge la copie.
 * Orice altă cerere merge la copie cu metoda, antetele și corpul ei; răspunsul vine înapoi la fel, cu `server: hcdn` (în
 * modul `alt-server`, `nginx`). Fără proxy (cererile spre copie sunt locale).
 */

$dosar = (string) getenv('CDN_SIMULAT_DOSAR');
$mod = trim((string) @file_get_contents($dosar . '/mod'));
$tinta = rtrim(trim((string) @file_get_contents($dosar . '/tinta')), '/');
$cale = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
header_remove('X-Powered-By');
header('Server: ' . ($mod === 'alt-server' ? 'nginx' : 'hcdn'));

if ($mod !== 'trece' && strtok($cale, '?') === '/robots.txt') {
    http_response_code(200);
    header('Content-Type: text/plain');
    if ($mod === 'cu-antetele-site') {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, private');
    }
    echo $mod === 'text-strain' ? "User-agent: *\r\nAllow: /\r\n" : "User-agent: Googlebot\r\nDisallow: /\r\n\r\nUser-agent: *\r\nAllow: /\r\n";
    return true;
}

$antete = [];
foreach ((array) getallheaders() as $nume => $valoare) {
    if (!in_array(strtolower((string) $nume), ['host', 'content-length', 'connection', 'accept-encoding', 'expect'], true)) {
        $antete[] = $nume . ': ' . $valoare;
    }
}
$primite = [];
$c = curl_init($tinta . $cale);
curl_setopt_array($c, [
    CURLOPT_CUSTOMREQUEST => $metoda,
    CURLOPT_NOBODY => $metoda === 'HEAD',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROXY => '',
    CURLOPT_NOPROXY => '*',
    CURLOPT_TIMEOUT => 60,
    CURLOPT_PATH_AS_IS => true,
    CURLOPT_HTTPHEADER => $antete,
    CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
        if (str_contains($linie, ':')) {
            $primite[] = rtrim($linie, "\r\n");
        }
        return strlen($linie);
    },
]);
$corp = (string) file_get_contents('php://input');
if ($corp !== '') {
    curl_setopt($c, CURLOPT_POSTFIELDS, $corp);
}
$raspuns = curl_exec($c);
$cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
curl_close($c);
if ($raspuns === false || $cod === 0) {
    http_response_code(502);
    echo 'CDN simulat: copia nu răspunde';
    return true;
}
http_response_code($cod);
foreach ($primite as $linie) {
    $nume = strtolower(trim(explode(':', $linie, 2)[0]));
    if (!in_array($nume, ['transfer-encoding', 'connection', 'content-length', 'date', 'server', 'host', 'x-powered-by'], true)) {
        header($linie, false);
    }
}
echo (string) $raspuns;
return true;
