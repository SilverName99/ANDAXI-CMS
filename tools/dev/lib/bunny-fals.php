<?php

declare(strict_types=1);

/**
 * O zonă de storage bunny.net falsă, pentru teste (tools/dev/test-fisiere-cloud.php): routerul unui `php -S` care răspunde ca
 * API-ul Bunny Edge Storage, cât folosește App\Support\BunnyStorage (și BunnyStorage al codului Biovitality):
 *
 *   GET    /<zona>/<cale>/   lista folderului: [{"ObjectName", "IsDirectory", "Length", "LastChanged", "DateCreated"}]
 *                            (un folder care nu există: [], ca Bunny)
 *   GET    /<zona>/<cale>    conținutul fișierului (404 dacă lipsește)
 *   PUT    /<zona>/<cale>    scrie fișierul (folderele de pe drum apar singure), 201
 *   DELETE /<zona>/<cale>    șterge fișierul (404 dacă lipsește); cu „/” la final, folderul cu tot ce are
 *
 * Cere antetul `AccessKey` = BUNNY_FALS_CHEIE (altfel 401), zona = BUNNY_FALS_ZONA (altfel 404). Fișierele stau în
 * BUNNY_FALS_DOSAR. Nicio cale cu „..”, NUL sau „\” (400). Fiecare cerere se notează în BUNNY_FALS_DOSAR/../cereri.log
 * (metoda și calea), ca testul să numere ce trimite fiecare cod. Doar în dezvoltare; nu ajunge pe un site (tools/).
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$dosar = rtrim((string) getenv('BUNNY_FALS_DOSAR'), '/');
$cheie = (string) getenv('BUNNY_FALS_CHEIE');
$zona = (string) getenv('BUNNY_FALS_ZONA');
$metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$caleBruta = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
@file_put_contents(dirname($dosar) . '/cereri.log', $metoda . ' ' . $caleBruta . "\n", FILE_APPEND | LOCK_EX);

$raspunde = static function (int $cod, mixed $corp = null): void {
    http_response_code($cod);
    header('Content-Type: application/json');
    echo json_encode($corp ?? ['HttpCode' => $cod, 'Message' => 'eroare ' . $cod]);
};

if ($dosar === '' || $cheie === '' || $zona === '') {
    $raspunde(500);
    return true;
}
if (!hash_equals($cheie, (string) ($_SERVER['HTTP_ACCESSKEY'] ?? ''))) {
    $raspunde(401, ['HttpCode' => 401, 'Message' => 'Unauthorized']);
    return true;
}

$segmente = array_map('rawurldecode', explode('/', ltrim($caleBruta, '/')));
$director = str_ends_with($caleBruta, '/');
if (array_shift($segmente) !== $zona) {
    $raspunde(404, ['HttpCode' => 404, 'Message' => 'Storage zone not found']);
    return true;
}
$segmente = array_values(array_filter($segmente, static fn (string $s): bool => $s !== ''));
foreach ($segmente as $segment) {
    if ($segment === '.' || $segment === '..' || str_contains($segment, "\0") || str_contains($segment, '\\') || str_contains($segment, '/')) {
        $raspunde(400);
        return true;
    }
}
$cale = $dosar . ($segmente !== [] ? '/' . implode('/', $segmente) : '');

$sterge = static function (string $tinta) use (&$sterge): void {
    if (is_dir($tinta) && !is_link($tinta)) {
        foreach (scandir($tinta) ?: [] as $nume) {
            if ($nume !== '.' && $nume !== '..') {
                $sterge($tinta . '/' . $nume);
            }
        }
        @rmdir($tinta);
        return;
    }
    @unlink($tinta);
};

if ($metoda === 'GET' && $director) {
    $lista = [];
    if (is_dir($cale)) {
        $nume = array_values(array_diff(scandir($cale) ?: [], ['.', '..']));
        sort($nume, SORT_STRING);
        foreach ($nume as $n) {
            $p = $cale . '/' . $n;
            $data = gmdate('Y-m-d\TH:i:s.000', (int) filemtime($p));
            $lista[] = ['ObjectName' => $n, 'IsDirectory' => is_dir($p), 'Length' => is_dir($p) ? 0 : (int) filesize($p),
                'LastChanged' => $data, 'DateCreated' => $data];
        }
    }
    $raspunde(200, $lista);
    return true;
}
if ($metoda === 'GET') {
    if (!is_file($cale)) {
        $raspunde(404, ['HttpCode' => 404, 'Message' => 'Object Not Found']);
        return true;
    }
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    readfile($cale);
    return true;
}
if ($metoda === 'PUT' && !$director && $segmente !== []) {
    @mkdir(dirname($cale), 0770, true);
    file_put_contents($cale, (string) file_get_contents('php://input'));
    $raspunde(201, ['HttpCode' => 201, 'Message' => 'File uploaded.']);
    return true;
}
if ($metoda === 'DELETE' && $segmente !== []) {
    if ($director ? !is_dir($cale) : !is_file($cale)) {
        $raspunde(404, ['HttpCode' => 404, 'Message' => 'Object Not Found']);
        return true;
    }
    $sterge($cale);
    $raspunde(200, ['HttpCode' => 200, 'Message' => 'File deleted successfuly.']);
    return true;
}
$raspunde(400);
return true;
