<?php

declare(strict_types=1);

/**
 * Testele importului WordPress (App\Support\ImportWordPress, scripts/importa-wordpress.php, deploy/lib/adopta-wp.php):
 * câte un test pentru fiecare corectură 1.1–1.15 a codului de adoptare și pentru dialectul divi4 (pasul 5a, pe o captură
 * inventată: aruncatele în sha256, ax-date, handlerele, head-ul, politica, recaptura, NU-urile), pe site-ul WordPress INVENTAT din
 * tools/dev/lib/wordpress-proba.php („Pensiunea Probă”, cu opțiunile divi, divi4 și unicode). Nicio dată a unui client.
 *
 *   tools/dev/php.sh tools/dev/test-import-wordpress.php
 *
 * Ce face: baza WordPress de probă (cms_imp_wp, prefixul wpx_) și dosarul ei, baza site-ului nou (cms_imp_site, făcută de
 * scripts/install.php, fără licență: doar nucleul), un dosar de site (storage/, harta importului) și un docroot nou, toate
 * în $CMS_DEV_DIR/test-import-wordpress/. Planul și importul rulează în proces (cu dosarul site-ului de probă, prin
 * PLATFORMA_SITE_DIR) și prin scripts/importa-wordpress.php (cu PLATFORMA_PUBLIC_DIR, ca bin/platforma --public);
 * deploy/lib/adopta-wp.php rulează cu --plan pe o găzduire simulată (doar verificările: nimic schimbat).
 *
 * Bazele și dosarul se șterg la final. Raport: $CMS_DEV_DIR/test-import-wordpress-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-import-wordpress';
// Capturile de probă stau în afara repo-ului: importul refuză o captură din repo, ca unealta capturii (recenzia 2, R3-5).
$lucruCapturi = rtrim((string) (getenv('CMS_DEV_TMP') ?: sys_get_temp_dir()), '/') . '/cms-test-wp-capturi-' . getmypid();
register_shutdown_function(static function () use ($lucruCapturi): void {
    if (str_contains($lucruCapturi, '/cms-test-wp-capturi-')) {
        exec('rm -rf ' . escapeshellarg($lucruCapturi));
    }
});
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'public', 'pachet', 'pachet-capturat', 'licenta', 'captura', 'acasa/domains/proba.test'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}
// Dosarul site-ului de probă (harta importului, manifestul mediilor) și docroot-ul nou, ca bin/platforma --site --public.
define('PLATFORMA_SITE_DIR', $lucru . '/site');
define('PLATFORMA_PUBLIC_DIR', $lucru . '/public');

require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/wordpress-proba.php';
require_once $radacina . '/deploy/lib/deploy.php';
require_once $radacina . '/deploy/lib/wordpress.php';
require_once $radacina . '/tools/dev/lib/captura-divi-proba.php';

use App\Support\ImportWordPress\Adrese;
use App\Support\ImportWordPress\Amprente;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Captura\ListaAdrese;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Context;
use App\Support\ImportWordPress\Continut\Convertor;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Shortcoduri;
use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\ImportWordPress;
use App\Support\ImportWordPress\Importator;
use App\Support\ImportWordPress\Inventar;
use App\Support\ImportWordPress\Media;
use App\Support\ImportWordPress\Plan;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Support\Paths;
use Platforma\Deploy\WordPress;

$fisierRaport = $dosarDev . '/test-import-wordpress-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . $detalii : ''));
    }
};
$scrie('Test importul WordPress (corecturile 1.1–1.15) — ' . date('Y-m-d H:i:s'));

$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'), (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bazaWp = 'cms_imp_wp';
$bazaSite = 'cms_imp_site';
register_shutdown_function(static function () use ($root, $bazaWp, $bazaSite, $lucru): void {
    // Conexiunile WordPress (tranzacția READ ONLY a SursaWordPress) se închid întâi: altfel DROP DATABASE le-ar aștepta.
    foreach (['necapturat', 'capturat', 'db'] as $v) {
        $GLOBALS[$v] = null;
    }
    gc_collect_cycles();
    foreach ([$bazaWp, $bazaSite] as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        } catch (Throwable) {
        }
    }
    exec('rm -rf ' . escapeshellarg($lucru));
});
$conectare = static fn (string $baza): PDO => new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $baza . ';charset=utf8mb4',
    (string) (getenv('CMS_DEV_DB_USER') ?: 'root'), (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/** Un script al proiectului prin tools/dev/php.sh, cu dosarul site-ului și docroot-ul de probă: [cod, stdout, stderr]. */
$preludiu = $lucru . '/preludiu.php';
file_put_contents($preludiu, "<?php\ndefine('PLATFORMA_SITE_DIR', " . var_export($lucru . '/site', true) . ");\ndefine('PLATFORMA_PUBLIC_DIR', "
    . var_export($lucru . '/public', true) . ");\n");
