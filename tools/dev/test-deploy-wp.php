<?php

declare(strict_types=1);

/**
 * Repetiția adoptării WordPress cap-coadă (deploy/adopta-wp.sh, deploy/revino.sh; docs/DEPLOY.md, „Adoptarea unui site
 * WordPress”), poarta G-deploy-wp, pe o găzduire simulată ca Hostinger și pe date INVENTATE („Pensiunea Probă”):
 *
 *  - `acasa/domains/pensiune-proba.test/public_html`: un WordPress de probă (tools/dev/lib/wordpress-proba.php, cu Divi 4), cu baza
 *    lui (`cms_dwp_wp`), foile temei, `et-cache/`, `wp-includes/`, un font, o poză a temei și un script;
 *  - `site/capturat/`: captura site-ului (tools/dev/lib/captura-divi-proba.php), cu manifestul fișierelor cerute de pagini (pe
 *    disc), fonturile Google aduse în `fonturi/` și lanțul sha256 (raport-captura.json);
 *  - o bază nouă, goală (`cms_dwp_nou`), cu utilizatorul ei; versiunea de cod făcută cu deploy/release.sh dintr-un repo cu codul
 *    de acum și pachetul `pensiune-proba` (cel al site-ului `pensiune`, plus lista scripturilor aruncate ale capturii inventate);
 *    licența de test; un server `php -S` pe docroot, cu un router care face ce fac .htaccess-urile (fișierele, apoi index.php).
 *
 * Pașii: `--baza-noua` (datele bazei pe stdin), planul (cod 0, niciun NU), `--aplica` până la `deschidere` (fiecare fază), apoi pe
 * site-ul nou: paginile, fișierele statice (foaia temei cu adresele relative, fontul Google), un formular trimis (scris în baza
 * nouă, emailul prins de tools/dev/mail-capturat.sh), căutarea, adresele vechi (`/camere` → `/camere/`, `?page_id=`, slug-ul vechi,
 * `/hello-world/`), `/wp-admin/` și `/wp-login.php` 410, `diagnostic.php --capturat`; apoi `revino.sh --aplica`: WordPress din nou
 * în public_html, octet cu octet ca înainte (fiecare fișier și drepturile lui), baza lui neatinsă (CHECKSUM TABLE).
 *
 *   tools/dev/php.sh tools/dev/test-deploy-wp.php
 *
 * Bazele `cms_dwp_*`, utilizatorul lor, serverul (CMS_DEV_PORT+60) și dosarul $CMS_DEV_DIR/deploy-wp/ se șterg la final
 * (CMS_DEV_PASTREAZA=1 lasă dosarul). Raport: $CMS_DEV_DIR/test-deploy-wp-raport.txt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/deploy-wp';
$acasa = $lucru . '/acasa';
$domeniu = 'pensiune-proba.test';
$slug = 'pensiune-proba';
$dosarSite = $acasa . '/domains/' . $domeniu;
$public = $dosarSite . '/public_html';
$site = $dosarSite . '/site';
$repo = $acasa . '/platforma/repo';
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 60;
$url = 'http://127.0.0.1:' . $port;
$sock = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$bazaWp = 'cms_dwp_wp';
$bazaNoua = 'cms_dwp_nou';
$utilizatorDb = 'cms_dwp';
$parolaDb = 'Parola-proba-' . bin2hex(random_bytes(4));
exec('rm -rf ' . escapeshellarg($lucru));
@mkdir($public, 0775, true);
@mkdir($lucru . '/mail', 0770, true);

require_once $radacina . '/bootstrap.php';
require_once $radacina . '/tools/dev/lib/wordpress-proba.php';
require_once $radacina . '/tools/dev/lib/captura-divi-proba.php';

use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Continut\Divi4;

$fisierRaport = $dosarDev . '/test-deploy-wp-raport.txt';
@file_put_contents($fisierRaport, '');
$trecute = 0;
$esuate = 0;
$scrie = static function (string $linie) use ($fisierRaport): void {
    echo $linie, "\n";
    file_put_contents($fisierRaport, $linie . "\n", FILE_APPEND);
};
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate, $scrie): void {
    if ($ok) {
        $trecute++;
        $scrie('  OK    ' . $eticheta);
    } else {
        $esuate++;
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 2500) : ''));
    }
};
$scrie('Repetiția adoptării WordPress cap-coadă (G-deploy-wp) — ' . date('Y-m-d H:i:s'));

$root = new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'), (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pidServer = 0;
register_shutdown_function(static function () use (&$pidServer, $root, $bazaWp, $bazaNoua, $utilizatorDb, $lucru): void {
    if ($pidServer > 0) {
        exec('pkill -TERM -P ' . (int) $pidServer . ' 2>/dev/null; kill ' . (int) $pidServer . ' 2>/dev/null');
    }
    foreach ([$bazaWp, $bazaNoua] as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        } catch (Throwable) {
        }
    }
    try {
        $root->exec("DROP USER IF EXISTS '" . $utilizatorDb . "'@'localhost'");
    } catch (Throwable) {
    }
    if (getenv('CMS_DEV_PASTREAZA') !== '1') {
        exec('rm -rf ' . escapeshellarg($lucru));
    }
});

// PHP-ul scripturilor de deploy și al serverului: socketul bazei de test, emailurile prinse (mail-capturat.sh), fără internet.
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'sendmail_path=' . $radacina . '/tools/dev/mail-capturat.sh', '-d', 'error_reporting=E_ALL',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $lucru . '/php-erori.log', '-d', 'memory_limit=512M'];
// Serverul: OPcache-ul cu revalidarea la 4 secunde (Hostinger: cel puțin 2), ca după comutare index.php-ul WordPress compilat să mai
// răspundă câteva secunde de la aceeași cale: verificarea adoptării și revenirea trebuie să aștepte codul nou.
$iniServer = array_merge($ini, ['-d', 'opcache.enable=1', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=4']);
$php = PHP_BINARY . ' ' . implode(' ', $ini);
$mediu = static function (array $plus = []) use ($php, $lucru): array {
    $m = [];
    foreach (getenv() as $k => $v) {
        $m[$k] = $v;
    }
    return array_merge($m, ['PHP' => $php, 'CMS_DEV_MAIL_DIR' => $lucru . '/mail', 'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost'], $plus);
};
/** @return array{0: int, 1: string} */
$ruleaza = static function (array $comanda, string $intrare = '', array $plus = []) use ($mediu, $lucru): array {
    $proces = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, null, $mediu($plus));
    fwrite($tevi[0], $intrare);
    fclose($tevi[0]);
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    $cod = proc_close($proces);
    file_put_contents($lucru . '/comenzi.log', '$ ' . implode(' ', array_map('escapeshellarg', $comanda)) . "\n" . $iesire . "\n[cod " . $cod . "]\n\n", FILE_APPEND);
    return [$cod, $iesire];
};
$deploy = static fn (string $script, array $argumente, string $intrare = '', array $plus = []) => $ruleaza(array_merge(['bash', $radacina . '/deploy/' . $script . '.sh'],
    $argumente, ['--acasa=' . $acasa]), $intrare, $plus);
