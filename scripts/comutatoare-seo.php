<?php

declare(strict_types=1);

/**
 * Comutatoarele SEO partea B (WP-40: viteza, pozele, fluxul Merchant, sursa comenzilor) pe site-ul acesta, fără admin.
 * Sunt pornite în nucleu și înghețate la valoarea de azi pe site-urile existente (setari-inghetate.json); proprietarul le
 * vede întâi pe site-ul de verificare (docs/VERIFICARE.md, 6c), apoi le pornește pe site, una câte una sau toate.
 *
 *   php scripts/comutatoare-seo.php --arata                       valorile de acum; nu scrie nimic
 *   php scripts/comutatoare-seo.php --porneste                    toate pe „1”
 *   php scripts/comutatoare-seo.php --porneste=imagini_srcset,css_js_in_fisiere
 *   php scripts/comutatoare-seo.php --opreste[=…]                 pe „0” (toate sau cele scrise)
 *
 * Scrie doar aceste chei din `settings`, apoi golește cache-ul de pagini (CDN-ul din hPanel se golește de mână). Pozele
 * au nevoie o dată și de `php scripts/optimizeaza-imagini.php --aplica` (variantele pozelor existente). Nicio dată
 * personală pe ecran. Cod de ieșire: 0 gata, 1 baza nu se deschide, 2 argumente greșite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\FluxGoogleMerchant;
use App\Support\ResponseCache;
use App\Support\Settings;

const COMUTATOARE = [
    'imagini_srcset' => 'pozele în mărimile ecranului (srcset; B5)',
    'sesiune_doar_la_nevoie' => 'sesiunea doar unde e nevoie (B6)',
    'cache_pagini_extins' => 'cache-ul paginilor publice, fără reguli în admin (B6)',
    'css_js_in_fisiere' => 'CSS-ul și JavaScript-ul șablonului în fișiere (B6)',
    'merchant_feed' => 'fluxul Google Merchant (B8; și în admin: Setări magazin → SEO Google)',
    'sursa_comenzii' => 'sursa comenzilor (B9; raportul: Rapoarte → Sursa comenzilor)',
];

$folosire = 'Folosire: php scripts/comutatoare-seo.php --arata | --porneste[=a,b] | --opreste[=a,b] (cheile: '
    . implode(', ', array_keys(COMUTATOARE)) . ')';
$argumente = array_slice($argv, 1);
if (count($argumente) !== 1 || preg_match('/^--(arata|porneste|opreste)(?:=([a-z_,]+))?$/', $argumente[0], $m) !== 1
    || ($m[1] === 'arata' && isset($m[2]))) {
    fwrite(STDERR, $folosire . "\n");
    exit(2);
}
$mod = $m[1];
$chei = isset($m[2]) ? array_values(array_unique(array_filter(explode(',', $m[2])))) : array_keys(COMUTATOARE);
$necunoscute = array_diff($chei, array_keys(COMUTATOARE));
if ($chei === [] || $necunoscute !== []) {
    fwrite(STDERR, 'EROARE: cheie necunoscută: ' . implode(', ', $necunoscute) . "\n" . $folosire . "\n");
    exit(2);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "EROARE: nu am putut deschide baza (vezi .env).\n");
    exit(1);
}

if ($mod !== 'arata') {
    $valoare = $mod === 'porneste' ? '1' : '0';
    Settings::save($db, array_fill_keys($chei, $valoare));
    $golite = ResponseCache::purgePageCache();
    FluxGoogleMerchant::sterge();
    echo 'Scris „', $valoare, '” pentru: ', implode(', ', $chei), '; cache-ul de pagini (', $golite, " fișiere) și al fluxului golite.\n";
}
$setari = Settings::all($db);
foreach (COMUTATOARE as $cheie => $descriere) {
    echo '  ', (string) ($setari[$cheie] ?? '1') === '1' ? 'PORNIT ' : 'OPRIT  ', str_pad($cheie, 24), ' ', $descriere, "\n";
}
exit(0);