$php = static function (array $script, array $mediu = [], bool $cuPreludiu = true) use ($radacina, $preludiu, $lucru, $bazaSite): array {
    $env = array_merge(getenv(), ['CMS_DEV_DB' => $bazaSite, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta'], $mediu);
    $comanda = array_merge([$radacina . '/tools/dev/php.sh'], $cuPreludiu ? ['-d', 'auto_prepend_file=' . $preludiu] : [], $script);
    $p = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out, $err];
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Pregătirea: WordPress-ul de probă, baza site-ului nou, pachetele');
$dosarWp = $lucru . '/acasa/domains/proba.test/public_html';
@mkdir($dosarWp, 0770, true);
$ids = wordpressProba($root, $bazaWp, $dosarWp, ['prefix' => 'wpx_', 'divi' => true, 'divi4' => true, 'unicode' => true]);
$root->exec('DROP DATABASE IF EXISTS `' . $bazaSite . '`');
$root->exec('CREATE DATABASE `' . $bazaSite . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
[$cod, $out, $err] = $php(['scripts/install.php'], [], false);
$verifica('scripts/install.php pe baza site-ului nou (cu nucleu/0068, tabelul import_amprente)', $cod === 0, trim($out . $err));
$db = $conectare($bazaSite);
$verifica('baza site-ului are import_amprente', Amprente::disponibil($db));
// Pagina Divi a probei (Evenimente) nu are convertor pe drumul necapturat: lăsată deoparte, cu motivul.
$reguliNecapturat = ['pagini' => ['despre-noi' => ['slug' => 'despre-noi-aa'], 'evenimente' => ['importa' => false, 'motiv' => 'pagina Divi: doar pe drumul capturat']]];
file_put_contents($lucru . '/pachet/import-wordpress.json', (string) json_encode($reguliNecapturat, JSON_UNESCAPED_UNICODE));
file_put_contents($lucru . '/pachet-capturat/import-wordpress.json', (string) json_encode(['continut' => 'capturat', 'dialect' => 'divi4']));

/** Un convertor de dialect inventat (doar în test): pe drumul capturat, conținutul paginii ca un paragraf. */
$dialectProba = new class () implements Convertor {
    public function nume(): string
    {
        return 'dialectul de probă';
    }

    public function stie(string $constructor): bool
    {
        return $constructor === 'dialect:divi4';
    }

    public function converteste(array $pagina, Context $ctx): array
    {
        return ['html' => '<p>' . htmlspecialchars((string) $pagina['titlu']) . '</p>', 'css' => '', 'js' => '', 'probleme' => [], 'avertismente' => []];
    }

    public function design(Context $ctx): array
    {
        return ['design_header_html' => '<header>antet</header>'];
    }
};
$planifica = static function (array $reguli, array $convertoare = []) use ($dosarWp): array {
    return ImportWordPress::planifica($dosarWp, WP_PROBA_URL, $convertoare, ['fara' => ['articole']], $reguli);
};
$necapturat = $planifica($reguliNecapturat);
$capturat = $planifica(['continut' => 'capturat', 'dialect' => 'divi4'], [$dialectProba]);
$i = $necapturat['inventar'];
$pagina = static function (Plan $p, string $titlu): ?array {
    foreach ($p->pagini as $pg) {
        if ($pg['titlu'] === $titlu) {
            return $pg;
        }
    }
    return null;
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.1 Adresele: mediile rămân la /wp-content/uploads/, paginile păstrează „/” final');
$a = new Adrese(WP_PROBA_URL);
$a->adaugaPostare($ids['camere'], '/camere/', '/camere/');
$verifica('rescrie(): o poză absolută din uploads rămâne exact cum e', $a->rescrie(WP_PROBA_URL . '/wp-content/uploads/2024/05/camera-dubla.jpg')
    === WP_PROBA_URL . '/wp-content/uploads/2024/05/camera-dubla.jpg');
$verifica('rescrie(): una relativă, cu parametri și entități, la fel', $a->rescrie('/wp-content/uploads/2024/05/a.jpg?ver=1&amp;x=2') === '/wp-content/uploads/2024/05/a.jpg?ver=1&amp;x=2');
$verifica('media(): /wp-content/uploads/<cale> (nu /uploads/wordpress/)', Adrese::media('2024/05/camera dublă.jpg') === '/wp-content/uploads/2024/05/camera%20dubl%C4%83.jpg');
$verifica('o pagină păstrează „/” final: /camere → /camere/', $a->rescrie(WP_PROBA_URL . '/camere') === '/camere/' && $a->rescrie('/CAMERE/?x=1#y') === '/camere/?x=1#y');
$verifica('?page_id= pe prima pagină → calea paginii, cu „/”', $a->rescrie(WP_PROBA_URL . '/?page_id=' . $ids['camere']) === '/camere/');
$verifica('cheie() rămâne doar pentru comparații (fără „/”, decodată, cu litere mici)', Adrese::cheie('/Camere-%C8%99I/') === '/camere-și');
$verifica('caleWordPress(): segmentul Unicode în forma WordPress (hexa mic), cel deja codat neatins',
    Adrese::caleWordPress(['δωμάτια', 'camere-%c8%99i']) === '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/camere-%c8%99i/');
$verifica('fără „/” final în structură: /camere', Adrese::caleWordPress(['camere'], false) === '/camere');
$camereHtml = (string) ($pagina($necapturat['plan'], 'Camere & prețuri')['html'] ?? '');
$verifica('conținutul convertit: pozele la /wp-content/uploads/ (nicio adresă /uploads/wordpress/)',
    str_contains($camereHtml, '/wp-content/uploads/2024/05/') && !str_contains($camereHtml, '/uploads/wordpress/'), substr($camereHtml, 0, 300));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.2 Mediile: copiate în <docroot>/wp-content/uploads/<cale>, cu sha256; legăturile, codul PHP și fișierele peste 200 MB refuzate');
$uploadsWp = $dosarWp . '/wp-content/uploads';
@mkdir($uploadsWp . '/2024/07', 0775, true);
file_put_contents($uploadsWp . '/2024/07/ascuns.jpg', "\xFF\xD8\xFF<?php echo 1; ?>");
@symlink($uploadsWp . '/2024/05/camera-dubla.jpg', $uploadsWp . '/2024/07/legatura.jpg');
@mkdir($lucru . '/in-afara', 0775, true);
file_put_contents($lucru . '/in-afara/poza.jpg', 'jpg');
@symlink($lucru . '/in-afara', $uploadsWp . '/2024/dosar-legat');
$lista = Media::lista($i, $uploadsWp, ['2024/07/ascuns.jpg', '2024/07/legatura.jpg', '2024/dosar-legat/poza.jpg', '2024/05/camera-dubla.jpg']);
$sarite = implode(' | ', $lista['sarite']);
$verifica('un fișier cu <?php (oricare ar fi extensia) e sărit', str_contains($sarite, '2024/07/ascuns.jpg (conține <?php)'), $sarite);
$verifica('o legătură simbolică (fișierul) e sărită', str_contains($sarite, '2024/07/legatura.jpg (legătură simbolică)'), $sarite);
$verifica('un fișier dintr-un dosar-legătură (spre afara uploads) e sărit', str_contains($sarite, '2024/dosar-legat/poza.jpg (legătură simbolică)'), $sarite);
$verifica('poza obișnuită trece', isset($lista['fisiere']['2024/05/camera-dubla.jpg']));
$tintaTest = $lucru . '/copie/wp-content/uploads';
$c1 = Media::copiaza(['2024/05/camera-dubla.jpg' => $lista['fisiere']['2024/05/camera-dubla.jpg']], $tintaTest);
$c2 = Media::copiaza(['2024/05/camera-dubla.jpg' => $lista['fisiere']['2024/05/camera-dubla.jpg']], $tintaTest);
$verifica('copiaza(): aceiași octeți la aceeași cale, sha256 în manifest; a doua oară „la zi”', $c1['copiate'] === 1 && $c2['la_zi'] === 1
    && hash_file('sha256', $tintaTest . '/2024/05/camera-dubla.jpg') === hash_file('sha256', $uploadsWp . '/2024/05/camera-dubla.jpg')
    && ($c1['manifest']['2024/05/camera-dubla.jpg']['sha256'] ?? '') === hash_file('sha256', $uploadsWp . '/2024/05/camera-dubla.jpg')
    && ($c2['manifest']['2024/05/camera-dubla.jpg'] ?? null) === ($c1['manifest']['2024/05/camera-dubla.jpg'] ?? false));
unlink($uploadsWp . '/2024/07/ascuns.jpg');
unlink($uploadsWp . '/2024/07/legatura.jpg');
unlink($uploadsWp . '/2024/dosar-legat');
// PHP fără „<?php”: `<?=` (PHP îl rulează mereu) și `<?` cu spațiu (short_open_tag), într-un nume cu .php înăuntru, într-o poză
// poliglotă și într-un SVG; o poză cu cei trei octeți apăruți la întâmplare (fără text după ei) trece.
$png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR";
$fisiereScurte = [
    '2024/07/scurt-ecou.php.png' => $png . '<?= "RULAT-CA-PHP" ?>' . "\x00\xff",
    '2024/07/scurt-ecou.png' => $png . "tEXt\x00<?= `id` ?>\x00\x8f\xa1",
    '2024/07/scurt-deschis.png' => $png . "\xff\x00<? system(\$_GET['c']); ?>\x01\x02",
    '2024/07/scurt-final.jpg' => "\xFF\xD8\xFF\xE0<? echo 1;",
    '2024/07/scurt.svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><?= 1 ?></svg>',
    '2024/07/legitima.png' => $png . "\x00\x10<?=\x93\x00\xd1<? \x02\xfe\x7f<?\n\x80 date binare",
    '2024/07/legitima.svg' => '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1"/></svg>',
];
foreach ($fisiereScurte as $rel => $continut) {
    file_put_contents($uploadsWp . '/' . $rel, $continut);
}
$listaScurte = Media::lista($i, $uploadsWp, array_keys($fisiereScurte));
$sariteScurte = implode(' | ', $listaScurte['sarite']);
$verifica('PHP fără <?php: o extensie executabilă în nume (x.php.png), `<?=` și `<?` cu cod într-o poză (și fără `?>` la final), `<?=` într-un SVG: sărite',
    str_contains($sariteScurte, '2024/07/scurt-ecou.php.png (o extensie executabilă înăuntrul numelui)')
    && str_contains($sariteScurte, '2024/07/scurt-ecou.png (conține cod PHP după „<?” (compilează ca PHP))')
    && str_contains($sariteScurte, '2024/07/scurt-deschis.png (conține cod PHP după „<?” (compilează ca PHP))')
    && str_contains($sariteScurte, '2024/07/scurt-final.jpg (conține cod PHP după „<?” (compilează ca PHP))')
    && str_contains($sariteScurte, '2024/07/scurt.svg (conține <?= (PHP))'),
    $sariteScurte);
$verifica('o poză cu `<?=`, `<? ` și `<?\n` apăruți la întâmplare în octeții ei (fără text după ei) și un SVG cu `<?xml`: trec',
    isset($listaScurte['fisiere']['2024/07/legitima.png'], $listaScurte['fisiere']['2024/07/legitima.svg']), $sariteScurte);
foreach (array_keys($fisiereScurte) as $rel) {
    unlink($uploadsWp . '/' . $rel);
}
// M1b: cod PHP închis înaintea datelor binare prin tagul de închidere cu un octet non-ASCII în comentariu sau șir, ori prin
// __halt_compiler(): PHP tot îl rulează, deci se refuză (nu doar codul numai-ASCII). O poză cu octeți la întâmplare după un tag
// PHP scurt, pe care PHP n-o poate compila, trece. Coada binară inventată (0x80–0xBF) n-are octetul „mai mic", deci niciun al
// doilea tag PHP în ea.
$coada = '';
for ($b = 0; $b < 1500; $b++) {
    $coada .= chr(0x80 + ($b % 0x40));
}
$cod = '"RULAT-" . "CA-PHP"';
$fisierePoliglot = [
    '2024/08/halt.png' => $png . '<?= ' . $cod . '; __halt_compiler();' . $coada,
    '2024/08/comentariu-utf8.png' => $png . '<?=' . $cod . '/*ă*/?>' . $coada,
    '2024/08/sir-neascii.png' => $png . '<?=' . $cod . '." ț";?>' . $coada,
    '2024/08/intamplatoare.png' => $png . "\x00<?=\x93\x00\xd1<? \x02\xfe\x7f octeți" . $coada,
];
foreach ($fisierePoliglot as $rel => $continut) {
    @mkdir(dirname($uploadsWp . '/' . $rel), 0775, true);
    file_put_contents($uploadsWp . '/' . $rel, $continut);
}
$listaPoliglot = Media::lista($i, $uploadsWp, array_keys($fisierePoliglot));
$saritePoliglot = implode(' | ', $listaPoliglot['sarite']);
$verifica('M1b: PHP închis cu `?>` (cu octet non-ASCII) sau cu __halt_compiler() înaintea datelor binare se refuză (PHP tot îl rulează)',
    str_contains($saritePoliglot, '2024/08/halt.png (conține cod PHP după „<?” (compilează ca PHP))')
    && str_contains($saritePoliglot, '2024/08/comentariu-utf8.png (conține cod PHP după „<?” (compilează ca PHP))')
    && str_contains($saritePoliglot, '2024/08/sir-neascii.png (conține cod PHP după „<?” (compilează ca PHP))'), $saritePoliglot);
$verifica('M1b: o poză cu `<?=`/`<? ` urmate de octeți la întâmplare (pe care PHP nu-i compilează) trece',
    isset($listaPoliglot['fisiere']['2024/08/intamplatoare.png']), $saritePoliglot);
// M1c: codul pornește dincolo de fereastra de 64 KB (padding: spații, un comentariu lung, un șir lung) — fereastra se mărește
// și prinde codul; un short tag lipit (`<?echo`, fără spațiu) e și el prins; `<?xml` dintr-un SVG nu compilează, deci trece.
@mkdir($uploadsWp . '/2024/09', 0770, true);
$pad = str_repeat(' ', 70000);
$fisiereM1c = [
    '2024/09/pad-spatiu.png' => $png . '<?=' . $pad . 'system($_GET[0]); __halt_compiler();' . "\x00\xff",
    '2024/09/pad-comentariu.png' => $png . '<?= /*' . str_repeat('x', 70000) . '*/ `id`; __halt_compiler();' . "\x00\xff",
    '2024/09/pad-sir.jpg' => "\xFF\xD8\xFF\xE0" . '<?= "' . str_repeat('A', 70000) . '" . `id`; __halt_compiler();' . "\x00\xff",
    '2024/09/scurt-lipit.png' => $png . '<?echo `id`; __halt_compiler();' . "\x00\xff",
    '2024/09/xml-svg.svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
    '2024/09/intamplator.png' => $png . '<?' . random_bytes(2000),
];
foreach ($fisiereM1c as $rel => $continut) {
    file_put_contents($uploadsWp . '/' . $rel, $continut);
}
$listaM1c = Media::lista($i, $uploadsWp, array_keys($fisiereM1c));
$verifica('M1c: poligloții cu cod dincolo de fereastra de 64 KB (spații, comentariu, șir) și short tag-ul lipit (`<?echo`) se refuză; '
    . '`<?xml` dintr-un SVG și octeții la întâmplare după `<?` trec',
    !isset($listaM1c['fisiere']['2024/09/pad-spatiu.png']) && !isset($listaM1c['fisiere']['2024/09/pad-comentariu.png'])
    && !isset($listaM1c['fisiere']['2024/09/pad-sir.jpg']) && !isset($listaM1c['fisiere']['2024/09/scurt-lipit.png'])
    && isset($listaM1c['fisiere']['2024/09/xml-svg.svg']) && isset($listaM1c['fisiere']['2024/09/intamplator.png']),
    implode(' | ', $listaM1c['sarite']));
foreach (array_keys($fisiereM1c) as $rel) {
    @unlink($uploadsWp . '/' . $rel);
}
foreach (array_keys($fisierePoliglot) as $rel) {
    unlink($uploadsWp . '/' . $rel);
}
// F5: un fișier plin de „<?” (fiecare ar cere o compilare; fără buget timpul creștea cu fiecare: 8 MiB = 53 s) se refuză după
// bugetul de sonde, fail-closed și repede; o poză cu câteva sute de „<?” urmate de octeți pe care PHP nu-i compilează, sub buget,
// trece.
@mkdir($uploadsWp . '/2024/10', 0770, true);
$fisiereBuget = [
    '2024/10/bomba.jpg' => "\xFF\xD8\xFF\xE0" . str_repeat('<?', 1 << 20),
    '2024/10/sub-buget.jpg' => "\xFF\xD8\xFF\xE0" . str_repeat("\x00<?\x93\x00\xd1", 300) . $coada,
];
foreach ($fisiereBuget as $rel => $continut) {
    file_put_contents($uploadsWp . '/' . $rel, $continut);
}
$t0Buget = microtime(true);
$listaBuget = Media::lista($i, $uploadsWp, array_keys($fisiereBuget));
$durataBuget = microtime(true) - $t0Buget;
$sariteBuget = implode(' | ', $listaBuget['sarite']);
$verifica('F5: 2 MiB de „<?” se refuză după bugetul de sonde (fail-closed), în sub 5 s; o poză cu 300 de „<?” la întâmplare, sub buget, trece',
    str_contains($sariteBuget, '2024/10/bomba.jpg (prea multe „<?” de verificat') && isset($listaBuget['fisiere']['2024/10/sub-buget.jpg'])
    && $durataBuget < 5.0, $sariteBuget . ' / ' . round($durataBuget, 2) . ' s');
foreach (array_keys($fisiereBuget) as $rel) {
    @unlink($uploadsWp . '/' . $rel);
}
// Peste 200 MB (un fișier rar, fără octeți pe disc): sărit; exact 200 MB trece.
$mare = $uploadsWp . '/2024/07/mare.mp4';
$exact = $uploadsWp . '/2024/07/exact.mp4';
foreach ([$mare => Media::MARIME_MAXIMA + 1, $exact => Media::MARIME_MAXIMA] as $cale => $marime) {
    $h = fopen($cale, 'wb');
    ftruncate($h, $marime);
    fclose($h);
}
$listaMare = Media::lista($i, $uploadsWp, ['2024/07/mare.mp4', '2024/07/exact.mp4']);
$verifica('un fișier peste 200 MB (200 MiB + 1 octet) e sărit, cu mărimea în motiv; unul de exact 200 MiB trece',
    str_contains(implode(' | ', $listaMare['sarite']), '2024/07/mare.mp4 (peste 200 MB') && isset($listaMare['fisiere']['2024/07/exact.mp4'])
    && !isset($listaMare['fisiere']['2024/07/mare.mp4']), implode(' | ', $listaMare['sarite']));
unlink($mare);
unlink($exact);
// Un dosar înlocuit cu o legătură spre afara uploads ÎNTRE plan și copiere: copiaza() verifică iar sursa și n-o urmează.
@mkdir($uploadsWp . '/2024/cursa', 0775, true);
file_put_contents($uploadsWp . '/2024/cursa/poza.jpg', "\xFF\xD8\xFF poza din uploads");
@mkdir($lucru . '/in-afara-cursa', 0775, true);
file_put_contents($lucru . '/in-afara-cursa/poza.jpg', "\xFF\xD8\xFF SECRET din afara uploads");
$listaCursa = Media::lista($i, $uploadsWp, ['2024/cursa/poza.jpg']);
rename($uploadsWp . '/2024/cursa', $lucru . '/cursa-mutata');
symlink($lucru . '/in-afara-cursa', $uploadsWp . '/2024/cursa');
$tintaCursa = $lucru . '/copie-cursa/wp-content/uploads';
$cCursa = Media::copiaza(['2024/cursa/poza.jpg' => $listaCursa['fisiere']['2024/cursa/poza.jpg'] ?? []], $tintaCursa);
$verifica('copiaza(): un dosar devenit legătură după plan e sărit (nicio copie a fișierului din afara uploads)',
    isset($listaCursa['fisiere']['2024/cursa/poza.jpg']) && $cCursa['copiate'] === 0 && !is_file($tintaCursa . '/2024/cursa/poza.jpg')
    && str_contains(implode(' | ', $cCursa['sarite']), '2024/cursa/poza.jpg (legătură simbolică apărută între plan și copiere)'),
    json_encode($cCursa['sarite'], JSON_UNESCAPED_UNICODE));
unlink($uploadsWp . '/2024/cursa');
rename($lucru . '/cursa-mutata', $uploadsWp . '/2024/cursa');
$fara = ['2024/cursa/poza.jpg' => $listaCursa['fisiere']['2024/cursa/poza.jpg'] ?? []];
unset($fara['2024/cursa/poza.jpg']['radacina']);
$cFara = Media::copiaza($fara, $tintaCursa);
$verifica('copiaza(): o intrare fără dosarul ei de uploads (nu din lista()) nu se copiază', $cFara['copiate'] === 0
    && str_contains(implode(' | ', $cFara['sarite']), 'sursa nu vine din lista dosarului uploads'), json_encode($cFara['sarite'], JSON_UNESCAPED_UNICODE));
exec('rm -rf ' . escapeshellarg($uploadsWp . '/2024/cursa'));
$verifica('Paths::public(Media::DOSAR_DOCROOT) = docroot-ul dat lui bin/platforma (--public) + wp-content/uploads',
    Paths::public(Media::DOSAR_DOCROOT) === $lucru . '/public/wp-content/uploads');

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.3 Slug-urile: post_name păstrat exact (decodat în pages.slug, percent-encoded în adrese)');
$ro = $pagina($necapturat['plan'], 'Camere și prețuri speciale');
$el = $pagina($necapturat['plan'], 'Δωμάτια');
$verifica('slug-ul cu diacritice: „camere-și-prețuri” (decodat, fără transliterare)', ($ro['slug'] ?? '') === 'camere-și-prețuri', (string) ($ro['slug'] ?? '?'));
$verifica('adresa lui: /camere-%c8%99i-pre%c8%9buri/ (forma WordPress)', ($ro['cale'] ?? '') === '/camere-%c8%99i-pre%c8%9buri/', (string) ($ro['cale'] ?? '?'));
$verifica('slug-ul grecesc păstrat (Slug::make l-ar fi golit): „δωμάτια”, /%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/',
    ($el['slug'] ?? '') === 'δωμάτια' && ($el['cale'] ?? '') === '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/', json_encode($el, JSON_UNESCAPED_UNICODE));
$fara301 = array_filter($necapturat['plan']->redirecturi, static fn (array $r): bool => in_array(Adrese::cheie($r['din']), ['/camere-și-prețuri', '/δωμάτια', '/camere'], true));
$verifica('nicio redirecționare pentru o pagină cu aceeași adresă', $fara301 === [], json_encode(array_values($fara301), JSON_UNESCAPED_UNICODE));
$despre = $pagina($necapturat['plan'], 'Despre noi');
$verifica('regula „slug” a pachetului (singurul loc cu Slug::make): /despre-noi-aa/, cu 301 de la /despre-noi/', ($despre['slug'] ?? '') === 'despre-noi-aa'
    && ($despre['cale'] ?? '') === '/despre-noi-aa/'
    && array_filter($necapturat['plan']->redirecturi, static fn (array $r): bool => $r['din'] === '/despre-noi' && $r['spre'] === '/despre-noi-aa/') !== []);
$echipa = $pagina($necapturat['plan'], 'Echipa');
$verifica('pagina copil: /despre-noi/echipa/', ($echipa['cale'] ?? '') === '/despre-noi/echipa/' && ($echipa['slug'] ?? '') === 'despre-noi/echipa');

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.4 Resturile WordPress: 410 doar pe adresele tehnice, nu pe arborii copiați și nu pe endpointurile compatibile');
$reguli410 = array_values(array_filter($necapturat['plan']->redirecturi, static fn (array $r): bool => $r['cod'] === 410 && str_starts_with($r['nota'], 'rest tehnic')));
$cai410 = array_column($reguli410, 'din');
$verifica('nicio regulă pe /wp-content/, /wp-includes/ (fișierele lor le servește serverul web; lipsă = 404, ca live)',
    array_filter($cai410, static fn (string $c): bool => str_starts_with($c, '/wp-content') || str_starts_with($c, '/wp-includes')) === [], implode(', ', $cai410));
$verifica('410 pe /wp-admin/…, /wp-login.php, /xmlrpc.php, /readme.html', array_diff(['/wp-admin/', '/wp-login.php', '/xmlrpc.php', '/readme.html'], $cai410) === [],
    implode(', ', $cai410));
$verifica('cu Complianz activ: /wp-json/… nu intră în hartă (sub el e managerul consimțământului), cu ATENȚIE',
    !in_array('/wp-json/', $cai410, true) && array_filter($necapturat['plan']->avertismente, static fn (string $t): bool => str_contains($t, '/wp-json/')
        && str_contains($t, 'manage_consent_html')) !== []);
$iFaraComplianz = clone $i;
$iFaraComplianz->pluginuri = array_values(array_diff($i->pluginuri, ['complianz-gdpr']));
$verifica('fără Complianz: /wp-json/… primește 410 în hartă', in_array('/wp-json/', array_column(array_filter(
    Plan::fa($iFaraComplianz, $necapturat['context'])->redirecturi, static fn (array $r): bool => $r['cod'] === 410), 'din'), true));
$iDge = clone $i;
$iDge->pluginuri = array_merge($i->pluginuri, ['divi-gallery-extended']);
$planDge = Plan::fa($iDge, $necapturat['context']);
$verifica('cu galeria DGE: POST /wp-admin/admin-ajax.php (dge_get_images) e compatibil; 410 pe /wp-admin/ rămâne (harta e doar pe GET)',
    in_array('/wp-admin/', array_column($planDge->redirecturi, 'din'), true)
    && array_filter($planDge->info, static fn (string $t): bool => str_contains($t, 'POST /wp-admin/admin-ajax.php (acțiunea dge_get_images)')) !== []);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.5 SEO pe drumul capturat: valorile AIOSEO exacte, cu canonicalul');
$camereC = $pagina($capturat['plan'], 'Camere & prețuri');
$camereN = $pagina($necapturat['plan'], 'Camere & prețuri');
$verifica('capturat: canonicalul = adresa absolută a paginii (cum o scrie AIOSEO)', ($camereC['seo']['canonical'] ?? '') === WP_PROBA_URL . '/camere/',
    (string) ($camereC['seo']['canonical'] ?? '?'));
$verifica('capturat: titlul și descrierea AIOSEO exacte', ($camereC['seo']['titlu'] ?? '') === 'Camere & prețuri - ' . WP_PROBA_SITE
    && ($camereC['seo']['descriere'] ?? '') === 'Camerele pensiunii: duble și triple.');
$verifica('capturat: poza de distribuire la adresa ei absolută din uploads', ($camereC['seo']['imagine'] ?? '') === WP_PROBA_URL . '/wp-content/uploads/2024/05/camera-dubla.jpg',
    (string) ($camereC['seo']['imagine'] ?? '?'));
$verifica('necapturat: canonicalul spre aceeași pagină nu se păstrează (îl face nucleul), ca înainte', ($camereN['seo']['canonical'] ?? 'x') === '');

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.6 Antetul și subsolul pe drumul capturat: fără design_*, meniul doar ca date');
$verifica('capturat: niciun design_* în plan, deși dialectul ar avea unul', $capturat['plan']->design === []);
$verifica('capturat: meniul WordPress rămâne ca date în plan', $capturat['plan']->meniu !== []);
$verifica('necapturat: meniul și designul ca înainte', $necapturat['plan']->meniu !== [] && $necapturat['plan']->capturat === false);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.7 Setările pe drumul capturat: doar ID-urile trackerelor, fără fanioanele de pornire; bannerul nucleului oprit');
$sc = $capturat['plan']->setari;
$sn = $necapturat['plan']->setari;
$verifica('capturat: ID-urile (GA, Clarity) fără *_enabled', ($sc['google_analytics_id'] ?? '') === 'G-PROBA123' && ($sc['microsoft_clarity_project_id'] ?? '') === 'abcd1234ef'
    && !isset($sc['google_analytics_enabled']) && !isset($sc['microsoft_clarity_enabled']) && !isset($sc['google_tag_manager_enabled']));
$verifica('capturat: cookie_banner_enabled = 0', ($sc['cookie_banner_enabled'] ?? null) === '0');
$verifica('necapturat: ca înainte (fanioanele pornite, bannerul nucleului neatins)', ($sn['google_analytics_enabled'] ?? '') === '1'
    && ($sn['microsoft_clarity_enabled'] ?? '') === '1' && !isset($sn['cookie_banner_enabled']));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.8 Inventarul: pluginurile descrise neutru și ce se citește în plus');
$complianz = Inventar::PLUGINURI['complianz-gdpr']['cum'];
$verifica('Complianz: „capturat” (nu „bannerul nucleului (CookieConsent)”), cu cookie-urile cmplz_*', str_contains($complianz, 'capturat')
    && str_contains($complianz, 'cmplz_') && !str_contains($complianz, 'CookieConsent'));
$verifica('DiviMenus, dg-carousel, DGE: „capturat”; nicio descriere nu numește un runtime', str_starts_with(Inventar::PLUGINURI['divimenus']['cum'], 'capturat')
    && str_starts_with(Inventar::PLUGINURI['dg-carousel']['cum'], 'capturat') && str_starts_with(Inventar::PLUGINURI['divi-gallery-extended']['cum'], 'capturat')
    && array_filter(Inventar::PLUGINURI, static fn (array $p): bool => stripos($p['cum'], 'runtime') !== false) === []);
$verifica('Site Kit, Clarity, GTM: ID-urile, pornite doar după acord', str_contains(Inventar::PLUGINURI['google-site-kit']['cum'], 'după acord')
    && str_contains(Inventar::PLUGINURI['microsoft-clarity']['cum'], 'după acord') && str_contains(Inventar::PLUGINURI['duracelltomi-google-tag-manager']['cum'], 'după acord'));
$verifica('CF7 și Polylang la pasul 17; Flatsome între teme, la pasul 17', str_contains(Inventar::PLUGINURI['contact-form-7']['cum'], 'pasul 17')
    && str_contains(Inventar::PLUGINURI['polylang']['cum'], 'pasul 17') && str_contains(Inventar::TEME['flatsome'], 'pasul 17'));
$tb = $i->themeBuilder;
$sablon404 = $tb['sabloane'][$ids['tb_sablon404']] ?? [];
$verifica('Theme Builder: 2 șabloane (implicitul cu antetul și subsolul), 3 layout-uri, lista din et_theme_builder', count($tb['sabloane']) === 2
    && count($tb['layouturi']) === 3 && ($tb['sabloane'][$ids['tb_sablon']]['implicit'] ?? false) === true
    && ($tb['sabloane'][$ids['tb_sablon']]['antet']['layout'] ?? 0) === $ids['tb_antet']
    && ($tb['constructor'][$ids['tb']]['sabloane'] ?? []) === [$ids['tb_sablon'], $ids['tb_sablon404']]);
$verifica('_et_use_on și _et_exclude_from (cu mai multe valori)', ($sablon404['foloseste'] ?? []) === ['404']
    && ($sablon404['exclude'] ?? []) === ['singular:post_type:page:id:' . $ids['contact'], 'singular:post_type:post:all']
    && ($sablon404['corp']['layout'] ?? 0) === $ids['tb_corp404']);
$formular = $i->formulareDivi[0] ?? [];
$verifica('modulul et_pb_contact_form: destinatarul, titlul, mesajul, captcha, redirectul', count($i->formulareDivi) === 1
    && ($formular['postare'] ?? 0) === $ids['tb_subsol'] && ($formular['atribute']['email'] ?? '') === 'rezervari@example.test'
    && ($formular['atribute']['success_message'] ?? '') === 'Mulțumim, revenim curând.' && ($formular['atribute']['captcha'] ?? '') === 'on'
    && ($formular['atribute']['use_redirect'] ?? '') === 'off' && !isset($formular['atribute']['_builder_version']), json_encode($formular, JSON_UNESCAPED_UNICODE));
$verifica('Divi Dynamic Content: anul curent în subsol', ($i->continutDinamic[0]['continut'] ?? '') === 'current_date'
    && ($i->continutDinamic[0]['setari']['custom_date_format'] ?? '') === 'Y' && ($i->continutDinamic[0]['postare'] ?? 0) === $ids['tb_subsol']);
// P1 (Shortcoduri::atribute, scaner propriu în locul regexului lui shortcode_parse_atts): nume=valoare cu ghilimele duble,
// simple și fără, valori poziționale (cu ghilimele sau un cuvânt), numele în litere mici, numerotarea celor poziționale,
// stripcslashes, o valoare pozițională goală ignorată, NBSP ca spațiu. Cazuri-limită inventate.
$scAtr = [
    'email="x@example.test" captcha=on use_redirect=off' => ['email' => 'x@example.test', 'captcha' => 'on', 'use_redirect' => 'off'],
    'a=\'1\' B=2 pozitional "cu spatiu"' => ['a' => '1', 'b' => '2', '0' => 'pozitional', '1' => 'cu spatiu'],
    'gol="" apoi=da' => ['gol' => '', 'apoi' => 'da'],
    'name = "val cu = si spatii"' => ['name' => 'val cu = si spatii'],
    'msg="linie\\nou"' => ['msg' => "linie\nou"], // stripcslashes: „\n” devine linie nouă
    'dup=1 dup=2' => ['dup' => '2'], // numele duplicat: primul din regex câștigă (ultimul scris)
    '"" doar-pozitional' => ['0' => 'doar-pozitional'], // o valoare pozițională goală se ignoră
    "nbsp\u{00a0}sep=\"ok\"" => ['0' => 'nbsp', 'sep' => 'ok'], // NBSP devine spațiu, apoi „nbsp” e pozițional
];
$scGresite = [];
foreach ($scAtr as $intrare => $asteptat) {
    if (Shortcoduri::atribute((string) $intrare) !== $asteptat) {
        $scGresite[] = $intrare . ' → ' . json_encode(Shortcoduri::atribute((string) $intrare), JSON_UNESCAPED_UNICODE);
    }
}
$verifica('Shortcoduri::atribute (' . count($scAtr) . ' cazuri): nume=valoare (ghilimele duble/simple/fără), poziționale numerotate, '
    . 'litere mici, stripcslashes, valoare pozițională goală ignorată, NBSP ca spațiu', $scGresite === [], implode('; ', $scGresite));
$verifica('categoriile mediilor (o taxonomie din afara nucleului, legată de atașamente)', count($i->categoriiMedia['categorie_media_proba'] ?? []) === 2
    && in_array($ids['poza_camera'], array_merge(...array_column($i->categoriiMedia['categorie_media_proba'] ?? [['atasamente' => []]], 'atasamente')), true));
$verifica('proiectele Divi (project, project_category), CF7, Polylang (2 limbi, o traducere)', count($i->proiecte['proiecte']) === 1
    && count($i->proiecte['categorii']) === 1 && count($i->formulareCf7) === 1 && count($i->polylang['limbi']) === 2
    && ($i->polylang['traduceri'][0] ?? []) === ['ro' => $ids['acasa'], 'en' => $ids['contact']]
    && in_array('ro_RO', array_column($i->polylang['limbi'], 'locale'), true));
$verifica('autorul: doar id-ul și user_nicename (fără adresa de email)', $i->autori === [1 => ['id' => 1, 'slug' => 'autorul-probei']]);
$harta = [];
foreach ($i->harta as $r) {
    $harta[$r['tip'] . ':' . $r['wp_id']] = $r;
}
$asteptate = [
    'page:' . $ids['acasa'] => '/', 'page:' . $ids['camere'] => '/camere/', 'page:' . $ids['echipa'] => '/despre-noi/echipa/',
    'page:' . $ids['unicode_el'] => '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/', 'post:' . $ids['hello'] => '/hello-world/',
    'project:' . $ids['proiect'] => '/project/drumetie-pe-munte/', 'attachment:' . $ids['atasament_harta'] => '/harta-proba/',
    'attachment:' . $ids['atasament_scara'] => '/camere/scara-proba/', 'author:1' => '/author/autorul-probei/',
];
foreach ($i->categorii as $idCategorie => $categorie) {
    if ($categorie['slug'] === 'uncategorized') {
        $asteptate['category:' . $idCategorie] = '/category/uncategorized/';
    }
}
$gresite = [];
foreach ($asteptate as $k => $cale) {
    if (($harta[$k]['cale'] ?? null) !== $cale) {
        $gresite[] = $k . ' → ' . ($harta[$k]['cale'] ?? 'lipsă') . ' (aștept ' . $cale . ')';
    }
}
$verifica('harta WordPress: adresele paginilor, articolelor, proiectelor, atașamentelor, categoriilor și autorilor', $gresite === [], implode('; ', $gresite));
$verifica('harta: atașamentul cu fișierul lui; ciorna și pagina privată după stare; ordinea creării crescătoare',
    ($harta['attachment:' . $ids['poza_camera']]['fisier'] ?? '') === '2024/05/camera-dubla.jpg' && !isset($harta['page:' . $ids['ciorna']])
    && ($harta['page:' . $ids['privata']]['stare'] ?? '') === 'private' && array_column($i->harta, 'ordine') === range(1, count($i->harta)));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.9 Reimportul: amprentele pe pagină, pe rândul SEO și pe setare');
$planImport = $necapturat['plan'];
$verifica('planul necapturat al probei: fără NU', $planImport->probleme === [], implode(' | ', $planImport->probleme));
$rez1 = Importator::aplica($planImport, $db, true, false, $lucru . '/public/wp-content/uploads');
$amprente = Amprente::toate($db);
$idCamere = (int) (Importator::harta()['pagini'][(string) $ids['camere']] ?? 0);
$verifica('primul import: pagini scrise, cu amprente (pagina, seo, setare)', $rez1['pagini'] >= 6 && isset($amprente['pagina:' . $idCamere])
    && isset($amprente['seo:' . $idCamere]) && isset($amprente['setare:store_name']), json_encode(array_slice($amprente, 0, 3)));
$verifica('reimportul fără nicio editare: nicio piedică', Amprente::conflicte($db, $planImport, Importator::harta()) === []);
$db->prepare('UPDATE pages SET html_content = CONCAT(html_content, ?) WHERE id = ?')->execute(['<p>adăugat de om</p>', $idCamere]);
$db->prepare("UPDATE seo_pages SET description = 'Descriere scrisă de om' WHERE page_type = 'custom_page' AND page_ref = ?")->execute([(string) $idCamere]);
$db->prepare("UPDATE settings SET `value` = 'Numele schimbat de om' WHERE `key` = 'store_name'")->execute();
$conflicte = Amprente::conflicte($db, $planImport, Importator::harta());
$verifica('după editări: pagina, rândul SEO și setarea sunt în conflicte', array_column($conflicte, 'fel') === ['pagina', 'seo', 'setare'],
    json_encode($conflicte, JSON_UNESCAPED_UNICODE));
$refuzat = '';
try {
    Importator::aplica($planImport, $db, false);
} catch (EroareImport $e) {
    $refuzat = $e->getMessage();
}
$verifica('fără --suprascrie, importul e refuzat (nimic scris)', str_contains($refuzat, 'editate după importul de dinainte')
    && str_contains((string) $db->query('SELECT html_content FROM pages WHERE id = ' . $idCamere)->fetchColumn(), 'adăugat de om'), $refuzat);
$rez2 = Importator::aplica($planImport, $db, false, true);
$verifica('cu --suprascrie: scris peste, cu lista în rezultat', count($rez2['suprascrise']) === 3
    && !str_contains((string) $db->query('SELECT html_content FROM pages WHERE id = ' . $idCamere)->fetchColumn(), 'adăugat de om')
    && Amprente::conflicte($db, $planImport, Importator::harta()) === []);
$db->prepare("INSERT INTO seo_pages (page_type, page_ref, title) VALUES ('custom_page', ?, 'Scris de om') ON DUPLICATE KEY UPDATE title = VALUES(title)")
    ->execute([(string) (Importator::harta()['pagini'][(string) $ids['echipa']] ?? 0)]);
Amprente::sterge($db, 'seo', (string) (Importator::harta()['pagini'][(string) $ids['echipa']] ?? 0));
$verifica('un rând SEO fără amprentă (scris de om) pe o pagină importată: conflict (nu se șterge în tăcere)',
    array_column(Amprente::conflicte($db, $planImport, Importator::harta()), 'fel') === ['seo']);
[$codPlan, $outPlan] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan'], ['SITE_DATE_DOSAR' => $lucru . '/pachet']);
$verifica('planul scriptului listează rândul editat ca NU și iese cu 1', $codPlan === 1
    && preg_match('/^  NU +editat după importul de dinainte: SEO-ul paginii/mu', $outPlan) === 1, $outPlan);
// Ștergerile omului sunt tot editări: un rând SEO golit din panoul SEO (Seo::salveaza șterge rândul gol), o pagină mutată în
// coș; și o setare pe care importul de dinainte n-a scris-o, dar a pus-o omul. Plus o pagină din coș care nu e a importului.
Importator::aplica($planImport, $db, false, true);
$refuzA = static function (Plan $p) use ($db): string {
    try {
        Importator::aplica($p, $db, false);
    } catch (EroareImport $e) {
        return $e->getMessage();
    }
    return '';
};
$seoCamere = static fn (): array|false => $db->query("SELECT title, description FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = '" . $idCamere . "'")->fetch();
$seoInainte = $seoCamere();
\App\Support\Seo::salveaza($db, 'custom_page', (string) $idCamere, \App\Support\Seo::gol());
$conflicteGol = Amprente::conflicte($db, $planImport, Importator::harta());
$refuzGol = $refuzA($planImport);
$verifica('un rând SEO al importului golit din panoul SEO (rândul dispare): conflict, iar importul fără --suprascrie e refuzat (rândul rămâne gol)',
    is_array($seoInainte) && $seoCamere() === false && array_column($conflicteGol, 'fel') === ['seo'] && str_contains($conflicteGol[0]['motiv'] ?? '', 'golit după import')
    && str_contains($refuzGol, 'editate după importul de dinainte') && $seoCamere() === false, json_encode([$conflicteGol, $refuzGol], JSON_UNESCAPED_UNICODE));
Importator::aplica($planImport, $db, false, true);
$verifica('cu --suprascrie, rândul SEO golit se scrie la loc, cu valorile din WordPress', $seoCamere() === $seoInainte
    && Amprente::conflicte($db, $planImport, Importator::harta()) === []);
// R3: og_type și intro_html, pe care omul le pune în panoul SEO (importul nu le scrie), intră în amprenta SEO. Un om care le
// pune e un conflict, iar un reimport cu SEO gol NU mai poate șterge tot rândul (cu intro_html-ul omului) în tăcere.
\App\Support\Seo::salveaza($db, 'custom_page', (string) $idCamere, ['og_type' => 'article', 'intro_html' => '<p>Text scris de om în panoul SEO.</p>']);
$conflicteOg = Amprente::conflicte($db, $planImport, Importator::harta());
$refuzOg = $refuzA($planImport);
$planGolSeo = clone $planImport;
$planGolSeo->pagini = array_map(static function (array $pg) use ($ids): array {
    if ((int) $pg['wp_id'] === (int) $ids['camere']) {
        $pg['seo'] = [];
    }
    return $pg;
}, $planGolSeo->pagini);
$refuzGolOg = $refuzA($planGolSeo);
$introRamas = (string) $db->query("SELECT intro_html FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = '" . $idCamere . "'")->fetchColumn();
$verifica('og_type/intro_html puse de om intră în amprentă: conflict „seo”; un reimport (chiar cu SEO gol) e refuzat, iar rândul (cu intro_html-ul omului) nu se șterge',
    array_column($conflicteOg, 'fel') === ['seo'] && str_contains($refuzOg, 'editate după importul de dinainte')
    && str_contains($refuzGolOg, 'editate după importul de dinainte') && str_contains($introRamas, 'Text scris de om'),
    json_encode([$conflicteOg, $refuzOg, $refuzGolOg], JSON_UNESCAPED_UNICODE));
\App\Support\Seo::salveaza($db, 'custom_page', (string) $idCamere, ['og_type' => '', 'intro_html' => '']);
$verifica('după ce omul își scoate og_type/intro_html, rândul revine la starea importului (nicio piedică)',
    Amprente::conflicte($db, $planImport, Importator::harta()) === []);
$planB = clone $planImport;
$planB->setari['google_tag_manager_id'] = 'GTM-WPSITE';
$faraOm = Amprente::conflicte($db, $planB, Importator::harta());
$db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('google_tag_manager_id', 'GTM-OM') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute();
$cuOm = Amprente::conflicte($db, $planB, Importator::harta());
$db->prepare("UPDATE settings SET `value` = 'GTM-WPSITE' WHERE `key` = 'google_tag_manager_id'")->execute();
$laFel = Amprente::conflicte($db, $planB, Importator::harta());
$db->prepare("DELETE FROM settings WHERE `key` = 'google_tag_manager_id'")->execute();
$verifica('o setare pe care importul de dinainte n-a scris-o: implicită, nicio piedică; pusă de om, conflict; egală cu cea din plan, nicio piedică',
    $faraOm === [] && array_column($cuOm, 'cheie') === ['google_tag_manager_id'] && str_contains($cuOm[0]['motiv'] ?? '', 'pusă de om') && $laFel === [],
    json_encode([$faraOm, $cuOm, $laFel], JSON_UNESCAPED_UNICODE));
$db->prepare('UPDATE pages SET deleted_at = NOW() WHERE id = ?')->execute([$idCamere]);
$conflicteCos = Amprente::conflicte($db, $planImport, Importator::harta());
$refuzCos = $refuzA($planImport);
$verifica('o pagină a importului mutată în coș: conflict, iar importul fără --suprascrie e refuzat cu motivul, nu cu o eroare SQL',
    array_column($conflicteCos, 'fel') === ['pagina'] && str_contains($conflicteCos[0]['motiv'] ?? '', 'mutată în coș')
    && str_contains($refuzCos, 'mutată în coș') && !str_contains($refuzCos, 'SQLSTATE'), json_encode([$conflicteCos, $refuzCos], JSON_UNESCAPED_UNICODE));
$rezCos = Importator::aplica($planImport, $db, false, true);
$restaurata = $db->query('SELECT deleted_at, html_content FROM pages WHERE id = ' . $idCamere)->fetch();
$verifica('cu --suprascrie, pagina din coș se restaurează pe același id, cu conținutul din WordPress (fără dublură)',
    is_array($restaurata) && $restaurata['deleted_at'] === null && (int) (Importator::harta()['pagini'][(string) $ids['camere']] ?? 0) === $idCamere
    && (int) $db->query("SELECT COUNT(*) FROM pages WHERE slug = 'camere'")->fetchColumn() === 1 && $rezCos['pagini_noi'] === 0
    && Amprente::conflicte($db, $planImport, Importator::harta()) === []);
// O pagină din coș care nu e a importului, pe o adresă din plan (aici: harta importului a uitat pagina, iar ea a ajuns în coș):
// NU în planul scriptului și refuz cu motivul la import, nu o eroare SQL (unicitatea slug-ului cuprinde și coșul).
$fisierHarta = $lucru . '/site/storage/import-wordpress/harta.json';
$hartaSalvata = (string) file_get_contents($fisierHarta);
$hartaFara = json_decode($hartaSalvata, true);
unset($hartaFara['pagini'][(string) $ids['camere']]);
file_put_contents($fisierHarta, json_encode($hartaFara));
$db->prepare('UPDATE pages SET deleted_at = NOW() WHERE id = ?')->execute([$idCamere]);
$adreseCos = Importator::conflicteAdrese($db, $planImport, Importator::harta());
$refuzAdresa = $refuzA($planImport);
[$codCos, $outCos] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan'], ['SITE_DATE_DOSAR' => $lucru . '/pachet']);
$verifica('o pagină din coș care nu e a importului, pe o adresă din plan: NU în planul scriptului și refuz cu motivul la import (nu SQLSTATE)',
    $adreseCos === ['/camere (pagina ' . $idCamere . ', în coș: o ștergi definitiv din coș sau îi schimbi adresa)'] && str_contains($refuzAdresa, 'în coș')
    && !str_contains($refuzAdresa, 'SQLSTATE') && $codCos === 1 && preg_match('/^  NU +pe site există deja pagina de la adresa \/camere \(pagina \d+, în coș/mu', $outCos) === 1,
    json_encode([$adreseCos, $refuzAdresa], JSON_UNESCAPED_UNICODE) . ' / ' . $outCos);
file_put_contents($fisierHarta, $hartaSalvata);
$db->prepare('UPDATE pages SET deleted_at = NULL WHERE id = ?')->execute([$idCamere]);
// SPEC-R3-05: două slug-uri ale planului care diferă doar printr-un accent se bat cap în cap pe cheia UNIQUE a bazei
// (utf8mb4_unicode_ci), deși trec de verificarea PHP cu cheie exactă. coliziuniSlug le prinde (lasă MariaDB să decidă), cu
// ambele ID-uri WordPress și adrese, iar aplica() refuză curat, nu cu un „1062 Duplicate entry”. Fixture inventat.
$planAccent = new Plan();
$planAccent->pagini = [
    ['wp_id' => 7001, 'titlu' => 'Fără accent', 'slug' => 'zzαβγ', 'cale_veche' => '/a/', 'cale' => '/zz%ce%b1%ce%b2%ce%b3/',
        'publicata' => true, 'html' => '<p>a</p>', 'css' => '', 'js' => '', 'seo' => []],
    ['wp_id' => 7002, 'titlu' => 'Cu accent', 'slug' => 'zzάβγ', 'cale_veche' => '/b/', 'cale' => '/zz%ce%ac%ce%b2%ce%b3/',
        'publicata' => true, 'html' => '<p>b</p>', 'css' => '', 'js' => '', 'seo' => []],
];
$colAccent = Importator::coliziuniSlug($db, $planAccent);
$refuzColiziune = '';
try {
    Importator::aplica($planAccent, $db, false);
} catch (EroareImport $e) {
    $refuzColiziune = $e->getMessage();
}
$verifica('SPEC-R3-05: două slug-uri care diferă doar printr-un accent se bat cap în cap pe cheia unică (colația); coliziuniSlug le prinde cu ambele '
    . 'ID-uri WordPress, iar aplica() refuză curat (nu SQLSTATE 1062), fără pagini scrise',
    count($colAccent) === 1 && str_contains($colAccent[0], '7001') && str_contains($colAccent[0], '7002')
    && str_contains($refuzColiziune, 'cheia unică') && !str_contains($refuzColiziune, 'SQLSTATE') && !str_contains($refuzColiziune, '1062')
    && (int) $db->query("SELECT COUNT(*) FROM pages WHERE slug IN ('zzαβγ', 'zzάβγ')")->fetchColumn() === 0,
    json_encode([$colAccent, $refuzColiziune], JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.10 Galeria: adresele /wp-content/uploads/…');
$galerie = $db->query("SELECT image_url FROM gallery_images WHERE folder_id = (SELECT id FROM gallery_folders WHERE slug = 'wordpress')")->fetchAll(PDO::FETCH_COLUMN);
$verifica('fiecare poză din dosarul „WordPress” are adresa din uploads-ul WordPress', $galerie !== []
    && array_filter($galerie, static fn (string $u): bool => !str_starts_with($u, '/wp-content/uploads/')) === [], implode(', ', $galerie));
$verifica('și fișierul e chiar acolo, în docroot-ul nou, cu aceiași octeți', is_file($lucru . '/public' . rawurldecode($galerie[0] ?? '/x'))
    && hash_file('sha256', $lucru . '/public' . rawurldecode($galerie[0] ?? '/x')) === hash_file('sha256', $dosarWp . rawurldecode($galerie[0] ?? '/x')));
$manifest = json_decode((string) @file_get_contents($lucru . '/site/storage/import-wordpress/media.json'), true);
$verifica('manifestul mediilor (storage/import-wordpress/media.json): adresa, calea, sha256 de pe disc', is_array($manifest)
    && array_filter((array) ($manifest['fisiere'] ?? []), static fn (array $f): bool => $f['adresa'] === '/wp-content/uploads/2024/05/camera-dubla.jpg'
        && $f['sha256_disc'] === hash_file('sha256', $dosarWp . '/wp-content/uploads/2024/05/camera-dubla.jpg')) !== []);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.11 Fără variantele WebP ale nucleului pe mediile WordPress');
$adoptaWp = (string) file_get_contents($radacina . '/deploy/lib/adopta-wp.php');
$verifica('adopta-wp.php nu mai cheamă optimizeaza-imagini.php și nu mai pomenește uploads/wordpress',
    !str_contains($adoptaWp, "'scripts/optimizeaza-imagini.php'") && !str_contains($adoptaWp, '/uploads/wordpress'));
$verifica('WordPress::capturat() citește drumul din pachet', WordPress::capturat($lucru . '/pachet-capturat') && !WordPress::capturat($lucru . '/pachet'));
$verifica('.well-known/ (recenzia 2, R1-1): doar lista albă (fără extensie, .txt, .json, .xml); refuzate bucățile executabile oriunde în nume (a.php.txt), fișierele ascunse (.env), restul extensiilor (.html, .svg, .js, .cgi)',
    array_filter(['a.php', 'a.php.txt', 'a.PHP5.json', 'b.pl', 'c.py', 'd.cgi', 'e.sh', 'e.sh.xml', 'f.phtml', 'g.phar.txt', 'h.shtml', 'x.html', 'x.svg', 'x.xhtml',
        'x.js', '.env', 'x/.htaccess', '.user.ini', 'y/.ascuns/z.txt', 'a.php.zzz', 'b\\c.txt'], static fn (string $f): bool => WordPress::permisWellKnown($f)) === []
    && array_filter(['security.txt', 'assetlinks.json', 'apple-app-site-association', 'change-password', 'pki-validation/ABC.txt', 'x/php.txt', 'harta.xml'],
        static fn (string $f): bool => !WordPress::permisWellKnown($f)) === []
    && str_contains($adoptaWp, 'WordPress::permisWellKnown($rel)') && str_contains(WordPress::HTACCESS_WELL_KNOWN, 'nosniff')
    && str_contains(WordPress::HTACCESS_WELL_KNOWN, 'sandbox') && str_contains(WordPress::HTACCESS_WELL_KNOWN, '-ExecCGI'));
// Recenzia 3, R1-N3 și R1-N4: o legătură în .well-known e găsită (și refuzată înaintea mentenanței); protecția nu depinde de mod_headers.
$wkProba = $lucru . '/wk-proba';
exec('rm -rf ' . escapeshellarg($wkProba));
@mkdir($wkProba . '/.well-known/sub', 0770, true);
file_put_contents($wkProba . '/.well-known/sub/a.txt', 'x');
$wkFara = WordPress::legaturaWellKnown($wkProba . '/.well-known');
symlink('/etc', $wkProba . '/.well-known/sub/leg');
$wkCu = WordPress::legaturaWellKnown($wkProba . '/.well-known');
$htFaraHeaders = (string) preg_replace('/<IfModule mod_headers\.c>.*?<\/IfModule>\n/s', '', WordPress::HTACCESS_WELL_KNOWN);
$posMentenanta = strpos($adoptaWp, 'Mentenanta::pune($s, $jeton, true)');
$posLeg = strpos($adoptaWp, 'WordPress::legaturaWellKnown($s->docroot');
$verifica('.well-known: o legătură simbolică oriunde în el e găsită, verificată înaintea mentenanței; fără mod_headers rămân SetHandler none și ForceType text/plain',
    $wkFara === null && is_string($wkCu) && str_contains($wkCu, 'sub/leg') && $posLeg !== false && $posMentenanta !== false && $posLeg < $posMentenanta
    && str_contains($htFaraHeaders, 'SetHandler none') && str_contains($htFaraHeaders, 'ForceType text/plain') && !str_contains(substr($htFaraHeaders, 0, (int) strpos($htFaraHeaders, '<IfModule')), 'Header'),
    (string) $wkCu);
exec('rm -rf ' . escapeshellarg($wkProba));
// Recenzia 4 (N4): tipul fiecărui fișier după .htaccess, ca Apache: ForceType din dosar, apoi cel din <FilesMatch> pe numele de bază.
$tipWk = static function (string $rel): string {
    $tip = preg_match('/^ForceType (\S+)$/m', WordPress::HTACCESS_WELL_KNOWN, $m) === 1 ? $m[1] : '';
    preg_match_all('/<FilesMatch "([^"]+)">\s*ForceType (\S+)\s*<\/FilesMatch>/', WordPress::HTACCESS_WELL_KNOWN, $blocuri, PREG_SET_ORDER);
    foreach ($blocuri as [, $re, $t]) {
        $tip = preg_match('~' . $re . '~', basename($rel)) === 1 ? $t : $tip;
    }
    return $tip;
};
$tipuriWk = ['apple-app-site-association' => 'application/json', 'sub/apple-app-site-association' => 'application/json', 'assetlinks.json' => 'application/json',
    'X.JSON' => 'application/json', 'security.txt' => 'text/plain', 'change-password' => 'text/plain', 'apple-app-site-association.txt' => 'text/plain',
    'apple-app-site-association-vechi' => 'text/plain', 'x-apple-app-site-association' => 'text/plain', 'a.xml' => 'text/plain'];
$verifica('.well-known: apple-app-site-association (JSON fără extensie) și .json ca application/json; restul, și celelalte fără extensie, text/plain',
    array_map($tipWk, array_keys($tipuriWk)) === array_values($tipuriWk), (string) json_encode(array_map($tipWk, array_keys($tipuriWk))));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.12 .htaccess-urile arborilor copiați, din șabloanele din git');
foreach (WordPress::ARBORI as $arbore) {
    $text = WordPress::htaccess($radacina, $arbore);
    // Tiparele sunt clase de litere ([pP]…, nu (?i)): încercate pe nume de fișiere; test-captura.php le încearcă pe toate.
    $refuz = preg_match('#<FilesMatch "([^"]+)">\s*Require all denied#', $text, $mr) === 1 ? '#' . $mr[1] . '#' : '#(?!)#';
    $cors = preg_match('#<FilesMatch "([^"]+)">\s*Header set Access-Control-Allow-Origin "\*"#', $text, $mc) === 1 ? '#' . $mc[1] . '#' : '#(?!)#';
    $verifica($arbore . '.htaccess: fără execuție, fără liste, cache 7 zile cu Expires, CORS doar pe fonturi', str_contains($text, 'Options -ExecCGI -Indexes')
        && preg_match($refuz, 'a.php') === 1 && preg_match($refuz, 'a.PHP') === 1 && preg_match($refuz, 'a.phtml') === 1 && preg_match($refuz, 'a.png') === 0
        && str_contains($text, 'ExpiresDefault "access plus 7 days"') && str_contains($text, 'max-age=604800')
        && preg_match($cors, 'a.woff2') === 1 && preg_match($cors, 'a.TTF') === 1 && preg_match($cors, 'a.css') === 0
        && substr_count($text, 'Access-Control-Allow-Origin') === 1 && !str_contains($text, 'Redirect') && !str_contains($text, 'R=301'));
}
$docrootTest = $lucru . '/docroot-ht';
@mkdir($docrootTest . '/wp-includes', 0775, true);
$scrise = WordPress::scrieHtaccess($radacina, $docrootTest);
$verifica('scrieHtaccess(): wp-content mereu, wp-includes fiindcă există, capturat nu (lipsește)', $scrise === ['wp-content', 'wp-includes']
    && is_file($docrootTest . '/wp-content/.htaccess') && !is_dir($docrootTest . '/capturat'), implode(', ', $scrise));
$verifica('adopta-wp.php scrie .htaccess-urile din WordPress::scrieHtaccess (nu 301 spre /uploads/wordpress/)', str_contains($adoptaWp, 'WordPress::scrieHtaccess(')
    && !str_contains($adoptaWp, 'htaccessUploads'));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.13 și 1.14 adopta-wp.sh --plan: --admin-email obligatoriu; spațiul cu două copii și hPanel');
$verifica('WordPress::adminEmail(): fără opțiune, nimic (nu adresa din WordPress)', WordPress::adminEmail(null) === ''
    && WordPress::adminEmail('Proprietar@Example.test') === 'proprietar@example.test' && WordPress::adminEmail(null, 'tinut@example.test') === 'tinut@example.test');
$spatiu = WordPress::spatiu($dosarWp, 1000);
$verifica('WordPress::spatiu(): mediile și arborii statici de două ori, plus codul și baza', $spatiu['media'] > 0
    && $spatiu['doua_copii'] === 2 * ($spatiu['media'] + $spatiu['statice']) && $spatiu['nevoie'] > $spatiu['doua_copii'] + 1000);
file_put_contents($lucru . '/cron-gol.txt', '');
$adopta = static function (array $extra) use ($php, $lucru): array {
    return $php(array_merge(['deploy/lib/adopta-wp.php', '--site=proba', '--domeniu=proba.test', '--versiune=2026.11.17-abcdef0', '--acasa=' . $lucru . '/acasa',
        '--url=http://127.0.0.1:9', '--fara-sonda-web', '--cron-vechi=' . $lucru . '/cron-gol.txt', '--plan'], $extra), [], false);
};
[$codA, $outA, $errA] = $adopta([]);
$verifica('fără --admin-email: NU, adresa din WordPress nu e luată', $codA !== 0 && str_contains($outA . $errA, 'lipsește adresa primului administrator')
    && !str_contains($outA . $errA, 'agentia@example.test'), $outA . $errA);
[$codB, $outB, $errB] = $adopta(['--admin-email=proprietar@example.test']);
$verifica('cu --admin-email: rândul OK al administratorului', str_contains($outB, 'primul administrator al site-ului nou: proprietar@example.test'), $outB . $errB);
$verifica('spațiul: ATENȚIE cu mediile și arborii statici de două ori pe disc', preg_match('/ATENȚIE.*stau de două ori pe disc/u', $outB) === 1, $outB);
$verifica('spațiul: ATENȚIE „verifici în hPanel → Disk usage” (df nu arată cota)', preg_match('/ATENȚIE.*hPanel → Disk usage.*df nu arată cota/u', $outB) === 1, $outB);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1.15 Drumul capturat în scripts/importa-wordpress.php: --url, captura și dialectul obligatorii');
$verifica('valideazaReguli(): capturat cu divi4 trece; fără dialect sau cu altul pică', ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4']) === []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat']) !== [] && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'flatsome9']) !== []);
[$codC, $outC] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura'],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-capturat']);
$verifica('fără --url și fără captură: NU pentru fiecare, cod 1', $codC === 1 && str_contains($outC, 'drumul capturat cere --url=')
    && str_contains($outC, 'lipsește ' . $lucruCapturi . '/captura/manifest.json'), $outC);
