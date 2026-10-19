<?php

declare(strict_types=1);

/**
 * Partea PHP a lui tools/dev/compara-cu-baza.sh (nu se rulează singur):
 * aceleași cereri spre serverul curent și spre copia de bază, apoi compararea
 * răspunsurilor normalizate.
 *
 *   php compara-cu-baza.php <port-baza> <commit-baza> <dosarul-copiei-de-baza>
 *
 * Ce se scoate înainte de comparare (diferă la fiecare cerere, nu ține de cod):
 * tokenurile formularelor (checkout, înregistrare, CSRF), momentul randării,
 * versiunea fișierelor CSS/JS (`?v=` = data fișierului pe disc), identificatorii
 * aleși la întâmplare ai formularelor, ora curentă.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Database;
use App\Support\Plugins;

$portBaza = (string) ($argv[1] ?? '');
$commitBaza = (string) ($argv[2] ?? '?');
$dosarBaza = rtrim((string) ($argv[3] ?? ''), '/');
if ($portBaza === '') {
    fwrite(STDERR, "Folosește tools/dev/compara-cu-baza.sh\n");
    exit(1);
}
$portCurent = (string) (getenv('CMS_DEV_PORT') ?: '8701');
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$dosarDiff = $dosarDev . '/compara';
@mkdir($dosarDiff, 0770, true);
foreach (glob($dosarDiff . '/*') ?: [] as $vechi) {
    @unlink($vechi);
}

$neoprite = array_filter(Plugins::stari(), static fn (string $s): bool => $s !== 'pornit');
if ($neoprite !== []) {
    fwrite(STDERR, 'Comparația are sens doar cu licența „toate” (pune-o cu: tools/dev/php.sh tools/dev/licenta.php toate). '
        . 'Acum nu sunt pornite: ' . implode(', ', array_keys($neoprite)) . "\n");
    exit(1);
}

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Baza de test nu răspunde.\n");
    exit(1);
}
$unu = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '');
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');

$cerere = static function (string $port, string $metoda, string $cale, string $jar, array $date = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HEADER => true,
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marimeAntet = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antet = substr($raspuns, 0, $marimeAntet);
    $location = preg_match('/^Location:\s*(\S+)/mi', $antet, $m) === 1 ? $m[1] : '';
    return ['cod' => $cod, 'corp' => substr($raspuns, $marimeAntet), 'location' => $location];
};

/** Ce diferă la fiecare cerere, indiferent de cod. */
$normalizeaza = static function (string $html) use ($portBaza, $portCurent, $dosarBaza, $radacina): string {
    $html = str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $html);
    // Căile de pe disc apar în instrucțiunile de cron din admin.
    if ($dosarBaza !== '') {
        $html = str_replace($dosarBaza, $radacina, $html);
    }
    $inlocuiri = [
        // Tokenurile formularelor și momentul randării lor.
        '/(name="(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)"\s+value=")[^"]*"/' => '$1<TOKEN>"',
        '/(name="(?:checkout_form_rendered_at|register_form_rendered_at)"\s+value=")[^"]*"/' => '$1<MOMENT>"',
        '/("(?:token|rendered_at)"\s*:\s*)("[^"]*"|\d+)/' => '$1<TOKEN>',
        // Aceleași câmpuri în HTML-ul de previzualizare pus în JSON (editorul de pagini: \u0022 în loc de ghilimele).
        '/(name=\\\\u0022(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)\\\\u0022\s+value=\\\\u0022)[^\\\\]*/' => '$1<TOKEN>',
        '/(name=\\\\u0022(?:checkout_form_rendered_at|register_form_rendered_at)\\\\u0022\s+value=\\\\u0022)\d+/' => '$1<MOMENT>',
        '/(String\()"[0-9a-f]{16,}"/' => '$1"<TOKEN>"',
        '/(Number\()\d{9,}/' => '$1<MOMENT>',
        '/(window\.BT_CSRF\s*=\s*)"[^"]*"/' => '$1"<TOKEN>"',
        // Versiunea fișierelor statice = data fișierului pe disc.
        '/(\.(?:css|js)\?v=)\d+/' => '$1<V>',
        // Identificatori aleși la întâmplare (formularele de checkout/cont).
        '/\b(bv-checkout-[a-z0-9-]*?)[0-9a-f]{6,}\b/' => '$1<ID>',
        '/\b([a-z]+-)[0-9a-f]{12,}\b/' => '$1<ID>',
    ];
    foreach ($inlocuiri as $tipar => $cu) {
        $html = (string) preg_replace($tipar, $cu, $html);
    }
    return $html;
};

