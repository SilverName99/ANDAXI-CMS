<?php

declare(strict_types=1);

/**
 * Fișierele statice ale paginilor capturate în docroot-ul nou (adoptarea WordPress pe drumul capturat; docs/DEPLOY.md, „Fișierele
 * statice”; App\Support\ImportWordPress\Statice): foile temei și ale pluginurilor, `et-cache/`, `wp-includes/`, fonturile și pozele
 * temei cerute de pagini (manifestul capturii verificate), de pe discul WordPress (doar citit), plus fonturile Google aduse de
 * captură. Mediile din `wp-content/uploads/` le copiază importul; scripturile nu se publică niciodată.
 *
 *   php scripts/publica-statice.php --wp=<dosarul WordPress> --url=<adresa site-ului> [--captura=<dosar>] [--sha-raport=<sha256>] --plan|--aplica
 *
 * Captura: implicit `capturat/` lângă `.env` (dosarul site-ului), deschisă doar verificată (lanțul sha256 al raportului; ancora:
 * --sha-raport=, altfel sha256-ul lui raport-captura.json din dosar). Pe server îl rulează deploy/adopta-wp.sh în faza `pregatire`,
 * prin bin/platforma cu --public=public_html.nou (App\Support\Paths::public). Manifestul copiilor:
 * `storage/import-wordpress/statice.json`.
 *
 * Coduri de ieșire: 0 fără NU; 1 măcar un NU (nimic nu se copiază peste o coliziune; o copie cu alt sha256 decât în captură se scoate);
 * 2 folosire greșită.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\ImportWordPress\Statice;
use App\Support\Paths;

$o = ['wp' => null, 'url' => null, 'captura' => null, 'sha-raport' => null, 'aplica' => false, 'plan' => false];
$folosire = "Folosire: php scripts/publica-statice.php --wp=<dosarul WordPress> --url=<adresa> [--captura=<dosar>] [--sha-raport=<sha256>] --plan|--aplica\n";
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(wp|url|captura|sha-raport)=(.+)$/', $arg, $m) === 1) {
        $o[$m[1]] = $m[2];
    } elseif (in_array($arg, ['--aplica', '--plan'], true)) {
        $o[substr($arg, 2)] = true;
    } else {
        fwrite(STDERR, 'Argument necunoscut: ' . $arg . "\n" . $folosire);
        exit(2);
    }
}
if ($o['wp'] === null || $o['url'] === null || $o['aplica'] === $o['plan'] || !is_dir((string) $o['wp'])) {
    fwrite(STDERR, $folosire);
    exit(2);
}
$captura = rtrim((string) ($o['captura'] ?? (dirname(Paths::env()) . '/capturat')), '/');
$sha = (string) ($o['sha-raport'] ?? (is_file($captura . '/raport-captura.json') ? hash_file('sha256', $captura . '/raport-captura.json') : ''));
$docroot = rtrim(Paths::public(), '/');
$scrie = static function (string $fel, string $text): void {
    echo mb_str_pad($fel, 11) . $text, "\n";
};
echo '== Fișierele statice ale paginilor capturate' . ($o['aplica'] ? '' : ' — PLAN') . "\n";
$scrie('  ..', 'captura: ' . $captura . ' (raport-captura.json: sha256 ' . ($sha !== '' ? substr($sha, 0, 12) : '?') . ')');
$scrie('  ..', 'de pe discul WordPress ' . $o['wp'] . ' (doar citit), în ' . $docroot);
$r = Statice::publica((string) $o['wp'], $captura, $sha, (string) $o['url'], $docroot, (bool) $o['aplica']);
foreach ($r['necopiate'] as $n) {
    $scrie('  ATENȚIE', 'necopiat (refuzat de verificări): ' . $n['cale'] . ': ' . $n['motiv']);
}
foreach ($r['probleme'] as $p) {
    $scrie('  NU', $p);
}
$scrie('  ..', ($o['aplica'] ? 'copiate: ' : 'de copiat: ') . $r['copiate'] . ' fișiere statice (' . number_format($r['octeti'] / 1048576, 1, ',', '') . ' MB), fonturi Google: '
    . $r['fonturi'] . ', necopiate: ' . count($r['necopiate']));
if ($r['probleme'] !== []) {
    echo 'NU: ' . count($r['probleme']) . " probleme (mai sus)" . ($o['aplica'] ? '; manifestul nu e scris' : '') . ".\n";
    exit(1);
}
if (!$o['aplica']) {
    echo "PLAN: nimic copiat. Copierea: aceeași comandă cu --aplica.\n";
    exit(0);
}
$fisier = Paths::stocareSite('import-wordpress') . '/statice.json';
Statice::scrieManifest($r['manifest'], $fisier);
$scrie('  OK', 'manifestul copiilor (sha256 al sursei și al copiei): ' . $fisier);
echo "GATA: fișierele statice sunt în docroot-ul nou.\n";
exit(0);