$verifica('convertorul vine din dialect (Divi4, 5a): fără captură, NU pe fiecare pagină, nu convertorul simplu', str_contains($outC, 'lipsește captura site-ului (pasul 4')
    && str_contains($outC, '[dialectul divi4 (captura)]') && !str_contains($outC, '[WordPress (blocuri sau editorul clasic)]'), $outC);
@mkdir($lucruCapturi . '/captura', 0770, true);
file_put_contents($lucruCapturi . '/captura/manifest.json', '{"format": 1, "fisiere": []}');
[$codD, $outD] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-capturat']);
$verifica('cu --url și manifestul, dar fără indexul capturii: rămâne doar NU-ul dialectului', $codD === 1 && !str_contains($outD, 'drumul capturat cere')
    && str_contains($outD, 'lipsește captura site-ului (pasul 4'), $outD);
$verifica('pe drumul capturat, în proces, cu un dialect (inventat, doar al testului): convertorul lui', ($pagina($capturat['plan'], 'Contact')['convertor'] ?? '') === 'dialectul de probă');

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5a Dialectul divi4 pe o captură inventată: aruncatele în sha256, ax-date, handlerele, head-ul, politica, recaptura, NU-urile');
$lista5a = ListaAdrese::dinInventar($i, ['continut' => 'capturat', 'dialect' => 'divi4', 'pagini' => ['echipa' => ['importa' => false, 'spre' => '/']]], WP_PROBA_URL, 'x1');
$caiRamase = array_column(ListaAdrese::filtreaza($lista5a, ['pagina']), 'cale');
$verifica('lista adreselor din inventar: paginile publicate rămase (prima la /, fără cea scoasă, fără cea privată), 404, embed și oEmbed pentru fiecare',
    in_array('/', $caiRamase, true) && in_array('/camere/', $caiRamase, true) && !in_array('/despre-noi/echipa/', $caiRamase, true) && !in_array('/pagina-privata/', $caiRamase, true)
    && in_array('/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/', $caiRamase, true) && count(ListaAdrese::filtreaza($lista5a, ['embed'])) === count($caiRamase)
    && count(ListaAdrese::filtreaza($lista5a, ['oembed'])) === count($caiRamase) && ListaAdrese::filtreaza($lista5a, ['404'])[0]['cale'] === '/x1-inexistent/', implode(' ', $caiRamase));
