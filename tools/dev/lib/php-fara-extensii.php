<?php

declare(strict_types=1);

/**
 * Un PHP „ca pe Hostinger” pentru teste (tools/dev/test-verificare.php, tools/dev/test-calcule.php): pe site-ul de verificare
 * (21 oct 2026) `/opt/alt/php83/usr/bin/php` nu încarcă sodium, deși modulul e pe disc, iar `-d extension=sodium.so` îl
 * încarcă. Local sodium e în PHP-ul însuși, deci testele scot alte extensii (pdo_mysql, mbstring): `php_fara_extensii()` face
 * în dosarul dat un executabil `php` care pornește PHP-ul testului cu fișierele ini ale lui, fără cele care încarcă
 * extensiile scoase (`PHP_INI_SCAN_DIR` spre o copie a dosarului conf.d). `exec -a "$0"` îi lasă numele: în procesul pornit
 * așa, PHP_BINARY e chiar acest executabil, ca /opt/alt/php83/usr/bin/php pe server, deci și procesele copil pornite cu
 * PHP_BINARY (cu orice mediu, și cu unul gol) n-au extensiile, până primesc `-d extension=<nume>.so`.
 *
 * Întoarce calea executabilului (fără legături, cum o vede PHP_BINARY). Aruncă o excepție dacă PHP-ul făcut așa tot are o
 * extensie scoasă sau, cu `-d extension=<nume>.so`, tot nu o are (atunci testul n-ar dovedi nimic).
 *
 * @param list<string> $scoase
 */
function php_fara_extensii(string $dosar, array $scoase, string $php = PHP_BINARY): string
{
    $confd = $dosar . '/conf.d';
    if (!is_dir($confd) && !mkdir($confd, 0770, true) && !is_dir($confd)) {
        throw new RuntimeException('nu pot face ' . $confd);
    }
    foreach (array_filter(array_map('trim', explode(',', (string) php_ini_scanned_files()))) as $fisier) {
        $text = (string) @file_get_contents($fisier);
        if (preg_match('/^\s*extension\s*=\s*"?([A-Za-z0-9_]+)(?:\.so)?"?\s*$/m', $text, $m) === 1 && in_array(strtolower($m[1]), $scoase, true)) {
            continue;
        }
        copy($fisier, $confd . '/' . basename($fisier));
    }
    $executabil = $dosar . '/php';
    file_put_contents($executabil, "#!/bin/bash\n"
        . '# PHP-ul testului fără ' . implode(', ', $scoase) . ' (le încarcă doar -d extension=<nume>.so), ca /opt/alt/php83 pe Hostinger fără sodium.' . "\n"
        . 'PHP_INI_SCAN_DIR=' . escapeshellarg($confd) . "\nexport PHP_INI_SCAN_DIR\n"
        . 'exec -a "$0" ' . escapeshellarg($php) . ' "$@"' . "\n");
    chmod($executabil, 0755);
    $executabil = (string) realpath($executabil);
    $are = static function (array $argumente) use ($executabil, $scoase): array {
        $cod = 'echo json_encode(["php" => PHP_BINARY, "ext" => array_map("extension_loaded", ' . var_export($scoase, true) . ')]);';
        $iesire = (string) shell_exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' ' . escapeshellarg($executabil) . ' '
            . implode(' ', array_map('escapeshellarg', $argumente)) . ' -r ' . escapeshellarg($cod) . ' 2>/dev/null');
        $date = json_decode($iesire, true);
        return is_array($date) ? $date : ['php' => '', 'ext' => []];
    };
    $simplu = $are([]);
    $cuD = $are(array_merge(...array_map(static fn (string $e): array => ['-d', 'extension=' . $e . '.so'], $scoase)));
    if ($simplu['php'] !== $executabil || in_array(true, (array) $simplu['ext'], true) || in_array(false, (array) $cuD['ext'], true) || $cuD['ext'] === []) {
        throw new RuntimeException('PHP-ul fără ' . implode(', ', $scoase) . ' nu iese cum trebuie: ' . json_encode([$simplu, $cuD]));
    }
    return $executabil;
}