$git = static fn (string $dosar, string ...$a) => $ruleaza(array_merge(['git', '-C', $dosar, '-c', 'user.email=test@exemplu.ro', '-c', 'user.name=Test'], $a));
/** @return array{cod: int, antete: array<string, string>, corp: string, locatie: string} */
$cerere = static function (string $cale, ?array $post = null, array $antete = []) use ($url): array {
    $c = curl_init($url . $cale);
    $h = [];
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $antete, CURLOPT_HEADERFUNCTION => static function ($c, string $l) use (&$h): int {
            if (str_contains($l, ':')) {
                [$n, $v] = explode(':', $l, 2);
                $h[strtolower(trim($n))] = trim($v);
            }
            return strlen($l);
        }] + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)] : []));
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $h, 'corp' => $corp, 'locatie' => (string) ($h['location'] ?? '')];
};
/** Amprenta unui arbore: fiecare fișier (calea, drepturile, sha256) și fiecare dosar și legătură. */
$amprentaArbore = static function (string $dosar): array {
    $rez = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($dosar) + 1);
        $rez[$rel] = $f->isLink() ? 'legatura:' . readlink($f->getPathname()) : ($f->isDir() ? 'dosar:' . decoct(fileperms($f->getPathname()) & 0777)
            : decoct(fileperms($f->getPathname()) & 0777) . ':' . hash_file('sha256', $f->getPathname()));
    }
    ksort($rez);
    return $rez;
};
$amprentaBaza = static function (string $baza) use ($root): array {
    $rez = [];
    foreach ($root->query('SHOW TABLES FROM `' . $baza . '`')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $rez[$t] = (string) $root->query('CHECKSUM TABLE `' . $baza . '`.`' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
    }
    return $rez;
};
$scurt = static fn (string $t): string => mb_substr(trim($t), -2500);

try {
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('1. Găzduirea simulată: WordPress cu baza lui, captura, baza nouă, versiunea, licența, serverul');
    $root->exec("DROP USER IF EXISTS '" . $utilizatorDb . "'@'localhost'");
    $root->exec("CREATE USER '" . $utilizatorDb . "'@'localhost' IDENTIFIED BY '" . $parolaDb . "'");
    $ids = wordpressProba($root, $bazaWp, $public, ['divi4' => true, 'url' => $url, 'utilizator' => $utilizatorDb, 'parola' => $parolaDb]);
    $root->exec('DROP DATABASE IF EXISTS `' . $bazaNoua . '`');
    $root->exec('CREATE DATABASE `' . $bazaNoua . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ([$bazaWp, $bazaNoua] as $b) {
        $root->exec('GRANT ALL PRIVILEGES ON `' . $b . '`.* TO \'' . $utilizatorDb . '\'@\'localhost\'');
    }
    // Fișierele statice ale temei (pe disc, în WordPress), cerute de paginile capturii: foaia temei (cu o adresă proprie absolută,
    // pe care copia o face relativă), foaia „late” a fiecărei pagini (et-cache), o foaie din wp-includes, un font, o poză, un script.
    $poza = imagecreatetruecolor(8, 8);
    imagefill($poza, 0, 0, imagecolorallocate($poza, 30, 120, 90));
    @mkdir($public . '/wp-content/themes/Divi/images', 0775, true);
    @mkdir($public . '/wp-content/themes/Divi/core/fonts', 0775, true);
    @mkdir($public . '/wp-content/themes/Divi/js', 0775, true);
    @mkdir($public . '/wp-includes/css', 0775, true);
    imagepng($poza, $public . '/wp-content/themes/Divi/images/sigla.png');
    file_put_contents($public . '/wp-content/themes/Divi/style.css', "body{color:#666}\n.sigla{background:url(" . $url . "/wp-content/themes/Divi/images/sigla.png)}\n"
        . "@font-face{font-family:Iconite;src:url(core/fonts/iconite.woff2) format('woff2')}\n");
    file_put_contents($public . '/wp-content/themes/Divi/core/fonts/iconite.woff2', "wOF2\x00\x01\x00\x00proba-font-inventat");
    file_put_contents($public . '/wp-content/themes/Divi/js/proba.min.js', "window.proba=1;\n");
    file_put_contents($public . '/wp-includes/css/dashicons.min.css', ".dashicons{display:inline-block}\n");
    $i = \App\Support\ImportWordPress\ImportWordPress::planifica($public, $url, [], ['fara' => ['articole']], [])['inventar'];
    $prima = (int) $i->site['prima_pagina'];
    // Subsolul Theme Builder (pachetul: import-wordpress.json → stiluri_comune, subsolul din „/”), ca pe site-ul real: pe Acasă și pe
    // cele mai multe pagini în foaia et-cache, cu adresa proprie absolută a pozei de fundal (copia o face relativă); pe Camere regulile
    // vechi rămase în cache; pe Contact același subsol doar în <style>-ul critic din HTML, cu adresa absolută și scris altfel (ca pe
    // Contact, Galerie, Politica și Cookie Policy pe site-ul real, unde planul de pe server dădea NU); pe Politica o regulă veche doar
    // în <style> (ATENȚIE, nu NU).
    $subsolNou = "\n.et_pb_section_0_tb_footer.et_pb_section{padding-top:40px}\n.et_pb_text_0_tb_footer{font-size:15px}\n"
        . 'div.et_pb_section_0_tb_footer{background-image:url(' . $url . "/wp-content/themes/Divi/images/sigla.png)!important}\n"
        . '@media only screen and (max-width:980px){.et_pb_text_0_tb_footer{font-size:13px}}';
    $stilInline = ['contact' => str_replace(['{', ';', '}'], [' { ', '; ', " }\n"], $subsolNou), 'politica-de-confidentialitate' => '.et_pb_text_0_tb_footer{font-size:11px}'];
    $paginiProba = [];
    foreach ($i->pagini as $id => $pg) {
        if ($pg['stare'] === 'publish' && !$pg['parola']) {
            $paginiProba[] = ['cale' => $id === $prima ? '/' : (string) ($pg['cale_wp'] ?? $pg['cale']), 'titlu' => (string) $pg['titlu'], 'id' => (int) $id,
                'formular' => $pg['slug'] === 'contact', 'seoTitlu' => (string) ($i->seo[$id]['titlu'] ?? ''), 'descriere' => (string) ($i->seo[$id]['descriere'] ?? ''),
                'stil' => $stilInline[$pg['slug']] ?? '',
                // Pe Camere, două module text: modelul (et_pb_text_1) și ținta (et_pb_text_2, rămasă cu regulile vechi în foaie; mai jos).
                'inCorp' => $pg['slug'] === 'camere' ? '<div class="et_pb_module et_pb_text et_pb_text_1"><div class="et_pb_text_inner"><p>45 min</p></div></div>'
                    . '<div class="et_pb_module et_pb_text et_pb_text_2"><div class="et_pb_text_inner"><p>1h 18min</p></div></div>'
                    // Pe Politica, ca pe Cookie Policy a site-ului real: managerul consimțământului și niciun banner (șablonul
                    // managerului îl pune cadrul doar la răspunsul web).
                    : ($pg['slug'] === 'politica-de-confidentialitate' ? '<div id="cmplz-manage-consent-container" class="cmplz-manage-consent-container"></div>' : ''),
                'faraBanner' => $pg['slug'] === 'politica-de-confidentialitate'];
        }
    }
    $captura = $site . '/capturat';
    capturaDiviProba($captura, $paginiProba, ['url' => $url, 'nonce' => 'dddd777777', 'ua' => 'windows', 'an' => date('Y'), 'antet' => $ids['tb_antet'],
        'subsol' => $ids['tb_subsol'], 'corp404' => $ids['tb_corp404'], 'meniu' => [['/', 'Acasă'], ['/camere/', 'Camere'], ['/contact/', 'Contact']],
        'cale404' => '/x1-inexistent/', 'credit' => true]);
    // Manifestul capturii: fișierele cerute de pagini (ca la o captură făcută pe server cu --wp: sursa „disc”), fonturile Google.
    $fisiere = [];
    $cere = static function (string $cale, string $fel) use (&$fisiere, $public, $url, $paginiProba): void {
        $fisiere[] = ['url' => $url . $cale, 'cale' => $cale, 'fel' => $fel, 'sha256' => hash_file('sha256', $public . $cale), 'sha256_sursa' => hash_file('sha256', $public . $cale),
            'marime' => filesize($public . $cale), 'tip' => null, 'sursa' => 'disc', 'pagini' => array_column($paginiProba, 'cale'), 'publicat' => $cale, 'sha256_publicat' => null];
    };
    $cere('/wp-content/themes/Divi/style.css', 'foaie');
    $cere('/wp-content/themes/Divi/images/sigla.png', 'imagine');
    $cere('/wp-content/themes/Divi/core/fonts/iconite.woff2', 'font');
    $cere('/wp-content/themes/Divi/js/proba.min.js', 'script');
    $cere('/wp-includes/css/dashicons.min.css', 'foaie');
    // Foaia et-cache a fiecărei pagini (subsolul, mai sus): pe Camere regulile vechi, pe paginile cu subsolul în <style> nimic, pe
    // celelalte cele de acum, ca pe Acasă.
    $foiLate = [];
    foreach ($paginiProba as $pg) {
        $late = '/wp-content/et-cache/' . $pg['id'] . '/et-proba-late.css';
        @mkdir(dirname($public . $late), 0775, true);
        $foiLate[$pg['cale']] = [$late, '.et_pb_section_' . $pg['id'] . '{padding:1px}' . ($pg['cale'] === '/camere/'
            ? "\n.et_pb_text_1.et_pb_text{color:#123456}\n.et_pb_text_2.et_pb_text{color:#999}\n.et_pb_text_0,.et_pb_text_1,.et_pb_text_2{padding:2px}"
                . "\n.et_pb_section_0_tb_footer{padding:9px}\n@media only screen and (max-width:980px){.et_pb_text_0_tb_footer{font-size:11px}.et_pb_section_" . $pg['id']
                . '{margin:0}.et_pb_text_1{text-align:center}}'
            : ($pg['stil'] !== '' ? '' : $subsolNou)) . "\n"];
        file_put_contents($public . $late, $foiLate[$pg['cale']][1]);
        $cere($late, 'foaie');
    }
    $cere('/wp-content/uploads/2024/05/camera-dubla-300x200.jpg', 'imagine');
    $urlFont = 'https://fonts.googleapis.com/css?family=Proba+Sans:400,700&display=swap';
    $cheieFont = FonturiGoogle::cheie($urlFont);
    @mkdir($captura . '/fonturi', 0770, true);
    $woff = "wOF2\x00\x01\x00\x00proba-sans-inventat";
    file_put_contents($captura . '/fonturi/' . substr(hash('sha256', 'proba-sans'), 0, 16) . '.woff2', $woff);
    $css = "@font-face{font-family:'Proba Sans';src:url(/capturat/fonturi/" . substr(hash('sha256', 'proba-sans'), 0, 16) . ".woff2) format('woff2')}\n";
    file_put_contents($captura . '/fonturi/' . $cheieFont . '.css', $css);
    $m = json_decode((string) file_get_contents($captura . '/manifest.json'), true);
    $m['fisiere'] = $fisiere;
    $m['fonturi'] = [['url' => $urlFont, 'css' => '/capturat/fonturi/' . $cheieFont . '.css', 'fisier_css' => $cheieFont . '.css', 'sha256_css' => hash('sha256', $css),
        'fonturi' => [['url' => 'https://fonts.gstatic.com/s/proba/proba.woff2', 'cale' => '/capturat/fonturi/' . substr(hash('sha256', 'proba-sans'), 0, 16) . '.woff2',
            'fisier' => substr(hash('sha256', 'proba-sans'), 0, 16) . '.woff2', 'sha256' => hash('sha256', $woff)]]]];
    $m['fonturi_dosar'] = FonturiGoogle::DOSAR_CAPTURA;
    file_put_contents($captura . '/manifest.json', json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $shaCaptura = sigileazaCapturaProba($captura, $url);
    // Scripturile aruncate ale capturii inventate (ca lista revizuită din privat/import-wordpress.json a site-ului real).
    $dosarC = DosarCapturi::deschide($captura);
    $aruncate = [];
    foreach ($dosarC->toate() as $x) {
        $r = $dosarC->raspuns($x['url']);
        foreach ($r !== null && str_contains($r->corp, '<!DOCTYPE html>') ? (new Divi4())->transforma($r->corp, $url, [])->necunoscute : [] as $n) {
            if ($n['fel'] !== 'noscript' || str_contains($n['inceput'], 'googletagmanager')) {
                $aruncate[$n['sha256']] = ['sha256' => $n['sha256'], 'motiv' => 'proba: ' . ($n['id'] ?? $n['inceput'])];
            }
        }
    }
    @mkdir($site . '/privat', 0700, true);
    file_put_contents($site . '/privat/import-wordpress.json', json_encode(['scripturi_aruncate' => array_values($aruncate)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Statisticile păstrate (ca pe copie): identificatorii inventați ai trackerelor declarate în tema pachetului. Trackerele intră
    // în pagină doar la răspunsul web (ax-date → ax_urmarire); P1 la import compară fără ele.
    file_put_contents($site . '/privat/urmarire.json', json_encode(['gtag' => 'G-PROBA12345', 'gtm' => 'GTM-PROBA12', 'clarity' => 'proba12345']));
    $verifica('WordPress de probă (Divi 4) în public_html, baza lui, captura cu ' . count($fisiere) . ' fișiere cerute de pagini și fontul Google, '
        . count($aruncate) . ' scripturi aruncate în site/privat/', is_file($public . '/wp-config.php') && is_file($captura . '/raport-captura.json') && $aruncate !== []);

    // Repo-ul: codul de acum (fișierele urmărite de git) și pachetul pensiune-proba (al lui pensiune, cu manifestul refăcut).
    @mkdir($repo, 0770, true);
    $lista = (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard');
    foreach (array_filter(explode("\0", $lista)) as $f) {
        if (!is_file($radacina . '/' . $f) || is_link($radacina . '/' . $f) || str_starts_with($f, '.env') || str_contains($f, '/privat/')
            || (str_starts_with($f, 'storage/') && !in_array(basename($f), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($repo . '/' . $f), 0770, true);
        copy($radacina . '/' . $f, $repo . '/' . $f);
        chmod($repo . '/' . $f, fileperms($radacina . '/' . $f) & 0777);
    }
    $tintaPachet = $repo . '/site-uri/' . $slug;
    exec('cp -a ' . escapeshellarg($radacina . '/site-uri/pensiune') . ' ' . escapeshellarg($tintaPachet) . ' && rm -rf ' . escapeshellarg($tintaPachet . '/privat'));
    // Stilurile după model ale pachetului sunt ale paginilor site-ului real: aici, regula pentru modulele inventate de pe Camere.
    $regPachet = json_decode((string) file_get_contents($tintaPachet . '/import-wordpress.json'), true);
    $regPachet['stiluri_dupa_model'] = ['/camere/' => [['et_pb_text_2' => 'et_pb_text_1']]];
    // Adoptarea cu pachetul de dinainte, fără `legaturi` (ca importul de pe copie); regula vine apoi cu un reimport (secțiunea 3b).
    $legaturiPachet = $regPachet['legaturi'];
    unset($regPachet['legaturi']);
    file_put_contents($tintaPachet . '/import-wordpress.json', json_encode($regPachet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $manifestP = json_decode((string) file_get_contents($tintaPachet . '/pachet.json'), true);
    $manifestP['site'] = $slug;
    $manifestP['fisiere'] = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tintaPachet, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($tintaPachet) + 1);
        if ($rel !== 'pachet.json') {
            $manifestP['fisiere'][$rel] = hash_file('sha256', $f->getPathname());
        }
    }
    ksort($manifestP['fisiere']);
    file_put_contents($tintaPachet . '/pachet.json', json_encode($manifestP, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $git($repo, 'init', '-q');
    $git($repo, 'add', '-A');
    $git($repo, 'commit', '-qm', 'codul de acum, cu pachetul pensiune-proba');
    [$c, $o] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $versiune = preg_match('/Versiunea de cod ([0-9.]+-[0-9a-f]+)/', $o, $mv) === 1 ? $mv[1] : '';
    $verifica('deploy/release.sh --aplica: versiunea ' . $versiune, $c === 0 && $versiune !== '' && is_dir($acasa . '/platforma/releases/' . $versiune), $scurt($o));
    $cheiePublica = trim((string) shell_exec('CMS_DEV_DIR=' . escapeshellarg($lucru) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' cheie'));
    @mkdir($site . '/storage/licenta', 0770, true);
    [$c, $o] = $ruleaza([PHP_BINARY, $radacina . '/tools/dev/licenta.php', 'toate', '--site=' . $slug], '', ['CMS_DEV_DIR' => $lucru,
        'LICENTA_DOSAR' => $site . '/storage/licenta', 'LICENTA_SITE' => $slug, 'LICENTA_CHEIE_PUBLICA' => $cheiePublica, 'DB_DATABASE' => 'cms_dwp_nu_exista']);
    $verifica('licența de test în site/storage/licenta', $c === 0 && is_file($site . '/storage/licenta/licenta.json'), $scurt($o));
    file_put_contents($lucru . '/cron.txt', '*/15 * * * * wget -q -O - ' . $url . '/wp-cron.php?doing_wp_cron >/dev/null 2>&1 # ' . $domeniu . "\n");

    // Serverul: ce fac .htaccess-urile pe Hostinger (fișierele care există, .php-urile din rădăcină rulate, restul la index.php).
    file_put_contents($lucru . '/router.php', <<<'PHP'
<?php
$doc = (string) getenv('GAZDA_DOCROOT');
$cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
if (preg_match('#(^|/)\.(?!well-known/)#', $cale) === 1 || str_contains($cale, '..')) {
    http_response_code(403);
    return true;
}
$f = $doc . $cale;
if ($cale !== '/' && is_file($f)) {
    if (str_ends_with($f, '.php')) {
        if (preg_match('#^/(wp-content|wp-includes|capturat)/#', $cale) === 1) {
            http_response_code(403); // .htaccess-urile arborilor copiați: nimic nu se execută
            return true;
        }
        $_SERVER['SCRIPT_FILENAME'] = $f;
        $_SERVER['SCRIPT_NAME'] = $cale;
        chdir(dirname($f));
        require $f;
        return true;
    }
    $tipuri = ['css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2',
        'xml' => 'application/xml', 'html' => 'text/html', 'txt' => 'text/plain'];
    header('Content-Type: ' . ($tipuri[strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $doc . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
require $doc . '/index.php';
return true;
PHP);
    $envServer = ['GAZDA_DOCROOT' => $public, 'PATH' => (string) getenv('PATH'), 'LANG' => 'C.UTF-8', 'CMS_DEV_MAIL_DIR' => $lucru . '/mail', 'PHP_CLI_SERVER_WORKERS' => '2',
        'http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9',
        'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost'];
    $envText = '';
    foreach ($envServer as $k => $v) {
        $envText .= ' ' . $k . '=' . escapeshellarg($v);
    }
    $pidServer = (int) trim((string) shell_exec('cd ' . escapeshellarg($lucru) . ' && env -i' . $envText . ' nohup ' . escapeshellarg(PHP_BINARY) . ' '
        . implode(' ', array_map('escapeshellarg', $iniServer)) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($lucru) . ' ' . escapeshellarg($lucru . '/router.php')
        . ' >> ' . escapeshellarg($lucru . '/server.log') . ' 2>&1 & echo $!'));
    $wpAcasa = ['cod' => 0, 'corp' => ''];
    for ($n = 0; $n < 50 && $wpAcasa['cod'] === 0; $n++) {
        usleep(200000);
        $wpAcasa = $cerere('/');
    }
    $verifica('serverul pe ' . $url . ': WordPress răspunde (200)', $wpAcasa['cod'] === 200 && str_contains($wpAcasa['corp'], 'WORDPRESS PROBA'), $wpAcasa['corp']);
    $amprentaWp = $amprentaArbore($public);
    $amprentaBazaWp = $amprentaBaza($bazaWp);

    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('2. Baza nouă, planul, adoptarea (--aplica, toate fazele)');
    $comuna = ['--site=' . $slug, '--domeniu=' . $domeniu];
    [$c, $o] = $deploy('adopta-wp', array_merge($comuna, ['--baza-noua']), $bazaNoua . "\n" . $utilizatorDb . "\n" . $parolaDb . "\nlocalhost\n");
    $verifica('--baza-noua: datele întrebate pe ecran, baza goală verificată, scrise în site/.env (0600)', $c === 0 && str_contains($o, 'răspunde și e goală')
        && (fileperms($site . '/.env') & 0777) === 0600 && str_contains((string) file_get_contents($site . '/.env'), 'DB_DATABASE=' . $bazaNoua), $scurt($o));
    $adopta = array_merge($comuna, ['--versiune=' . $versiune, '--url=' . $url, '--cheie-licenta=' . $cheiePublica, '--admin-email=proprietar@exemplu.ro',
        '--cron-vechi=' . $lucru . '/cron.txt', '--sha-captura=' . $shaCaptura]);
    [$c, $o] = $deploy('adopta-wp', $adopta);
    $verifica('planul: cod 0, niciun NU; captura găsită, planul importului și al fișierelor statice fără probleme, PHP-ul web cu sodium; nimic schimbat',
        $c === 0 && !preg_match('/^\s+NU /m', $o) && str_contains($o, 'captura site-ului live: ' . $captura) && str_contains($o, 'planul importului: fără probleme')
        && str_contains($o, 'planul fișierelor statice: fără probleme') && str_contains($o, 'stilurile comune (_tb_footer din /): /camere/ (1 foi de rescris)')
        && str_contains($o, 'stiluri_comune._tb_footer: /politica-de-confidentialitate/ are alte reguli decât / doar în <style>-ul din HTML (nu se rescrie; 1 reguli față de 4')
        && !str_contains($o, '/contact/ are alte reguli')
        && str_contains($o, 'sodium da') && str_contains($o, 'PLAN: site-ul n-a fost schimbat')
        && !is_dir($dosarSite . '/public_html.nou') && $amprentaArbore($public) === $amprentaWp, $scurt($o));
    // Întâi până după pregătire (ca o noapte în doi pași), apoi pregătirea din nou: APP_KEY se scrie o dată, rămâne la o reluare,
    // stă doar în site/.env (0600) și nu apare în nicio ieșire.
    [$c0, $o0] = $deploy('adopta-wp', array_merge($adopta, ['--aplica']), '', ['PLATFORMA_TEST_OPRESTE_DUPA' => 'inainte-instalare']);
    $cheie1 = preg_match('/^APP_KEY=([0-9a-f]{64})$/m', (string) @file_get_contents($site . '/.env'), $mk) === 1 ? $mk[1] : '';
    [$c1, $o1] = $deploy('adopta-wp', array_merge($adopta, ['--aplica', '--reia=pregatire']), '', ['PLATFORMA_TEST_OPRESTE_DUPA' => 'inainte-instalare']);
    $cheie2 = preg_match('/^APP_KEY=([0-9a-f]{64})$/m', (string) @file_get_contents($site . '/.env'), $mk) === 1 ? $mk[1] : '';
    $verifica('APP_KEY: scris la pregătire (64 de cifre hexa), același după pregătirea din nou (--reia=pregatire), .env 0600, în nicio ieșire a comenzilor',
        $c0 === 9 && $c1 === 9 && $cheie1 !== '' && $cheie1 === $cheie2 && (fileperms($site . '/.env') & 0777) === 0600
        && str_contains($o1, 'APP_KEY (cea de dinainte)') && !str_contains((string) file_get_contents($lucru . '/comenzi.log'), $cheie1), $scurt($o0 . $o1));
    [$c, $o] = $deploy('adopta-wp', array_merge($adopta, ['--aplica']));
    $verifica('APP_KEY neschimbat de restul adoptării', preg_match('/^APP_KEY=([0-9a-f]{64})$/m', (string) @file_get_contents($site . '/.env'), $mk) === 1 && $mk[1] === $cheie1);
    $stare = json_decode((string) @file_get_contents($site . '/deploy/adopta-wp.stare'), true);
    $faze = array_keys((array) ($stare['faze'] ?? []));
    $verifica('--aplica: GATA, fiecare fază făcută (verificari … deschidere)', $c === 0 && str_contains($o, '== GATA')
        && $faze === ['verificari', 'pregatire', 'instalare', 'import', 'copie', 'comutare', 'verificare', 'deschidere'], implode(', ', $faze) . ' | ' . $scurt($o));
    if ($c !== 0) {
        throw new RuntimeException('adoptarea n-a ajuns la GATA; restul pașilor n-au rost (ieșirea, mai sus)');
    }
    $verifica('pregătirea: fișierele statice copiate cu manifestul lor', str_contains($o0 . $o1, 'fișierele statice ale paginilor capturate')
        && is_file($site . '/storage/import-wordpress/statice.json'), $scurt($o1));
    // Stilurile comune: pe Camere, regulile vechi ale subsolului scoase (și din @media, unde rămâne regula paginii), cele de pe Acasă
    // puse la sfârșit; celelalte foi copiate neschimbate (servite de site-ul nou, la adresele lor); manifestul are sha256-ul foii
    // rescrise (diagnostic --capturat îl verifică).
    [$foaieCamere, $sursaCamere] = $foiLate['/camere/'];
    $idCamere = (string) preg_replace('#^/wp-content/et-cache/(\d+)/.*$#', '$1', $foaieCamere);
    // Întâi stilurile comune (subsolul), apoi după model (et_pb_text_2 după et_pb_text_1: regula ei veche scoasă, ale modelului împărțite).
    $asteptatCamere = '.et_pb_section_' . $idCamere . "{padding:1px}\n.et_pb_text_1.et_pb_text,.et_pb_text_2.et_pb_text{color:#123456}\n"
        . ".et_pb_text_0,.et_pb_text_1,.et_pb_text_2{padding:2px}\n@media only screen and (max-width:980px){.et_pb_section_" . $idCamere
        . "{margin:0}.et_pb_text_1,.et_pb_text_2{text-align:center}}\n" . str_replace($url, '', $subsolNou) . "\n";
    $statice = json_decode((string) file_get_contents($site . '/storage/import-wordpress/statice.json'), true);
    $intrareCamere = array_values(array_filter((array) ($statice['fisiere'] ?? []), static fn (array $f): bool => ($f['cale'] ?? '') === $foaieCamere))[0] ?? [];
    $servitCamere = $cerere($foaieCamere . '?ver=1')['corp'];
    $alteSchimbate = array_keys(array_filter($foiLate, static fn (array $x): bool => $x[0] !== $foaieCamere && $cerere($x[0] . '?ver=1')['corp'] !== str_replace($url, '', $x[1])));
    $randStiluri = str_contains($o0 . $o1, 'stilurile comune (_tb_footer din /): /camere/ (1 foi rescrise)') && str_contains($o1, '/politica-de-confidentialitate/ are alte reguli')
        && str_contains($o1, 'stilurile după model (/camere/): 1 seturi de module, 3 selectori adăugați, 2 scoși')
        && !str_contains($o0 . $o1, '/contact/ are alte reguli');
    $verifica('stilurile comune (subsolul din „/”) și după model (et_pb_text_2 după et_pb_text_1): foaia et-cache a Camerelor rescrisă (regulile vechi ale '
        . 'subsolului scoase, cele de pe Acasă la sfârșit, cu adresa relativă; ținta cu regulile modelului, în aceleași reguli), '
        . 'celelalte ' . (count($foiLate) - 1) . ' neschimbate, manifestul cu sha256-ul foii rescrise; Contact (același subsol doar în <style>, cu adresa absolută) '
        . 'egal, Politica (o regulă veche doar în <style>) ATENȚIE, nu NU',
        $randStiluri && $servitCamere === $asteptatCamere && $alteSchimbate === []
        && ($intrareCamere['sha256_publicat'] ?? '') === hash('sha256', $asteptatCamere) && ($intrareCamere['sha256_sursa'] ?? '') === hash('sha256', $sursaCamere),
        json_encode(['randul' => $randStiluri, 'servit' => $servitCamere, 'alte' => $alteSchimbate, 'manifest' => $intrareCamere], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    // Pe un site deja adoptat, schimbarea pachetului (stiluri_comune) vine cu o nouă publicare a fișierelor statice: rândul pe care
    // proprietarul îl rulează după actualizeaza.sh (docs/DEPLOY.md, „Stilurile comune”), cu lansatorul cronurilor și WordPress-ul
    // din public_html.vechi. Același rezultat, aceeași foaie servită, același manifest.
    [$cP, $oP] = $ruleaza(array_merge([PHP_BINARY], $ini, [$acasa . '/platforma/bin/platforma', '--site=' . $domeniu, 'scripts/publica-statice.php',
        '--wp=' . $dosarSite . '/public_html.vechi', '--url=' . $url, '--aplica']), '', ['HOME' => $acasa]);
    $statice2 = json_decode((string) file_get_contents($site . '/storage/import-wordpress/statice.json'), true);
    $intrareCamere2 = array_values(array_filter((array) ($statice2['fisiere'] ?? []), static fn (array $f): bool => ($f['cale'] ?? '') === $foaieCamere))[0] ?? [];
    $verifica('publicarea din nou pe site-ul adoptat (bin/platforma … scripts/publica-statice.php --wp=public_html.vechi --aplica): GATA, Camere rescrisă la fel, '
        . 'manifestul la fel', $cP === 0 && str_contains($oP, 'stilurile comune (_tb_footer din /): /camere/ (1 foi rescrise)') && str_contains($oP, 'GATA: fișierele statice')
        && $cerere($foaieCamere . '?ver=2')['corp'] === $asteptatCamere && ($intrareCamere2['sha256_publicat'] ?? '') === hash('sha256', $asteptatCamere)
        && count((array) ($statice2['fisiere'] ?? [])) === count((array) ($statice['fisiere'] ?? [])), $scurt($oP));
    $verifica('verificarea: diagnostic --capturat și fiecare fișier cerut de pagini la adresa lui, 200, aceiași octeți', str_contains($o, 'diagnostic.php --capturat: fiecare fișier')
        && preg_match('/fișierele cerute de pagini \(\d+: foile, fonturile, mediile\), la adresele lor: 200, aceiași octeți/u', $o) === 1, $scurt($o));

    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('3. Site-ul nou, deschis');
    $coduri = [];
    foreach (array_column($paginiProba, 'cale') as $cale) {
        $coduri[$cale] = $cerere($cale)['cod'];
    }
    $acasaNou = $cerere('/');
    $verifica('paginile (' . count($coduri) . '): 200, din documentele capturate (antetul, subsolul, runtime-ul compat), fără WordPress', array_values(array_unique($coduri)) === [200]
        && str_contains($acasaNou['corp'], 'et-l--header') && str_contains($acasaNou['corp'], '/assets/compat/') && !str_contains($acasaNou['corp'], 'WORDPRESS PROBA')
        && isset($acasaNou['antete']['content-security-policy']), json_encode($coduri) . ' ' . json_encode($acasaNou['antete']) . ' ' . substr($acasaNou['corp'], 0, 600));
    // Subsolul fiecărei pagini și al 404-ului: legătura pachetului (import-wordpress.json → legaturi) și legătura Divi prin script.
    $subsol = static function () use ($cerere): array {
        $rez = [];
        foreach (['/', '/camere/', '/x1-inexistent/'] as $c) {
            $corp = $cerere($c)['corp'];
            preg_match('#<script type="application/json" id="ax-date">(.*?)</script>#s', $corp, $mAx);
            $rez[$c] = ['legatura' => str_contains($corp, '<div class="et_pb_module et_pb_text et_pb_text_8_tb_footer  et_pb_text_align_center et_pb_bg_layout_light">')
                && str_contains($corp, '<p><a href="https://andaxi.ro" style="line-height:inherit">Powered by Andaxi Web Solutions</a></p>')
                && !str_contains($mAx[1] ?? '', 'et_pb_text_8_tb_footer'), 'divi' => str_contains($corp, 'et_pb_text_8_tb_footer et_clickable')
                && str_contains($mAx[1] ?? '', '"class":"et_pb_text_8_tb_footer"'), 'subsol' => str_contains($mAx[1] ?? '', 'et_pb_text_0_tb_footer'),
                'trackere' => str_contains($mAx[1] ?? '', '"ax_urmarire":[{"fel":"gtag"')];
        }
        return $rez;
    };
    $inainte = $subsol();
    $verifica('adoptarea cu pachetul de dinainte (fără legaturi): subsolul cu legătura Divi prin script, ca pe live; trackerele din privat/urmarire.json '
        . 'în ax-date doar la răspuns (P1 la import a trecut cu ele)', array_map(static fn (array $x): array => [$x['legatura'], $x['divi'], $x['subsol'], $x['trackere']], $inainte)
        === array_fill_keys(['/', '/camere/', '/x1-inexistent/'], [false, true, true, true]), json_encode($inainte));
    $foaie = $cerere('/wp-content/themes/Divi/style.css');
    $font = $cerere('/capturat/fonturi/' . $cheieFont . '.css');
    $verifica('fișierele statice: foaia temei cu adresa proprie relativă, fontul temei, foaia fontului Google, scriptul temei nepublicat',
        $foaie['cod'] === 200 && str_contains($foaie['corp'], 'url(/wp-content/themes/Divi/images/sigla.png)') && !str_contains($foaie['corp'], $url)
        && $cerere('/wp-content/themes/Divi/core/fonts/iconite.woff2')['cod'] === 200 && $font['cod'] === 200 && $font['corp'] === $css
        && $cerere('/wp-content/themes/Divi/js/proba.min.js')['cod'] === 404, $foaie['corp'] . ' | ' . $font['cod']);
    $camere = $cerere('/camere/');
    preg_match('#name="_wpnonce-et-pb-contact-form-submitted-0" value="([0-9a-f]+)"#', $camere['corp'], $mn);
    preg_match('#name="(et_pb_contact_[a-z_]+_0)"#', $camere['corp'], $mc);
    $post = ['et_pb_contactform_submit_0' => 'et_contact_proccess', '_wpnonce-et-pb-contact-form-submitted-0' => $mn[1] ?? '', 'ax_capcana' => '',
        ($mc[1] ?? 'et_pb_contact_name_0') => 'Vizitator de probă'];
    $mailInainte = count(glob($lucru . '/mail/*.eml') ?: []);
    $trimis = $cerere('/camere/', $post);
    $pdoNou = new PDO('mysql:unix_socket=' . $sock . ';dbname=' . $bazaNoua . ';charset=utf8mb4', $utilizatorDb, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $mesaj = $pdoNou->query('SELECT name, source_url, status FROM contact_form_messages ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $mailuri = glob($lucru . '/mail/*.eml') ?: [];
    sort($mailuri);
    $ultimulMail = $mailuri !== [] ? (string) file_get_contents((string) end($mailuri)) : '';
    $verifica('formularul din subsol: trimis fără JavaScript, mesajul de mulțumire în pagină, scris în baza nouă, emailul încercat (prins) la destinatarul din WordPress',
        $trimis['cod'] === 200 && preg_match('#<div class="et-pb-contact-message"><p>[^<]+</p></div>#', $trimis['corp']) === 1 && is_array($mesaj)
        && $mesaj['name'] === 'Vizitator de probă' && $mesaj['source_url'] === '/camere/' && $mesaj['status'] === 'new' && count($mailuri) === $mailInainte + 1
        && preg_match('/^To: .*@example\.test/m', $ultimulMail) === 1, $trimis['cod'] . ' ' . json_encode($mesaj, JSON_UNESCAPED_UNICODE) . ' ' . substr($ultimulMail, 0, 400)
        . ' | ' . json_encode($post) . ' | ' . (preg_match('#<div id="et_pb_contact_form_0".{0,1500}#s', $trimis['corp'], $mf) === 1 ? $mf[0] : substr($trimis['corp'], 0, 300)));
    $cautare = $cerere('/?s=camere');
    $verifica('căutarea: /?s=camere în cadrul site-ului, cu pagina Camere, noindex', $cautare['cod'] === 200 && str_contains($cautare['corp'], 'href="/camere/"')
        && str_contains($cautare['corp'], 'Rezultatele căutării') && str_contains((string) ($cautare['antete']['x-robots-tag'] ?? ''), 'noindex'), substr($cautare['corp'], 0, 300));
    $adrese = ['/camere' => [301, '/camere/'], '/?page_id=' . $ids['camere'] => [301, '/camere/'], '/camerele-noastre/' => [301, '/camere/'], '/hello-world/' => [301, '/'],
        '/wp-admin/' => [410, ''], '/wp-login.php' => [410, ''], '/xmlrpc.php' => [410, '']];
    $rele = [];
    foreach ($adrese as $cale => [$cod, $spre]) {
        $r = $cerere($cale);
        if ($r['cod'] !== $cod || ($spre !== '' && !str_ends_with(parse_url($r['locatie'], PHP_URL_PATH) ?? '', $spre))) {
            $rele[] = $cale . ' → ' . $r['cod'] . ' ' . $r['locatie'];
        }
    }
    $verifica('adresele vechi: /camere → /camere/, ?page_id= (harta WordPress), slug-ul vechi, /hello-world/ → / (301); /wp-admin/, /wp-login.php, /xmlrpc.php: 410',
        $rele === [], implode('; ', $rele));
    $cs = $cerere('/contact/send', ['name' => 'Ocolire', 'email' => 'o@exemplu.ro', 'subject' => 's', 'message' => 'm']);
    $verifica('/contact/send al nucleului: 404 pe site-ul adoptat (doar formularele capturate primesc mesaje)', $cs['cod'] === 404, (string) $cs['cod']);
    $harta = (int) $pdoNou->query('SELECT COUNT(*) FROM wp_harta')->fetchColumn();
    $verifica('importul a scris harta WordPress (wp_harta) pentru ?page_id= și ?p=', $harta > 0, (string) $harta);
    [$c, $o] = $ruleaza(array_merge(explode(' ', $php), [$acasa . '/platforma/releases/' . $versiune . '/bin/platforma', '--site=' . $site, 'scripts/diagnostic.php', '--capturat']));
    $verifica('diagnostic.php --capturat după deschidere: rezultat ok', $c === 0 && str_contains($o, 'rezultat     : ok'), $scurt($o));
    $verifica('WordPress neatins de adoptare (public_html.vechi = arborele de dinainte; baza lui la fel)', $amprentaArbore($dosarSite . '/public_html.vechi') === $amprentaWp
        && $amprentaBaza($bazaWp) === $amprentaBazaWp);

    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('3b. Reimportul pe baza deja importată (ca pe copie): pachetul cu legaturi, importul --plan și --aplica');
    $rp = json_decode((string) file_get_contents($site . '/pachet/import-wordpress.json'), true);
    $rp['legaturi'] = $legaturiPachet;
    file_put_contents($site . '/pachet/import-wordpress.json', json_encode($rp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    // Rândul proprietarului, cu lansatorul cronurilor și WordPress-ul din public_html.vechi.
    $import = static fn (string $mod): array => $ruleaza(array_merge([PHP_BINARY], $ini, [$acasa . '/platforma/bin/platforma', '--site=' . $domeniu,
        'scripts/importa-wordpress.php', '--wp=' . $dosarSite . '/public_html.vechi', '--url=' . $url, '--captura=' . $captura, $mod]), '', ['HOME' => $acasa]);
    [$cI, $oI] = $import('--plan');
    $verifica('reimportul --plan: cod 0, niciun NU, captura cu aceleași documente', $cI === 0 && !preg_match('/^\s+NU\s/m', $oI) && str_contains($oI, 'captura: documente pagina 6, 404 1'),
        $scurt($oI));
    [$cI, $oI] = $import('--aplica');
    $verifica('reimportul --aplica: GATA, P1 la import trece pe fiecare document (cu trackerele în privat și managerul Cookie Policy fără banner)', $cI === 0
        && str_contains($oI, 'P1 la import: fiecare document randat din bază = T') && str_contains($oI, 'GATA: importul WordPress e făcut'), $scurt($oI));
    $dupaReimport = $subsol();
    $verifica('după reimport: „Powered by Andaxi Web Solutions” e legătura <a href="https://andaxi.ro"> pe Acasă, Camere și 404, fără legătura Divi prin '
        . 'script; restul subsolului și trackerele ca înainte', array_map(static fn (array $x): array => [$x['legatura'], $x['divi'], $x['subsol'], $x['trackere']], $dupaReimport)
        === array_fill_keys(['/', '/camere/', '/x1-inexistent/'], [true, false, true, true]), json_encode($dupaReimport));
    $politica = $cerere('/politica-de-confidentialitate/')['corp'];
    $verifica('pe pagina cu managerul consimțământului și fără banner: șablonul managerului (din fragmentul bannerului) pus doar la răspuns',
        str_contains($politica, '<template id="ax-cmplz-sablon"><div id="cmplz-cookiebanner-container">') && substr_count($politica, 'ax-cmplz-sablon') === 1,
        substr($politica, -600));
    [$c, $o] = $ruleaza(array_merge(explode(' ', $php), [$acasa . '/platforma/releases/' . $versiune . '/bin/platforma', '--site=' . $site, 'scripts/verifica-randare.php', '--capturat']));
    $verifica('scripts/verifica-randare.php --capturat după reimport: amprentele randării aprobate (fără trackere și fără șablon)', $c === 0, $scurt($o));

    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('4. Revenirea la WordPress (deploy/revino.sh --aplica)');
    [$c, $o] = $deploy('revino', array_merge($comuna, ['--url=' . $url, '--aplica']));
    // OPcache-ul serverului revalidează un fișier compilat după cel mult 2 secunde (opcache.revalidate_freq): index.php al
    // stub-ului și cel al WordPress au aceeași cale.
    sleep(3);
    $dupa = $amprentaArbore($public);
    $dif = array_keys(array_diff_assoc($dupa, $amprentaWp) + array_diff_assoc($amprentaWp, $dupa));
    $wpDinNou = $cerere('/');
    $verifica('revino.sh: GATA, WordPress din nou în public_html, octet cu octet (fiecare fișier și drepturile lui) ca înainte de adoptare, răspunde 200',
        $c === 0 && str_contains($o, 'WordPress e octet cu octet cum era la comutare') && $dif === [] && $wpDinNou['cod'] === 200
        && str_contains($wpDinNou['corp'], 'WORDPRESS PROBA'), 'cod ' . $c . ', ' . $wpDinNou['cod'] . ' ' . substr($wpDinNou['corp'], 0, 200) . ' dif: ' . implode(', ', array_slice($dif, 0, 10)) . ' | ' . $scurt($o));
    $verifica('baza WordPress neatinsă (CHECKSUM TABLE pe fiecare tabel); baza nouă rămâne, cu mesajul formularului', $amprentaBaza($bazaWp) === $amprentaBazaWp
        && (int) $pdoNou->query('SELECT COUNT(*) FROM contact_form_messages')->fetchColumn() === 1);
} catch (Throwable $e) {
    $verifica('fără excepții', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