// Captura de probă: toate paginile publicate (fără regula de scoatere de mai sus).
$caiPagini = array_column(ListaAdrese::filtreaza(ListaAdrese::dinInventar($i, ['continut' => 'capturat', 'dialect' => 'divi4'], WP_PROBA_URL), ['pagina']), 'cale');
/** O pagină Divi inventată (Pensiunea Probă), cu ce are de transformat dialectul; $nonce și $clasaUa se schimbă între capturi. */
$paginaDivi = static function (string $cale, string $titlu, string $nonce, string $clasaUa, string $inCorp = ''): string {
    $u = WP_PROBA_URL;
    $j = str_replace('/', '\/', $u);
    return "<!DOCTYPE html>\n<html lang=\"ro-RO\">\n<head>\n<meta charset=\"UTF-8\" />\n<script type=\"text/javascript\">\n\t\tdocument.documentElement.dataset.proba = 'da';\n\t</script>\n"
        . '<title>' . $titlu . " – Pensiunea Probă</title>\n<link rel=\"canonical\" href=\"{$u}{$cale}\" />\n<meta property=\"og:url\" content=\"{$u}{$cale}\" />\n"
        . "<script type=\"application/ld+json\" class=\"aioseo-schema\">{\"@context\":\"https:\\/\\/schema.org\",\"url\":\"{$j}\\/\"}</script>\n"
        . "<link rel=\"pingback\" href=\"{$u}/xmlrpc.php\" />\n<link rel='dns-prefetch' href='//fonts.googleapis.com' />\n"
        . "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin />\n"
        . "<link rel='stylesheet' id='proba-fonturi-css' href='https://fonts.googleapis.com/css?family=Proba+Sans:400,700&#038;display=swap' media='all' />\n"
        . "<link rel='stylesheet' id='proba-stil-css' href='{$u}/wp-content/themes/Divi/style.css?ver=9.9.9' media='all' />\n"
        . "<link rel='preload' id='proba-tarziu-css' href='{$u}/wp-content/et-cache/1/et-proba-late.css?ver=1' as='style' media='all' onload=\"this.onload=null;this.rel='stylesheet'\" />\n"
        . "<link rel=\"preload\" as=\"style\" id=\"proba-amanat\" href=\"{$u}/wp-content/et-cache/1/amanat.min.css\" onload=\"this.onload=null;this.rel='stylesheet';\" />\n"
        . "<script id=\"proba-biblioteca-js\" src=\"{$u}/wp-includes/js/proba/biblioteca.min.js?ver=1.0.0\"></script>\n"
        . "<script id=\"proba-stat-js\" src=\"https://www.googletagmanager.com/proba.js?id=G-PROBA123\" async></script>\n"
        . "<script id=\"proba-stat-js-after\">\nwindow.probaStat = window.probaStat || [];\nprobaStat.push([\"config\", \"G-PROBA123\"]);\n//# sourceURL=proba-stat-js-after\n</script>\n"
        . "<link rel=\"https://api.w.org/\" href=\"{$u}/wp-json/\" />\n<link rel=\"alternate\" title=\"JSON\" type=\"application/json\" href=\"{$u}/wp-json/wp/v2/pages/1\" />\n"
        . "<link rel='shortlink' href='{$u}/?p=1' />\n<style id=\"proba-inline-css\">.antet{background:url({$u}/wp-content/uploads/2024/05/curtea-mare-scaled.jpg)}</style>\n"
        . "<script type=\"application/javascript\">\n\t\t\t(function () {\n\t\t\t\tvar file = [\"{$j}\\/wp-content\\/et-cache\\/1\\/et-proba-late.css\"];\n\t\t\t\tvar proba = document.getElementById('proba');\n\t\t\t})();\n\t\t</script>\n"
        . "</head>\n<body class=\"page page-id-1 et_pb_gutter {$clasaUa} et_divi_theme\">\n"
        . "<noscript><iframe src=\"https://www.googletagmanager.com/proba.html?id=GTM-PROBA\" height=\"0\" width=\"0\" hidden></iframe></noscript>\n"
        . "<div id=\"page-container\"><div class=\"entry-content\"><div class=\"et_pb_section\"><p>{$titlu}: <a href=\"{$u}/contact/\">contact</a></p>"
        . "<img src=\"{$u}/wp-content/uploads/2024/05/camera-dubla-300x200.jpg\" srcset=\"{$u}/wp-content/uploads/2024/05/camera-dubla-300x200.jpg 300w\" alt=\"\">{$inCorp}"
        . "<form class=\"et_pb_contact_form\" method=\"post\" action=\"{$u}{$cale}\"><input type=\"hidden\" id=\"_wpnonce-et-pb-contact-form-submitted-0\" "
        . "name=\"_wpnonce-et-pb-contact-form-submitted-0\" value=\"{$nonce}\" /></form></div></div></div>\n"
        . "<script type=\"speculationrules\">{\"prefetch\":[{\"source\":\"document\",\"where\":{\"href_matches\":\"/*\"},\"eagerness\":\"conservative\"}]}</script>\n"
        . "<script type=\"text/javascript\">\n\t\t\t\tvar et_link_options_data = [{\"class\":\"et_pb_text_0_tb_footer\",\"url\":\"{$j}\\/contact\\/\",\"target\":\"_self\"},{\"class\":\"et_pb_text_2\",\"url\":\"{$j}\\/\",\"target\":\"_self\"}];\n\t\t\t</script>\n"
        . "<script id=\"proba-tema-js-extra\">\nvar DIVI = {\"item_count\":\"%d bucăți\"};\nvar et_pb_custom = {\"ajaxurl\":\"{$j}\\/wp-admin\\/admin-ajax.php\",\"et_frontend_nonce\":\"{$nonce}\",\"page_id\":\"1\"};\nvar et_pb_box_shadow_elements = [];\n//# sourceURL=proba-tema-js-extra\n</script>\n"
        . "<script id=\"proba-tema-js\" src=\"{$u}/wp-content/themes/Divi/js/proba.min.js?ver=9.9.9\"></script>\n"
        . "<script id=\"proba-lipicios-js-extra\">\nvar et_pb_sticky_elements = {\"et_pb_row_0_tb_header\":{\"id\":\"et_pb_row_0_tb_header\"},\"et_pb_row_1\":{\"id\":\"et_pb_row_1\"}};\n//# sourceURL=proba-lipicios-js-extra\n</script>\n"
        . "\t<span class=\"et_pb_scroll_top et-pb-icon\"></span>\n</body>\n</html>\n";
};
/** O captură în forma noastră (index.json + brut/), cu câte o pagină pe fiecare cale. */
$scrieCaptura = static function (string $dosar, string $nonce, string $clasaUa, array $inCorp = []) use ($paginaDivi, $caiPagini): void {
    exec('rm -rf ' . escapeshellarg($dosar));
    $adrese = [];
    foreach ($caiPagini as $cale) {
        $url = WP_PROBA_URL . $cale;
        $sha = hash('sha256', $url);
        @mkdir($dosar . '/brut/' . $sha, 0770, true);
        file_put_contents($dosar . '/brut/' . $sha . '/chrome-win-1.corp', $paginaDivi($cale, 'Pagina ' . trim(rawurldecode($cale), '/'), $nonce, $clasaUa, $inCorp[$cale] ?? ''));
        file_put_contents($dosar . '/brut/' . $sha . '/chrome-win-1.antete', "content-type: text/html; charset=UTF-8\r\n");
        $adrese[] = ['fel' => 'pagina', 'cale' => $cale, 'url' => $url, 'referinta' => 'brut/' . $sha . '/chrome-win-1.corp', 'status' => 200];
    }
    file_put_contents($dosar . '/index.json', json_encode(['format' => 1, 'url' => WP_PROBA_URL, 'adrese' => $adrese]));
    file_put_contents($dosar . '/manifest.json', json_encode(['format' => 1, 'pagini' => [], 'fisiere' => []]));
    sigileazaCapturaProba($dosar, WP_PROBA_URL);
};
$scrieCaptura($lucruCapturi . '/captura-a', 'aaaa111111', 'windows');
$divi4 = new Divi4(DosarCapturi::deschide($lucruCapturi . '/captura-a'));
$necunoscuteA = [];
foreach ($caiPagini as $cale) {
    foreach ($divi4->transforma((string) DosarCapturi::deschide($lucruCapturi . '/captura-a')->dupaCale($cale)?->corp, WP_PROBA_URL, [])->necunoscute as $x) {
        if ($x['fel'] !== 'noscript' || str_contains($x['inceput'], 'googletagmanager')) {
            $necunoscuteA[$x['sha256']] = $x['id'] ?? $x['inceput'];
        }
    }
}
$verifica('fără listă: scripturile furnizorilor și ale trackerelor sunt necunoscute (NU), aceleași pe toate paginile', count($necunoscuteA) === 7, json_encode($necunoscuteA, JSON_UNESCAPED_UNICODE));
$reguli5a = ['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => array_map(static fn (string $sha, string $id): array => ['sha256' => $sha,
    'motiv' => 'proba: ' . $id], array_keys($necunoscuteA), $necunoscuteA)];