// Două sesiuni pe fiecare server: vizitator (cu coș) și admin; plus clientul logat.
$jaruri = [];
foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
    foreach (['viz', 'adm', 'cli', 'adm-magazin', 'adm-blog', 'adm-emailuri'] as $rol) {
        $jaruri[$eticheta][$rol] = $dosarDiff . '/jar-' . $eticheta . '-' . $rol . '.txt';
        @unlink($jaruri[$eticheta][$rol]);
    }
}

$idMenta = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$slugBbd = $unu('SELECT slug FROM products WHERE bbd_enabled = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
$slugPre = $unu('SELECT slug FROM products WHERE preorder_enabled = 1 ORDER BY id LIMIT 1');
$slugBlog = $unu('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1');
$idClient = $unu("SELECT id FROM users WHERE email = 'andrei.exemplu@exemplu.ro'");
$nrComanda = $unu('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
$idPagina = $unu("SELECT id FROM pages WHERE slug = '' LIMIT 1");
$tokenLink = $unu('SELECT token FROM order_payment_links ORDER BY id LIMIT 1');
$idCupon = $unu('SELECT id FROM coupons ORDER BY id LIMIT 1');
$dupaLogin = [];

foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['viz'], ['quantity' => '2']);
    $cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm'], ['email' => $adminEmail, 'password' => $parola]);
    $cerere($port, 'POST', '/login', $jaruri[$eticheta]['cli'], ['email' => 'andrei.exemplu@exemplu.ro', 'password' => $parola]);
    // Adminii cu rol parțial: meniul lor are alte intrări, iar după login ajung pe prima pagină a rolului.
    foreach (['magazin', 'blog', 'emailuri'] as $rolAdmin) {
        $dupaLogin[$eticheta][$rolAdmin] = $cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm-' . $rolAdmin],
            ['email' => $rolAdmin . '@exemplu.ro', 'password' => $parola])['location'];
    }
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['cli'], ['quantity' => '1']);
}

// [rol, cale]: paginile-cheie (cerute de sarcină) și vecinii lor apropiați.
$pagini = [
    ['viz', '/'],
    ['viz', '/magazin'],
    ['viz', '/categorie/ceaiuri'],
    ['viz', '/marca/livada-demo'],
    ['viz', '/produs/ceai-de-menta'],
    ['viz', '/produs/ceai-de-musetel'],
    ['viz', '/produs/' . $slugBbd],
    ['viz', '/produs/' . $slugPre],
    ['viz', '/cos'],
    ['viz', '/checkout'],
    ['viz', '/blog'],
    ['viz', '/blog/' . $slugBlog],
    ['viz', '/contact'],
    ['viz', '/termeni-si-conditii'],
    ['viz', '/pagina-care-nu-exista'],
    ['viz', '/login'],
    ['viz', '/register'],
    ['viz', '/api/cart/summary'],
    ['viz', '/api/search/products?q=ceai'],
    ['viz', '/api/shop/catalog'],
    ['cli', '/contul-meu'],
    ['cli', '/checkout'],
    ['cli', '/cos'],
    ['adm', '/admin'],
    ['adm', '/admin/orders'],
    ['adm', '/admin/orders?search=' . rawurlencode($nrComanda)],
    ['adm', '/admin/orders/trash'],
    ['adm', '/admin/products'],
    ['adm', '/admin/users'],
    ['adm', '/admin/users/' . $idClient],
    ['adm', '/admin/users/settings'],
    ['adm', '/admin/users/points'],
    ['adm', '/admin/coupons'],
    ['adm', '/admin/settings/store'],
    ['adm', '/admin/settings/payments'],
    ['adm', '/admin/settings/shipping'],
    ['adm', '/admin/settings/erp'],
    ['adm', '/admin/emails/automation'],
    ['adm', '/admin/emails/newsletters?tab=ecommerce'],
    ['adm', '/admin/emails/newsletters?tab=templates'],
    ['adm', '/admin/pages'],
    ['adm', '/admin/pages/' . $idPagina . '/edit'],
    ['adm', '/admin/emails/newsletters?tab=campaigns'],
    ['adm', '/admin/design'],
    ['adm', '/admin/settings/google'],
    ['adm', '/admin/blog/posts'],
    ['adm-magazin', '/admin'],
    ['adm-magazin', '/admin/orders'],
    ['adm-magazin', '/admin/settings/payments'],
    ['adm-blog', '/admin/blog/posts'],
    ['adm-blog', '/admin/blog/categories'],
    ['adm-emailuri', '/admin/emails/newsletters?tab=subscribers'],
    ['adm-emailuri', '/admin/emails/sender'],
    // Paginile atinse de ascunderea textelor pluginurilor (Etapa 1, pasul 3).
    ['viz', '/plata/' . $tokenLink],
    ['adm', '/admin/settings/floating-cart'],
    ['adm', '/admin/settings/admins'],
    ['adm', '/admin/settings/payments?tab=bt'],
    ['adm', '/admin/emails/test'],
    ['adm', '/admin/emails/newsletters?tab=history'],
    ['adm', '/admin/emails/newsletters?tab=contact_forms'],
    ['adm', '/admin/users?panel=import'],
    ['adm', '/admin/products/new'],
    ['adm', '/admin/coupons?coupon=' . $idCupon],
    ['adm', '/admin/promo-products'],
];