$verifica('regulile cu lista aruncatelor trec de valideazaReguli()', ImportWordPress::valideazaReguli($reguli5a) === [], implode('; ', ImportWordPress::valideazaReguli($reguli5a)));
// Un pachet de probă ca unul real (recenzia 2, R3-7): în import-wordpress.json public doar configurația generică; aruncatele și
// nonce-urile în privat/import-wordpress.json, structura în privat/captura-structura.json.
$scriePachet = static function (string $dosar, array $reguli): void {
    @mkdir($dosar . '/privat', 0770, true);
    $privat = [];
    if (array_key_exists('scripturi_aruncate', $reguli)) {
        $privat['scripturi_aruncate'] = $reguli['scripturi_aruncate'];
        unset($reguli['scripturi_aruncate']);
    }
    if (isset($reguli['captura']['nonce'])) {
        $privat['captura'] = ['nonce' => $reguli['captura']['nonce']];
        unset($reguli['captura']['nonce']);
    }
    $structura = $reguli['captura']['structura'] ?? null;
    unset($reguli['captura']['structura']);
    if (($reguli['captura'] ?? null) === []) {
        unset($reguli['captura']);
    }
    file_put_contents($dosar . '/import-wordpress.json', (string) json_encode($reguli, JSON_UNESCAPED_UNICODE));
    $privat === [] ? @unlink($dosar . '/privat/import-wordpress.json') : file_put_contents($dosar . '/privat/import-wordpress.json', (string) json_encode($privat, JSON_UNESCAPED_UNICODE));
    $structura === null ? @unlink($dosar . '/privat/captura-structura.json') : file_put_contents($dosar . '/privat/captura-structura.json', (string) json_encode($structura, JSON_UNESCAPED_UNICODE));
};
@mkdir($lucru . '/pachet-5a', 0770, true);
$scriePachet($lucru . '/pachet-5a', $reguli5a);
[$codE, $outE] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('planul pe drumul capturat: fiecare pagină transformată de dialectul real, acceptată de politică, niciun NU', $codE === 0
    && substr_count($outE, '[dialectul divi4 (captura)]') === count($caiPagini) && !str_contains($outE, '  NU'), $outE);
// Recenzia 2, R1-2 și R3-5: o captură care a ieșit cu NU (probleme în raport; o adresă neidentică) sau una din repo nu se importă.
$refuzuri = [];
foreach (['probleme' => static function (string $d): void {
    $r = json_decode((string) file_get_contents($d . '/raport-captura.json'), true);
    $r['probleme'] = ['diferă după UA'];
    file_put_contents($d . '/raport-captura.json', json_encode($r));
}, 'neidentice' => static function (string $d): void {
    $i = json_decode((string) file_get_contents($d . '/index.json'), true);
    $i['adrese'][0]['identice'] = false;
    file_put_contents($d . '/index.json', json_encode($i));
    sigileazaCapturaProba($d, WP_PROBA_URL);
}, 'lant' => static function (string $d): void {
    file_put_contents($d . '/index.json', (string) file_get_contents($d . '/index.json') . ' ');
}] as $caz => $strica) {
    exec('rm -rf ' . escapeshellarg($lucruCapturi . '/captura-nu') . ' && cp -r ' . escapeshellarg($lucruCapturi . '/captura-a') . ' ' . escapeshellarg($lucruCapturi . '/captura-nu'));
    $strica($lucruCapturi . '/captura-nu');
    [$cR, $oR, $eR] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-nu', '--url=' . WP_PROBA_URL],
        ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
    if ($cR !== 1 || !str_contains($oR . $eR, 'captura urcată nu trece verificarea')) {
        $refuzuri[] = $caz . ' → ' . $cR;
    }
}
@mkdir($radacina . '/storage/logs/dev', 0770, true);
exec('rm -rf ' . escapeshellarg($radacina . '/storage/logs/dev/captura-in-repo') . ' && cp -r ' . escapeshellarg($lucruCapturi . '/captura-a') . ' ' . escapeshellarg($radacina . '/storage/logs/dev/captura-in-repo'));
[$cRepo, $oRepo, $eRepo] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $radacina . '/storage/logs/dev/captura-in-repo', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
exec('rm -rf ' . escapeshellarg($radacina . '/storage/logs/dev/captura-in-repo'));
$verifica('importul: o captură cu NU în raport, cu o adresă neidentică sau cu lanțul stricat e NU; una din repo e NU (ca unealta capturii)',
    $refuzuri === [] && $cRepo === 1 && str_contains($oRepo . $eRepo, 'captura nu se scrie în repo'), implode('; ', $refuzuri) . ' | ' . $cRepo . ' ' . $eRepo);
$verifica('pagina privată: pe drumul capturat nu se importă (ATENȚIE, nu NU)', str_contains($outE, 'Pagină privată') && str_contains($outE, 'drumul capturat ia doar paginile publice'), $outE);
$verifica('slug-ul grecesc: pagina găsită în captură după calea WordPress (percent-encoded) și transformată', preg_match('#pagina\s+/δωμάτια/ → /%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/  „Δωμάτια” \[dialectul divi4#u', $outE) === 1, $outE);
$camereA = (string) DosarCapturi::deschide($lucruCapturi . '/captura-a')->dupaCale('/camere/')?->corp;
$rA = $divi4->transforma($camereA, WP_PROBA_URL, $reguli5a);
$felA = $rA->numarPeFel();
$verifica('editările Camerelor: felurile declarate, câte trebuie (primul script din head e locul lui ax-date)', $felA === ['adresa-proprie' => 8, 'ax-date' => 1,
    'clasa-ua' => 1, 'config' => 3, 'dns-prefetch' => 1, 'fonturi-locale' => 2, 'handler-tarziu' => 2, 'link-wp' => 3, 'nonce' => 1, 'noscript-aruncat' => 1, 'script-aruncat' => 5], json_encode($felA));
$lipite = '';
$poz = 0;
foreach ($rA->editari as $e) {
    $lipite .= substr($camereA, $poz, $e['start'] - $poz) . $e['text'];
    $poz = $e['end'];
}
$verifica('T = captura cu editările lipite; în afara lor, octeții capturii; fiecare fel din lista închisă', $lipite . substr($camereA, $poz) === $rA->html
    && array_filter($rA->editari, static fn (array $e): bool => array_diff(explode('+', $e['fel']), array_merge(Normalizare::FELURI, Divi4::FELURI)) !== []) === []);
$verifica('T: niciun script executabil, niciun handler, legăturile WordPress scoase, fonturile locale, shortlink-ul și JSON-LD rămase',
    \App\Support\HtmlCapturat::verifica($rA->html) === [] && !str_contains($rA->html, 'onload=') && !str_contains($rA->html, 'xmlrpc.php') && !str_contains($rA->html, 'wp-json/wp/v2')
    && !str_contains($rA->html, 'dns-prefetch') && str_contains($rA->html, "href='/capturat/fonturi/" . FonturiGoogle::cheie('https://fonts.googleapis.com/css?family=Proba+Sans:400,700&#038;display=swap') . ".css'")
    && str_contains($rA->html, "rel='shortlink' href='" . WP_PROBA_URL . "/?p=1'") && str_contains($rA->html, 'application/ld+json') && substr_count($rA->html, 'data-ax-tarziu') === 2);
preg_match('#<script type="application/json" id="ax-date">(.*?)</script>#s', $rA->html, $m);
$ax = json_decode($m[1] ?? 'null', true);
$verifica('ax-date: configurațiile în ordinea din pagină (și vectorul CSS-ului late), nonce-urile și adresele normalizate', is_array($ax)
    && array_keys($ax) === ['file', 'et_link_options_data', 'DIVI', 'et_pb_custom', 'et_pb_box_shadow_elements', 'et_pb_sticky_elements']
    && $ax['et_pb_custom']['et_frontend_nonce'] === '{{ax:nonce}}' && $ax['et_pb_custom']['ajaxurl'] === '/wp-admin/admin-ajax.php' && $ax['file'] === ['/wp-content/et-cache/1/et-proba-late.css'], $m[1] ?? '');
$verifica('ax-date: în locul primului script din head; sloturile runtime-ului (cap înaintea lui, final unde stătea ultimul script)', $rA->sloturi['cap'] !== null
    && str_starts_with(substr($rA->html, (int) $rA->sloturi['cap']), '<script type="application/json" id="ax-date">') && str_starts_with(substr($rA->html, (int) $rA->sloturi['final']), "\n\t<span class=\"et_pb_scroll_top"));
$verifica('datele împărțite pe proprietar: _tb_header la antet, _tb_footer la subsol, restul la pagină', array_keys((array) ($rA->proprietari['antet']['et_pb_sticky_elements'] ?? [])) === ['et_pb_row_0_tb_header']
    && count($rA->proprietari['subsol']['et_link_options_data'] ?? []) === 1 && count($rA->proprietari['pagina']['et_link_options_data'] ?? []) === 1
    && array_keys((array) ($rA->proprietari['pagina']['et_pb_sticky_elements'] ?? [])) === ['et_pb_row_1']);
// Recaptura din noaptea adoptării: alte nonce-uri, alt UA (altă clasă de sistem): aceleași sha256-uri ale aruncatelor, același T.
$scrieCaptura($lucruCapturi . '/captura-b', 'bbbb222222', 'osx');
$rB = (new Divi4())->transforma((string) DosarCapturi::deschide($lucruCapturi . '/captura-b')->dupaCale('/camere/')?->corp, WP_PROBA_URL, $reguli5a);
$verifica('recaptura cu alte nonce-uri și alt UA: aceleași sha256-uri ale aruncatelor (CB-14, CB-15), același T, niciun NU', $rB->probleme === []
    && array_column($rB->aruncate, 'sha256') === array_column($rA->aruncate, 'sha256') && $rB->html === $rA->html, json_encode($rB->probleme));
// NU-urile: un script inline necunoscut (dialectul), un data-* necunoscut și un handler necunoscut (politica).
$scrieCaptura($lucruCapturi . '/captura-c', 'cccc333333', 'windows', ['/camere/' => '<script>window.proba = 1;</script>', '/contact/' => '<div data-proba-necunoscut="1"></div>',
    '/politica-de-confidentialitate/' => '<a href="#" onclick="proba()">x</a>']);
[$codG, $outG] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-c', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('un script inline necunoscut: NU pe pagină, cu sha256-ul lui de revizuit', $codG === 1 && preg_match('/NU\s+pagina „Camere & prețuri”: script inline necunoscut .*sha256 [0-9a-f]{64}/u', $outG) === 1, $outG);
$verifica('un data-* necunoscut: NU pe pagină (politica)', preg_match('/NU\s+pagina „Contact” \(HtmlCapturat\): atributul „data-proba-necunoscut”/u', $outG) === 1, $outG);
$verifica('un handler necunoscut: NU pe pagină (politica), netransformat', preg_match('/NU\s+pagina „Politica de confidențialitate” \(HtmlCapturat\): atribut de eveniment „onclick”/u', $outG) === 1, $outG);
$pagini0 = (int) $db->query('SELECT COUNT(*) FROM pages')->fetchColumn();
$capturate0 = (int) $db->query('SELECT COUNT(*) FROM pagini_capturate')->fetchColumn();
[$codG2, $outG2] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--aplica', '--fara-media', '--captura=' . $lucruCapturi . '/captura-c', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('--aplica pe drumul capturat cu pagini NU: cod 1, nimic scris (nici pagini, nici documente capturate)', $codG2 === 1 && str_contains($outG2, 'NU import')
    && (int) $db->query('SELECT COUNT(*) FROM pages')->fetchColumn() === $pagini0 && (int) $db->query('SELECT COUNT(*) FROM pagini_capturate')->fetchColumn() === $capturate0, $outG2);
// D7: secretul aleator al cardului embed rămâne în T exact cum l-a capturat (normalizat doar la comparație).
$embedProba = "<!DOCTYPE html>\n<html lang=\"ro-RO\" class=\"no-js\">\n<head>\n<title>Camere</title>\n</head>\n<body class=\"page\"><div class=\"wp-embed\">"
    . '<textarea class="wp-embed-share-input" readonly>&lt;blockquote data-secret=&quot;Pr0baSecr1&quot;&gt;&lt;/blockquote&gt;&lt;iframe src=&quot;' . WP_PROBA_URL
    . '/camere/embed/#?secret=Pr0baSecr1&quot;&gt;&lt;/iframe&gt;</textarea><div data-secret="Pr0baSecr2" style="background:url(' . WP_PROBA_URL . '/u.png)"></div>'
    . "</div>\n</body>\n</html>\n";
$rD7 = (new Divi4())->transforma($embedProba, WP_PROBA_URL, []);
$verifica('D7: secretul cardului embed rămâne cel capturat în T (în textarea și în atribute), adresele proprii din același atribut tot relative',
    $rD7->probleme === [] && !str_contains($rD7->html, '{{ax:secret}}') && str_contains($rD7->html, 'data-secret=&quot;Pr0baSecr1&quot;')
    && str_contains($rD7->html, '#?secret=Pr0baSecr1') && str_contains($rD7->html, 'data-secret="Pr0baSecr2"') && str_contains($rD7->html, 'background:url(/u.png)')
    && !in_array('secret', array_merge(...array_map(static fn (array $e): array => explode('+', $e['fel']), $rD7->editari ?: [['fel' => '']])), true), json_encode($rD7->probleme));