$identice = 0;
$diferite = [];
foreach ($pagini as [$rol, $cale]) {
    $r = [];
    foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
        $r[$eticheta] = $cerere($port, 'GET', $cale, $jaruri[$eticheta][$rol]);
    }
    $a = $normalizeaza($r['baza']['corp']);
    $b = $normalizeaza($r['curent']['corp']);
    $eticheta = str_pad($rol, 13) . $cale;
    if ($r['baza']['cod'] === $r['curent']['cod'] && $r['baza']['location'] === $r['curent']['location'] && $a === $b) {
        $identice++;
        echo '  OK    ', $r['curent']['cod'], '  ', $eticheta, "\n";
        continue;
    }
    $nume = preg_replace('/[^a-z0-9]+/i', '_', trim($rol . $cale, '/')) ?: 'pagina';
    file_put_contents($dosarDiff . '/' . $nume . '.baza.html', $a);
    file_put_contents($dosarDiff . '/' . $nume . '.curent.html', $b);
    $diff = (string) shell_exec('diff -u ' . escapeshellarg($dosarDiff . '/' . $nume . '.baza.html') . ' '
        . escapeshellarg($dosarDiff . '/' . $nume . '.curent.html') . ' 2>&1');
    file_put_contents($dosarDiff . '/' . $nume . '.diff', $diff);
    $linii = substr_count($diff, "\n- ") + substr_count($diff, "\n+ ") + preg_match_all('/^[-+](?![-+])/m', $diff);
    $diferite[] = $eticheta;
    echo '  DIFERĂ ', $r['baza']['cod'], '→', $r['curent']['cod'], '  ', $eticheta, '  (', $dosarDiff, '/', $nume, '.diff)', "\n";
}
// Unde ajunge fiecare administrator cu rol parțial după login.
foreach ($dupaLogin['baza'] ?? [] as $rolAdmin => $locatieBaza) {
    $locatieCurenta = $dupaLogin['curent'][$rolAdmin] ?? '';
    $eticheta = str_pad('login', 13) . $rolAdmin . '@exemplu.ro → ' . $locatieCurenta;
    if ($locatieBaza === $locatieCurenta) {
        $identice++;
        echo '  OK    302  ', $eticheta, "\n";
    } else {
        $diferite[] = $eticheta;
        echo '  DIFERĂ 302  ', $eticheta, ' (baza: ', $locatieBaza, ")\n";
    }
}
foreach (glob($dosarDiff . '/jar-*') ?: [] as $jar) {
    @unlink($jar);
}
echo "\n", 'Față de ', $commitBaza, ': ', $identice, ' pagini identice, ', count($diferite), " diferite.\n";
exit($diferite === [] ? 0 : 1);