// Fail-closed: un text „{{ax:” deja în captură (aici în conținutul unei pagini) e NU la import, niciodată 500 la randare.
$scrieCaptura($lucruCapturi . '/captura-j', 'jjjj444444', 'windows', ['/camere/' => '<p>Scrieți {{ax:cale}} aici</p>']);
[$codJ, $outJ] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-j', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('fail-closed: „{{ax:” deja în captură = NU pe pagină la import (planul nu pornește)', $codJ === 1
    && preg_match('/NU\s+pagina „Camere & prețuri”: captura conține deja textul „\{\{ax:”/u', $outJ) === 1, $outJ);
// Recenzia dialectului (M1, m1, M2, M3, m5, m6): fiecare abatere e NU, în dialect sau în politica pe T; nimic nu aruncă.
$camere = static fn (string $inCorp = '', string $inCap = ''): string => str_replace("</head>\n", $inCap . "</head>\n",
    $paginaDivi('/camere/', 'Camere', 'aaaa111111', 'windows', $inCorp));
$dialect = static function (string $html) use ($reguli5a): object {
    try {
        return (new Divi4())->transforma($html, WP_PROBA_URL, $reguli5a);
    } catch (\Throwable $e) {
        return (object) ['probleme' => ['ARUNCAT: ' . $e::class . ': ' . $e->getMessage()], 'html' => ''];
    }
};
$neprinse = [];
foreach ([
    'text {{ax:nonce}}' => $camere('<p>Rezervă: {{ax:nonce}}</p>'),
    'title cu {{ax:secret}}' => $camere('<a href="/x/" title="{{ax:secret}}">x</a>'),
    'nonce-ul formularului deja {{ax:nonce}}' => str_replace('value="aaaa111111"', 'value="{{ax:nonce}}"', $camere()),
    'JSON-LD cu {{ax:seo.titlu}}' => $camere('', '<script type="application/ld+json">{"name":"{{ax:seo.titlu}}"}</script>' . "\n"),
    'parțial, neînchis' => $camere('<p>{{ax:fragment:antet:</p>'),
    'cu majuscule' => $camere('<p>{{AX:nonce}}</p>'),
    'într-o configurație' => str_replace('"item_count":"%d bucăți"', '"item_count":"{{ax:runtime:cap}}"', $camere()),
] as $eticheta => $html) {
    $r = $dialect($html);
    if (!str_contains(implode(' ', $r->probleme), 'captura conține deja textul „{{ax:”')) {
        $neprinse[] = $eticheta . ' (' . implode(' | ', $r->probleme) . ')';
    }
}
$verifica('M1: un „{{ax:” deja în captură (text, atribut, nonce, JSON-LD, parțial, majuscule, configurație) = NU în dialect', $neprinse === [], implode('; ', $neprinse));
$config = static fn (string $js): string => $camere("<script>\n" . $js . "\n</script>");
$neprinse = [];
foreach ([
    'număr 1e400' => [$config('var DIVI = {"x":1e400};'), 'nu se poate citi strict ca JSON (număr care nu încape într-un double'],
    'cheie dublată' => [$config('var DIVI = {"item_count":"a","item_count":"b"};'), 'cheie dublată „item_count”'],
    'cheie goală' => [$config('var et_pb_custom = {"":1};'), 'cheie goală'],
    'întreg 2^64' => [$config('var DIVI = {"x":18446744073709551616};'), 'întreg în afara ±2^53'],
    'aceeași configurație, alt șir' => [$config('var DIVI = {"item_count":"%d bucăți noi"};'), 'configurația „DIVI” apare de două ori, cu valori diferite'],
    'aceeași configurație, [] și {}' => [$config('var et_pb_box_shadow_elements = {};'), 'configurația „et_pb_box_shadow_elements” apare de două ori'],
    'aceeași configurație, true și 1' => [$camere("<script>var et_frontend_scripts = {\"x\":true};</script>\n<script>var et_frontend_scripts = {\"x\":1};</script>"),
        'configurația „et_frontend_scripts” apare de două ori'],
    'ax-id: <style id="ax-date"> în head' => [$camere('', "<style id=\"ax-date\">.a{}</style>\n"), 'id-urile și numele „ax-…” sunt ale importului'],
    'ax-id: <meta id="ax-date">' => [$camere('', "<meta id=\"ax-date\" content=\"x\">\n"), 'id-urile și numele „ax-…” sunt ale importului'],
    'ax-id: id="ax&#45;date"' => [$camere('<div id="ax&#45;date">x</div>'), 'id-urile și numele „ax-…” sunt ale importului'],
    'ax-id: name="AX-proba"' => [$camere('<a name="AX-proba">x</a>'), 'id-urile și numele „ax-…” sunt ale importului'],
] as $eticheta => [$html, $motiv]) {
    $r = $dialect($html);
    if (!str_contains(implode(' ', $r->probleme), $motiv)) {
        $neprinse[] = $eticheta . ' (' . implode(' | ', $r->probleme) . ')';
    }
}
$verifica('m1, m5, M2: JSON nestrict în configurații (1e400, chei dublate/goale, 2^64), configurații dublate diferit, id-uri „ax-…” = NU, fără excepții',
    $neprinse === [], implode('; ', $neprinse));
$r = $dialect($config('var DIVI = {"item_count":"%d bucăți"};'));
preg_match('#<script type="application/json" id="ax-date">(.*?)</script>#s', $r->html, $m);
$verifica('m5: aceeași configurație de două ori, cu JSON-ul canonic identic: o singură intrare în ax-date, niciun NU', $r->probleme === []
    && (json_decode($m[1] ?? 'null', true)['DIVI'] ?? null) === ['item_count' => '%d bucăți'], json_encode($r->probleme, JSON_UNESCAPED_UNICODE));
preg_match('#<script type="application/javascript">.*?var file\s+=.*?</script>#s', $camere(), $mInc);
$alDoilea = str_replace('et-proba-late.css', 'et-proba-late-2.css', $mInc[0] ?? '');
$r = $dialect($camere($alDoilea));
preg_match('#<script type="application/json" id="ax-date">(.*?)</script>#s', $r->html, $m);
$verifica('m6: două încărcătoare „late” pe pagină: ambele foi în ax-date, în ordinea din pagină', $r->probleme === [] && $alDoilea !== ''
    && (json_decode($m[1] ?? 'null', true)['file'] ?? null) === ['/wp-content/et-cache/1/et-proba-late.css', '/wp-content/et-cache/1/et-proba-late-2.css'],
    json_encode($r->probleme, JSON_UNESCAPED_UNICODE) . ' ' . ($m[1] ?? ''));
$neprinse = [];
foreach ([
    'javascript: în et_link_options_data' => [str_replace('"url":"' . str_replace('/', '\/', WP_PROBA_URL) . '\/contact\/"', '"url":"javascript:alert(1)"', $camere()), 'ax-adresa'],
    '//gazdă în et_link_options_data' => [str_replace('"url":"' . str_replace('/', '\/', WP_PROBA_URL) . '\/contact\/"', '"url":"\/\/alt.test\/x"', $camere()), 'ax-adresa'],
    '__proto__ într-o configurație' => [$config('var et_frontend_scripts = {"__proto__":{"x":true}};'), 'data-proto'],
] as $eticheta => [$html, $cod]) {
    $r = $dialect($html);
    $coduriT = array_map(static fn ($p): string => $p->cod, $r->html !== '' ? \App\Support\HtmlCapturat::verifica($r->html) : []);
    if (!in_array($cod, $coduriT, true)) {
        $neprinse[] = $eticheta . ' (dialect: ' . implode(' | ', $r->probleme) . '; politica: ' . implode(',', $coduriT) . ')';
    }
}
$verifica('M2: adresele din ax-date (javascript:, //gazdă) și poluarea prototipului: NU în politica pe T', $neprinse === [], implode('; ', $neprinse));
$preload = static fn (string $atribute): string => '<link rel="preload" as="style" ' . $atribute . ' onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n";
$neprinse = [];
foreach ([
    'pe <img>' => $camere('<img src="/a.png" alt="" onload="this.onload=null;this.rel=\'stylesheet\'">'),
    'as=script' => $camere('', '<link rel="preload" as="script" href="/x.js" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n"),
    'as=font' => $camere('', '<link rel="preload" as="font" href="/f.woff2" crossorigin onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n"),
    'fără href' => $camere('', $preload('')),
    '„style sheet”' => $camere('', '<link rel="preload" as="style" href="/x.css" onload="this.onload=null;this.rel=\'style sheet\'">' . "\n"),
    '„thi s.onload”' => $camere('', '<link rel="preload" as="style" href="/x.css" onload="thi s.onload=null;this.rel=\'stylesheet\'">' . "\n"),
    'cu spații' => $camere('', '<link rel="preload" as="style" href="/x.css" onload="this.onload = null; this.rel = \'stylesheet\'">' . "\n"),
    'onload dublat' => $camere('', '<link rel="preload" as="style" href="/x.css" onload="this.onload=null;this.rel=\'stylesheet\'" onload="alert(1)">' . "\n"),
    'media=all fără ghilimele' => $camere('', '<link rel="stylesheet" href="/x.css" media="print" onload="media=all">' . "\n"),
    'media pe <a>' => $camere('<a href="/x/" onload="media=\'all\'">x</a>'),
] as $eticheta => $html) {
    $r = $dialect($html);
    $coduriT = array_map(static fn ($p): string => $p->cod, $r->html !== '' ? \App\Support\HtmlCapturat::verifica($r->html) : []);
    if (!in_array('handler', $coduriT, true) || !str_contains($r->html, 'onload=')) {
        $neprinse[] = $eticheta . ' (dialect: ' . implode(' | ', $r->probleme) . '; politica: ' . implode(',', $coduriT) . ')';
    }
}
$verifica('M3: lista închisă a handlerelor e strictă: cele 10 abateri rămân netransformate și politica le dă NU', $neprinse === [], implode('; ', $neprinse));
$neprinse = [];
foreach (['href pe altă gazdă' => 'href="https://alt.test/x.css"', 'href protocol-relativ' => 'href="//alt.test/x.css"'] as $eticheta => $href) {
    $r = $dialect($camere('', $preload($href)));
    if ($r->probleme !== [] || str_contains($r->html, 'alt.test') || substr_count($r->html, 'data-ax-tarziu') !== 2 || ($r->numarPeFel()['indiciu-tert'] ?? 0) !== 1) {
        $neprinse[] = $eticheta . ' (' . json_encode($r->numarPeFel()) . ' ' . implode(' | ', $r->probleme) . ')';
    }
}
$verifica('M3: un preload cu handler spre altă gazdă nu se transformă: se scoate întreg (indiciu-tert), cu handlerul lui', $neprinse === [], implode('; ', $neprinse));
$r = $dialect($camere('', "<link rel=\"stylesheet\" id=\"proba-embed-css\" href=\"/wp-includes/css/proba-embed.css\" media=\"print\" onload=\"media='all'\">\n"));
$verifica('M3: forma exactă a cardului embed (onload="media=\'all\'" pe <link rel=stylesheet>) devine media="all"', $r->probleme === []
    && str_contains($r->html, '<link rel="stylesheet" id="proba-embed-css" href="/wp-includes/css/proba-embed.css" media="all">')
    && \App\Support\HtmlCapturat::verifica($r->html) === [], json_encode($r->probleme, JSON_UNESCAPED_UNICODE));
$scrieCaptura($lucruCapturi . '/captura-k', 'kkkk555555', 'windows', ['/camere/' => "<script>\nvar DIVI = {\"x\":1e400};\n</script>"]);
[$codK, $outK] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-k', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('m1: --plan cu 1e400 într-o configurație: cod 1 și NU pe pagină (nicio excepție neprinsă)', $codK === 1
    && preg_match('/NU\s+pagina „Camere & prețuri”: configurația de la octetul \d+ nu se poate citi strict ca JSON/u', $outK) === 1
    && !str_contains($outK, 'Fatal') && !str_contains($outK, 'JsonException'), $outK);
// m6 și indiciile de resurse: un <link> se scoate doar dacă toate rel-urile lui sunt din mulțimea scoasă; orice indiciu spre o
// gazdă străină se scoate (`indiciu-tert`), unul spre site rămâne (relativ); ce rămâne nepermis îl refuză politica.
$u5 = WP_PROBA_URL;
$r = $dialect($camere('', "<link rel=\"pingback stylesheet\" href=\"{$u5}/xmlrpc.php\">\n<link rel=\"EditURI pingback\" href=\"{$u5}/xmlrpc.php?rsd\">\n"
    . "<link rel=\"preconnect\" href=\"https://cdn.alt.test\" crossorigin>\n<link rel=\"preload\" as=\"font\" href=\"https://fonts.gstatic.com/s/proba.woff2\" crossorigin>\n"
    . "<link rel=\"prefetch\" href=\"//alt.test/x\">\n<link rel=\"modulepreload\" href=\"https://alt.test/m.js\">\n<link rel=\"dns-prefetch preconnect\" href=\"//alt.test\">\n"
    . "<link rel=\"preload\" as=\"image\" href=\"{$u5}/wp-content/uploads/2024/05/camera-dubla-300x200.jpg\">\n<link rel=\"preload stylesheet\" as=\"style\" href=\"https://alt.test/x.css\">\n"));
$fel = $r->numarPeFel();
$verifica('m6: rel="pingback stylesheet" rămâne (politica: link-rel), rel="EditURI pingback" se scoate (link-wp: 4 cu cele 3 ale paginii)',
    str_contains($r->html, 'rel="pingback stylesheet"') && !str_contains($r->html, 'rel="EditURI pingback"') && ($fel['link-wp'] ?? 0) === 4
    && in_array('link-rel', array_map(static fn ($p): string => $p->cod, \App\Support\HtmlCapturat::verifica($r->html)), true), json_encode($fel));
$verifica('indiciile spre alte gazde (preconnect, preload de font, prefetch //, modulepreload, dns-prefetch+preconnect) se scot: indiciu-tert 5',
    ($fel['indiciu-tert'] ?? 0) === 5 && !str_contains($r->html, 'cdn.alt.test') && !str_contains($r->html, 'fonts.gstatic.com/s/proba.woff2')
    && !str_contains($r->html, '//alt.test/x"') && !str_contains($r->html, 'alt.test/m.js') && !str_contains($r->html, 'rel="dns-prefetch preconnect"'), json_encode($fel));
$verifica('un indiciu spre site rămâne, relativ; „preload stylesheet” spre altă gazdă rămâne și politica îl refuză (link-tert)',
    str_contains($r->html, '<link rel="preload" as="image" href="/wp-content/uploads/2024/05/camera-dubla-300x200.jpg">')
    && str_contains($r->html, 'rel="preload stylesheet"')
    && in_array('link-tert', array_map(static fn ($p): string => $p->cod, \App\Support\HtmlCapturat::verifica($r->html)), true));
// m7: pe drumul capturat, politica primește manifestul capturii: un <link> din <noscript> doar spre o foaie din el.
$scrieCaptura($lucruCapturi . '/captura-m', 'mmmm666666', 'windows', ['/camere/' => '<noscript><link rel="stylesheet" href="/wp-content/proba/fara-js.css"></noscript>',
    '/contact/' => '<noscript><link rel="stylesheet" href="/wp-content/proba/alta.css"></noscript>']);
file_put_contents($lucruCapturi . '/captura-m/manifest.json', json_encode(['format' => 1, 'pagini' => [], 'fisiere' => [['url' => WP_PROBA_URL . '/wp-content/proba/fara-js.css',
    'cale' => '/wp-content/proba/fara-js.css', 'fel' => 'foaie', 'sha256' => str_repeat('a', 64), 'marime' => 1, 'tip' => 'text/css', 'sursa' => 'disc', 'pagini' => ['/camere/']]]]));
sigileazaCapturaProba($lucruCapturi . '/captura-m', WP_PROBA_URL);
[$codM, $outM] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-m', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a']);
$verifica('m7: cu manifestul capturii, <noscript><link> spre o foaie din el trece (Camere), spre una din afara lui e NU (Contact)', $codM === 1
    && preg_match('/NU\s+pagina „Contact” \(HtmlCapturat\): .*nu e o foaie din manifestul capturii/u', $outM) === 1
    && !str_contains($outM, 'pagina „Camere & prețuri” (HtmlCapturat)'), $outM);
// ax_structura (pista R, structura.js): clasele de structură pe document, din pachet (captura.structura), puse de dialect ultimele
// în ax-date (ale paginii); validate la valideazaReguli, în dialect și în politică.
$structura = ['/camere/' => ['et-last-child' => ['.et_pb_row_1 > .et_pb_column_1_3', '#main-content .et_pb_section_0 > div.et_pb_row']],
    '404' => ['et-last-child' => ['.et_pb_row_0 > .et_pb_column']]];
$reguliS = $reguli5a + ['captura' => ['structura' => $structura]];
$verifica('valideazaReguli(): captura.structura bună trece; cheie care nu e cale, clasă din afara listei, selector nepermis, valoare greșită — refuzate',
    ImportWordPress::valideazaReguli($reguliS) === []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => ['camere' => ['et-last-child' => ['.a']]]]] + $reguli5a) !== []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => ['/camere/' => ['et_pb_column_single' => ['.a']]]]] + $reguli5a) !== []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => ['/camere/' => ['et-last-child' => ['.a:hover']]]]] + $reguli5a) !== []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => ['/camere/' => ['et-last-child' => '.a']]]] + $reguli5a) !== []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => [['et-last-child' => ['.a']]]]] + $reguli5a) !== []
    && ImportWordPress::valideazaReguli(['captura' => ['structura' => ['/x/../' => ['et-last-child' => ['.a']]]]] + $reguli5a) !== [],
    implode('; ', ImportWordPress::valideazaReguli($reguliS)));
$rS = (new Divi4())->transforma($camere(), WP_PROBA_URL, $reguliS, '/camere/');
preg_match('#<script type="application/json" id="ax-date">(.*?)</script>#s', $rS->html, $m);
$axS = json_decode($m[1] ?? 'null', true);
$verifica('dialectul pune ax_structura documentului ultima în ax-date (a paginii), iar politica o acceptă', $rS->probleme === [] && is_array($axS)
    && array_key_last($axS) === 'ax_structura' && $axS['ax_structura'] === $structura['/camere/']
    && (array) ($rS->proprietari['pagina']['ax_structura'] ?? []) === $structura['/camere/'] && \App\Support\HtmlCapturat::verifica($rS->html) === [],
    json_encode($rS->probleme, JSON_UNESCAPED_UNICODE) . ' ' . ($m[1] ?? ''));
$rFara = (new Divi4())->transforma($camere(), WP_PROBA_URL, $reguliS, '/contact/');
$verifica('fără intrare pentru document (sau fără calea lui), ax-date rămâne fără ax_structura', !str_contains($rFara->html, 'ax_structura')
    && !str_contains((new Divi4())->transforma($camere(), WP_PROBA_URL, $reguliS)->html, 'ax_structura'));
$minim = "<!DOCTYPE html>\n<html lang=\"ro-RO\">\n<head>\n<title>Probă</title>\n</head>\n<body class=\"page\"><p>Text</p></body>\n</html>\n";
$rMin = (new Divi4())->transforma($minim, WP_PROBA_URL, $reguliS, '404');
$verifica('un document fără scripturi: ax-date cu ax_structura se pune înaintea lui </head> (o înlocuire a ancorei, aceiași octeți după)',
    $rMin->probleme === [] && str_contains($rMin->html, '<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".et_pb_row_0 \\u003E .et_pb_column"]}}</script></head>')
    && \App\Support\HtmlCapturat::verifica($rMin->html) === [], json_encode($rMin->probleme, JSON_UNESCAPED_UNICODE) . ' ' . $rMin->html);
$rRau = (new Divi4())->transforma($camere(), WP_PROBA_URL, $reguli5a + ['captura' => ['structura' => ['/camere/' => ['et-last-child' => ['div > *']]]]], '/camere/');
$verifica('o structură nepermisă ajunsă la dialect (ocolind valideazaReguli) e NU pe document, nu se pune', $rRau->probleme !== []
    && str_contains(implode(' ', $rRau->probleme), 'selector nepermis') && !str_contains($rRau->html, 'ax_structura'), json_encode($rRau->probleme, JSON_UNESCAPED_UNICODE));
@mkdir($lucru . '/pachet-5a-s', 0770, true);
$scriePachet($lucru . '/pachet-5a-s', $reguliS);
[$codS, $outS] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-s']);
$verifica('planul cu captura.structura în pachet: niciun NU (Camere primește ax_structura)', $codS === 0 && !str_contains($outS, '  NU'), $outS);
$scriePachet($lucru . '/pachet-5a-s', $reguli5a + ['captura' => ['structura' => ['/camere/' => ['et-last-child' => ['.a,.b']]]]]);
[$codS2, $outS2, $errS2] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-s']);
$verifica('un selector nepermis în pachet: planul nu pornește (NU pe import-wordpress.json, cod 1)', $codS2 === 1
    && str_contains($outS2 . $errS2, 'import-wordpress.json din pachet: captura.structura („/camere/”)'), $outS2 . $errS2);
// Datele structurii sunt ale clientului: pot sta în privat/captura-structura.json (git-ignorat), îmbinate de ImportWordPress::reguli().
$scriePachet($lucru . '/pachet-5a-s', $reguli5a);
@mkdir($lucru . '/pachet-5a-s/privat', 0770, true);
file_put_contents($lucru . '/pachet-5a-s/privat/captura-structura.json', (string) json_encode(['_despre' => 'probă', '/camere/' => $structura['/camere/']]));
[$codS3, $outS3] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-s']);
$reguliPrivat = (static function () use ($lucru): array {
    \App\Support\DateSite::reseteaza($lucru . '/pachet-5a-s');
    $r = ImportWordPress::reguli();
    \App\Support\DateSite::reseteaza(null);
    return $r;
})();
$verifica('captura.structura din privat/captura-structura.json: îmbinată în reguli, planul fără NU', $codS3 === 0 && !str_contains($outS3, '  NU')
    && ($reguliPrivat['captura']['structura']['/camere/'] ?? null) === $structura['/camere/'], $outS3);
// Fișierul privat se citește strict: o cheie dublată (json_decode ar păstra-o în tăcere pe ultima) e NU.
file_put_contents($lucru . '/pachet-5a-s/privat/captura-structura.json', '{"/camere/": {"et-last-child": [".a"]}, "/camere/": {"et-last-child": [".b"]}}');
[$codS5, $outS5, $errS5] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-s']);
$verifica('privat/captura-structura.json cu o cheie dublată: NU (citit strict, cod 1)', $codS5 === 1
    && str_contains($outS5 . $errS5, 'nu e un obiect JSON strict'), $outS5 . $errS5);
file_put_contents($lucru . '/pachet-5a-s/privat/captura-structura.json', (string) json_encode(['_despre' => 'probă', '/camere/' => $structura['/camere/']]));
file_put_contents($lucru . '/pachet-5a-s/import-wordpress.json', (string) json_encode($reguliS, JSON_UNESCAPED_UNICODE));
[$codS4, $outS4, $errS4] = $php(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--plan', '--captura=' . $lucruCapturi . '/captura-a', '--url=' . WP_PROBA_URL],
    ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-s']);
$verifica('aruncatele și captura.structura în pachetul public: NU (stau doar în privat/; recenzia 2, R3-7)', $codS4 === 1
    && str_contains($outS4 . $errS4, 'stă doar în privat/'), $outS4 . $errS4);
// Regulile private (privat/import-wordpress.json): sha256-urile scripturilor scoase și nonce-urile, adăugate la cele din pachet.
@mkdir($lucru . '/pachet-priv/privat', 0770, true);
$scriePachet($lucru . '/pachet-priv', $reguli5a);
file_put_contents($lucru . '/pachet-priv/privat/import-wordpress.json', (string) json_encode(['_despre' => 'probă',
    'scripturi_aruncate' => [['sha256' => str_repeat('b', 64), 'motiv' => 'scriptul de probă', 'fel' => 'script-inline']], 'captura' => ['nonce' => ['proba_privat']]]));
$reguliPriv = (static function () use ($lucru): array {
    \App\Support\DateSite::reseteaza($lucru . '/pachet-priv');
    $r = ImportWordPress::reguli();
    \App\Support\DateSite::reseteaza(null);
    return $r;
})();
file_put_contents($lucru . '/pachet-priv/privat/import-wordpress.json', '{"pagini": {}}');
$reguliPrivRau = (static function () use ($lucru): array {
    \App\Support\DateSite::reseteaza($lucru . '/pachet-priv');
    $r = ImportWordPress::reguli();
    \App\Support\DateSite::reseteaza(null);
    return $r;
})();
@unlink($lucru . '/pachet-priv/privat/import-wordpress.json');
// Recenzia 2, R3-8: „captura” publică care nu e obiect, cu structura privată alături, e NU (nu o TypeError); o cheie „_x” privată, NU.
file_put_contents($lucru . '/pachet-priv/import-wordpress.json', '{"continut": "capturat", "dialect": "divi4", "captura": "x"}');
file_put_contents($lucru . '/pachet-priv/privat/captura-structura.json', '{"/camere/": {"et-last-child": [".a"]}}');
$reguliX = (static function () use ($lucru) {
    \App\Support\DateSite::reseteaza($lucru . '/pachet-priv');
    try {
        return ImportWordPress::valideazaReguli(ImportWordPress::reguli());
    } catch (\Throwable $e) {
        return get_class($e);
    } finally {
        \App\Support\DateSite::reseteaza(null);
    }
})();
file_put_contents($lucru . '/pachet-priv/import-wordpress.json', (string) json_encode(['continut' => 'capturat', 'dialect' => 'divi4']));
file_put_contents($lucru . '/pachet-priv/privat/import-wordpress.json', '{"_ascuns": 1, "scripturi_aruncate": []}');
$reguliY = (static function () use ($lucru): array {
    \App\Support\DateSite::reseteaza($lucru . '/pachet-priv');
    $r = ImportWordPress::valideazaReguli(ImportWordPress::reguli());
    \App\Support\DateSite::reseteaza(null);
    return $r;
})();
@unlink($lucru . '/pachet-priv/privat/import-wordpress.json');
@unlink($lucru . '/pachet-priv/privat/captura-structura.json');
$verifica('„captura”: „x” în pachet cu structura privată alături: NU, fără excepție; o cheie „_ascuns” în privat: NU (nu ignorată în tăcere)',
    is_array($reguliX) && $reguliX !== [] && $reguliY !== [] && str_contains(implode(' ', $reguliY), '_ascuns'), json_encode([$reguliX, $reguliY], JSON_UNESCAPED_UNICODE));
$verifica('privat/import-wordpress.json: scripturile scoase și nonce-urile adăugate la reguli; orice altă cheie e refuzată (NU)',
    in_array(str_repeat('b', 64), array_column((array) ($reguliPriv['scripturi_aruncate'] ?? []), 'sha256'), true)
    && in_array('proba_privat', (array) ($reguliPriv['captura']['nonce'] ?? []), true) && ImportWordPress::valideazaReguli($reguliPriv) === []
    && ImportWordPress::valideazaReguli($reguliPrivRau) !== [] && isset($reguliPrivRau['privat: cheie nepermisă pagini']),
    json_encode(ImportWordPress::valideazaReguli($reguliPriv), JSON_UNESCAPED_UNICODE));
$verifica('valideazaReguli(): sha256 greșit, email în motiv, chei de captură pe drumul necapturat, nonce cu nume greșit — refuzate',
    ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => [['sha256' => 'abc', 'motiv' => 'x']]]) !== []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => [['sha256' => str_repeat('a', 64), 'motiv' => 'scrie la a@example.test']]]) !== []
    && ImportWordPress::valideazaReguli(['scripturi_aruncate' => [['sha256' => str_repeat('a', 64), 'motiv' => 'x']]]) !== []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'captura' => ['nonce' => ['rău nume']]]) !== []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'captura' => ['nonce' => ['proba_token']]]) === []);
$verifica('un document nesigur nu se transformă (NU, fără T)', ($rN = (new Divi4())->transforma('<!doctype html><select><div>x</div></select>', WP_PROBA_URL, []))->html === ''
    && str_contains(implode(' ', $rN->probleme), 'document nesigur'));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5a.2 Tăierea și scrierea capturată: --plan, --aplica, P1 la import, reimportul, o editare refuzată fără --suprascrie');
// O bază a site-ului doar pentru drumul capturat (celelalte secțiuni au importat deja pagini necapturate în cealaltă).
$bazaSiteC = 'cms_imp_site_c';
register_shutdown_function(static function () use ($root, $bazaSiteC): void {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $bazaSiteC . '`');
    } catch (Throwable) {
    }
});
$root->exec('DROP DATABASE IF EXISTS `' . $bazaSiteC . '`');
$root->exec('CREATE DATABASE `' . $bazaSiteC . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
[$codI, $outI, $errI] = $php(['scripts/install.php'], ['CMS_DEV_DB' => $bazaSiteC], false);
$dbC = $conectare($bazaSiteC);
$verifica('scripts/install.php pe a doua bază a site-ului: are tabelele capturii (nucleu/0070)', $codI === 0 && ScriereCapturata::disponibil($dbC), trim($outI . $errI));
$prima = (int) $i->site['prima_pagina'];
$paginiProba = [];
foreach ($i->pagini as $id => $pg) {
    if ($pg['stare'] === 'publish' && !$pg['parola']) {
        $paginiProba[] = ['cale' => $id === $prima ? '/' : (string) ($pg['cale_wp'] ?? $pg['cale']), 'titlu' => (string) $pg['titlu'], 'id' => (int) $id,
            'formular' => $pg['slug'] === 'contact', 'seoTitlu' => (string) ($i->seo[$id]['titlu'] ?? ''), 'descriere' => (string) ($i->seo[$id]['descriere'] ?? '')];
    }
}
$optiuniProba = ['url' => WP_PROBA_URL, 'nonce' => 'tttt888888', 'ua' => 'windows', 'an' => '2031', 'antet' => $ids['tb_antet'], 'subsol' => $ids['tb_subsol'],
    'corp404' => $ids['tb_corp404'], 'meniu' => [['/', 'Acasă'], ['/camere/', 'Camere'], ['/contact/', 'Contact']], 'cale404' => '/x1-inexistent/', 'embed' => ['/camere/']];
capturaDiviProba($lucruCapturi . '/captura-t', $paginiProba, $optiuniProba);
$dosarT = DosarCapturi::deschide($lucruCapturi . '/captura-t');
// Lista aruncatelor acestei capturi (aceleași scripturi pe toate documentele; noscript-ul GTM și el).
$aruncateT = [];
foreach ($dosarT->toate() as $x) {
    $r = $dosarT->raspuns($x['url']);
    if ($r === null || !str_contains($r->corp, '<!DOCTYPE html>')) {
        continue;
    }
    foreach ((new Divi4())->transforma($r->corp, WP_PROBA_URL, [])->necunoscute as $n) {
        if ($n['fel'] !== 'noscript' || str_contains($n['inceput'], 'googletagmanager')) {
            $aruncateT[$n['sha256']] = ['sha256' => $n['sha256'], 'motiv' => 'proba: ' . ($n['id'] ?? $n['inceput'])];
        }
    }
}
$reguliT = ['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => array_values($aruncateT)];
@mkdir($lucru . '/pachet-5a-t', 0770, true);
$scriePachet($lucru . '/pachet-5a-t', $reguliT);
$importT = static fn (array $extra) => $php(array_merge(['scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--fara-articole', '--captura=' . $lucruCapturi . '/captura-t',
    '--url=' . WP_PROBA_URL], $extra), ['SITE_DATE_DOSAR' => $lucru . '/pachet-5a-t', 'CMS_DEV_DB' => $bazaSiteC]);
[$codP, $outP, $errP] = $importT(['--plan']);
$nrPagini = count($paginiProba);
$verifica('--plan pe captura tăiată: niciun NU; documentele (paginile, 404, embed), un singur antet, subsol și banner, 2 răspunsuri, 2 formulare', $codP === 0
    && !str_contains($outP, '  NU') && str_contains($outP, 'captura: documente pagina ' . $nrPagini . ', 404 1, embed 1; fragmente antet 1, subsol 1, banner 1; '
    . 'răspunsuri înghețate 2; formulare 2; galerii 0'), $outP . $errP);
$verifica('--plan: head-ul paginilor pe jetoanele SEO (valorile AIOSEO se potrivesc), al 404-ului și al cardului înghețate', preg_match('/doc\s+pagina:\d+\s+\/camere\/\s+head: pe jetoanele SEO/u', $outP) === 1
    && preg_match('/doc\s+404\s+.*head: înghețat/u', $outP) === 1, $outP);
[$codA, $outA, $errA] = $importT(['--aplica', '--fara-media']);
$numar = static fn (string $t): int => (int) $dbC->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
$verifica('--aplica: paginile, documentele capturate, fragmentele, răspunsurile, formularele scrise; P1 la import trecut', $codA === 0
    && str_contains($outA, 'P1 la import') && $numar('pagini_capturate') === $nrPagini + 2 && $numar('fragmente_capturate') === 3 && $numar('raspunsuri_inghetate') === 2
    && $numar('formulare_compat') === 2 && $numar('pages') >= $nrPagini, $outA . $errA);
$camereRand = $dbC->query("SELECT p.html_content, c.cap_html, c.antet, c.subsol, c.banner, c.seo FROM pages p JOIN pagini_capturate c ON c.page_id = p.id WHERE p.slug = 'camere'")
    ->fetch(PDO::FETCH_ASSOC);
$verifica('pages.html_content e doar conținutul (secțiunile cu data-ax-s), head-ul și fragmentele stau în tabelele capturii', is_array($camereRand)
    && str_starts_with(ltrim((string) $camereRand['html_content']), '<div class="et_pb_section et_pb_section_0 et_section_regular" data-ax-s="0"')
    && !str_contains((string) $camereRand['html_content'], '<head') && !str_contains((string) $camereRand['html_content'], 'et-l--footer')
    && str_contains((string) $camereRand['cap_html'], '{{ax:runtime:cap}}') && str_contains((string) $camereRand['cap_html'], '{{ax:seo.titlu|html}}')
    && str_starts_with((string) $camereRand['antet'], 'antet:') && str_starts_with((string) $camereRand['subsol'], 'subsol:') && $camereRand['seo'] === 'jetoane',
    json_encode($camereRand, JSON_UNESCAPED_UNICODE));
// P1 independent: planul refăcut în proces, fiecare document randat din bază = T, după normalizarea de comparație.
$planT = ImportWordPress::planifica($dosarWp, WP_PROBA_URL, [new Divi4($dosarT)], ['fara' => ['articole']], $reguliT)['plan'];
$normT = new Normalizare(WP_PROBA_URL);
$difP1 = [];
foreach ($planT->capturata?->documente ?? [] as $cheieDoc => $doc) {
    $randat = ScriereCapturata::randeazaDinBaza($dbC, (string) $cheieDoc, [], null);
    if ($normT->document($randat) !== $normT->document(ScriereCapturata::asteptat($doc['taiere'], []))) {
        $difP1[] = (string) $cheieDoc;
    }
}
$verifica('P1 (randarea din bază față de T, refăcut separat): 0 diferențe pe ' . count($planT->capturata?->documente ?? []) . ' documente', $difP1 === []
    && count($planT->capturata?->documente ?? []) === $nrPagini + 2, implode(', ', $difP1));
$randat = ScriereCapturata::randeazaDinBaza($dbC, 'pagina:' . $ids['contact'], [], ['cale' => '/contact/', 'nonce' => 'NONCE-CERERE', 'an' => '2032']);
$verifica('randarea unei cereri: nonce-ul, anul și calea ei; formularul subsolului devine al doilea pe Contact (formular_index); câmpul-capcană; niciun jeton rămas',
    str_contains($randat, 'value="NONCE-CERERE"') && str_contains($randat, '© 2032 ' . WP_PROBA_SITE) && str_contains($randat, 'id="et_pb_contact_form_1"')
    && str_contains($randat, 'name="ax_capcana"') && !str_contains($randat, '{{ax:') && str_contains($randat, '<script type="application/json" id="ax-date">'), '');
$hashuri = static fn (): array => $dbC->query('SELECT cheie, hash_import FROM pagini_capturate UNION ALL SELECT cheie, hash_import FROM fragmente_capturate ORDER BY 1')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$inainte = $hashuri();
[$codR, $outR, $errR] = $importT(['--aplica', '--fara-media']);
$verifica('reimportul aceleiași capturi: cod 0, aceleași rânduri cu aceleași amprente, nimic dublat', $codR === 0 && $hashuri() === $inainte
    && $numar('pagini_capturate') === $nrPagini + 2 && $numar('fragmente_capturate') === 3, $outR . $errR);
$dbC->exec("UPDATE fragmente_capturate SET html = REPLACE(html, 'Folosim cookie-uri', 'Folosim cookie-uri (editat)') WHERE fel = 'banner'");
[$codE1, $outE1, $errE1] = $importT(['--plan']);
[$codE2, $outE2, $errE2] = $importT(['--aplica', '--fara-media']);
$editat = (string) $dbC->query("SELECT html FROM fragmente_capturate WHERE fel = 'banner'")->fetchColumn();
$verifica('un fragment editat după import: --plan îl dă NU, --aplica îl refuză (cod 1) și rândul rămâne cum l-a lăsat omul', $codE1 === 1
    && str_contains($outE1, 'editat după importul de dinainte: fragmentul „banner:') && $codE2 === 1 && str_contains($editat, '(editat)'), $outE1 . $errE2);
[$codE3, $outE3, $errE3] = $importT(['--aplica', '--fara-media', '--suprascrie']);
$verifica('cu --suprascrie, rândul editat se scrie din nou din captură (ATENȚIE în ieșire), cu P1 trecut', $codE3 === 0
    && !str_contains((string) $dbC->query("SELECT html FROM fragmente_capturate WHERE fel = 'banner'")->fetchColumn(), '(editat)')
    && str_contains($outE3, 'scris peste (--suprascrie)') && $hashuri() === $inainte, $outE3 . $errE3);
// Fail-closed și pe răspunsurile înghețate: un „{{ax:” în robots.txt e NU la import, nu un 500 la randare.
file_put_contents($lucruCapturi . '/captura-t/brut/' . hash('sha256', WP_PROBA_URL . '/robots.txt') . '/chrome-win-1.corp', "User-agent: *\n# {{ax:nonce}}\n");
sigileazaCapturaProba($lucruCapturi . '/captura-t', WP_PROBA_URL);
[$codF2, $outF2] = $importT(['--plan']);
$verifica('fail-closed: „{{ax:” într-un răspuns înghețat (robots.txt) = NU la --plan', $codF2 === 1
    && preg_match('/NU\s+captura: \/robots\.txt: captura conține deja textul „\{\{ax:”/u', $outF2) === 1, $outF2);

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
