<?php

declare(strict_types=1);

/**
 * Lucrătorul comparației calculelor (WP-11, G2; docs/VERIFICARE.md, „Comparația calculelor”). Îl pornește doar
 * tools/verificare/compara-calcule.php, o dată pe fiecare cod: codul vechi al site-ului (pe site-ul de verificare
 * `~/domains/<verificare>/cod-vechi/<site>/`, local copia commit-ului revizuit al Bioscem sau al Biovitality) și codul comun. Amândouă
 * rulează pe aceeași bază: copia de verificare (anonimizată, de unică folosință) sau, local, o copie a bazei de
 * dezvoltare. Niciodată pe o bază live: ambele coduri trimit DDL chiar în calculul verificat (CheckoutCalculator,
 * Precomanda, ErpSync), de aceea, înaintea oricărui cod al aplicației, lucrătorul verifică baza la care l-a legat
 * codul încărcat (garda de mai jos).
 *
 *   php lucrator-calcule.php --cod=<rădăcina codului> [--intrare=<fișier> --iesire=<fișier>]
 *
 * Cererea e un JSON (pe stdin, sau în fișierul --intrare, 0600, cât proc_open lipsește): `faza` („verifica”: doar garda,
 * versiunea PHP, pluginurile codului comun și amprentele setărilor calculelor; „calcule”: cazurile), `mod` („verificare”
 * sau „local”), `baza` (numele bazei la care trebuie să fie legat codul), `sursa` și `incarcat_la` (rândul `_verificare`
 * al copiei), `profil` (`bioscem` sau `biovitality`: pluginurile site-ului din `licente_dev`), `cazuri`. Numele bazei vine pe stdin, nu în linia de comandă (`ps`).
 *
 * Pentru fiecare caz, prin API-ul public al codului, exact ca un client în checkout: `Cart::clear()`, `Cart::add` pentru
 * fiecare linie a comenzii (produsul, cantitatea, cheia ofertei BBD), `Cart::applyCoupon`, `CustomerAuth::login` și
 * `Cart::requestPoints`, `Cart::setCounty` și `CheckoutCalculator::setLocalitate` (județul și localitatea de facturare,
 * ca în checkout), `CheckoutCalculator::alegeFanbox`; apoi `CheckoutCalculator::buildSummary`, `adminRecalcShipping` (cu
 * transportul salvat și cu 0, ca la trecerea pe FANbox din admin; cu argumentul transportului gratuit din produs al
 * comenzii, WP-20 C14a, pe care un cod fără el îl ignoră), `LoyaltyService::estimateEarnPoints` (din sumar și din
 * comanda salvată) și `ErpSync::buildPayload` pe comanda salvată (sha1 pe JSON-ul canonic, plus câte un sha1 pe fiecare
 * cheie de sus, ca să se vadă care diferă). Sesiunea e un array în memorie: un handler de sesiune gol pornit înainte de
 * bootstrap.php (bootstrap-ul Bioscem pornește altfel o sesiune și în linia de comandă), deci niciun fișier de sesiune.
 *
 * Răspunsul: un rând JSON pe caz (`i`, valorile rotunjite la 2 zecimale; nimic nu se tipărește pe ecran: le compară
 * compara-calcule.php, care scrie doar numere) și un rând final `gata`. Erorile PHP nu se tipăresc (mesajul poate avea
 * date): se numără, cu felul și locul din cod (`E_WARNING în src/Support/X.php:12`); o excepție a unui caz e doar
 * clasa și locul ei. Cod de ieșire: 0 gata, 2 garda a refuzat (nimic rulat), 1 altă oprire.
 *
 * Sintaxa PHP 8.0 (codul vechi al unui site poate rula pe PHP-ul lui, `--php-vechi`).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('html_errors', '0');
set_time_limit(0);

$argumente = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(cod|intrare|iesire)=(.+)$/s', $argument, $m) !== 1) {
        fwrite(STDERR, "lucrator-calcule: argument necunoscut (îl pornește doar compara-calcule.php)\n");
        exit(1);
    }
    $argumente[$m[1]] = $m[2];
}
$cod = isset($argumente['cod']) ? realpath($argumente['cod']) : false;
if ($cod === false || !is_file($cod . '/bootstrap.php') || !is_file($cod . '/src/Support/CheckoutCalculator.php')) {
    fwrite(STDERR, "lucrator-calcule: --cod nu e rădăcina unui cod de site\n");
    exit(1);
}

/** Răspunsul: pe stdout sau, cu --iesire, în fișier (0600). */
$iesiri = [];
$scrie = static function (array $rand) use (&$iesiri, $argumente): void {
    $text = json_encode($rand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    if (isset($argumente['iesire'])) {
        $iesiri[] = $text;
        return;
    }
    fwrite(STDOUT, $text);
};
$incheie = static function (int $codIesire) use (&$iesiri, $argumente): void {
    if (isset($argumente['iesire'])) {
        $vechi = umask(0077);
        file_put_contents($argumente['iesire'], implode('', $iesiri));
        @chmod($argumente['iesire'], 0600);
        umask($vechi);
    }
    exit($codIesire);
};

// Erorile PHP: numărate, cu felul și locul din codul care rulează (relativ la rădăcina lui), fără mesaj.
$avertismente = [];
$loc = static function (string $fisier, int $linie) use ($cod): string {
    // Locul în codul care rulează sau, pentru lucrătorul însuși, în codul comun (tools/verificare/…).
    $relativ = basename($fisier);
    foreach ([$cod . '/', dirname(__DIR__, 2) . '/'] as $prefix) {
        if (str_starts_with($fisier, $prefix)) {
            $relativ = substr($fisier, strlen($prefix));
            break;
        }
    }
    return preg_match('/^[A-Za-z0-9._\/-]{1,200}$/', $relativ) === 1 ? $relativ . ':' . $linie : '?';
};
set_error_handler(static function (int $nivel, string $mesaj, string $fisier = '', int $linie = 0) use (&$avertismente, $loc): bool {
    if ((error_reporting() & $nivel) === 0) {
        return false;
    }
    $nume = [E_WARNING => 'E_WARNING', E_NOTICE => 'E_NOTICE', E_DEPRECATED => 'E_DEPRECATED', E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE', E_USER_DEPRECATED => 'E_USER_DEPRECATED', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR'];
    $cheie = ($nume[$nivel] ?? 'E_' . $nivel) . ' în ' . $loc($fisier, $linie);
    $avertismente[$cheie] = ($avertismente[$cheie] ?? 0) + 1;
    return true;
});
register_shutdown_function(static function () use ($loc): void {
    $eroare = error_get_last();
    if (is_array($eroare) && in_array($eroare['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $fel = str_contains((string) $eroare['message'], 'Allowed memory size') ? 'memoria PHP s-a terminat'
            : (str_contains((string) $eroare['message'], 'Maximum execution time') ? 'timpul PHP s-a terminat' : 'eroare PHP fatală');
        fwrite(STDERR, 'OPRIT: ' . $fel . ' în ' . $loc((string) $eroare['file'], (int) $eroare['line']) . "\n");
    }
});

$textCerere = isset($argumente['intrare']) ? (string) @file_get_contents($argumente['intrare']) : (string) stream_get_contents(STDIN);
$cerere = json_decode($textCerere, true);
if (!is_array($cerere) || !in_array($cerere['faza'] ?? '', ['verifica', 'calcule'], true) || !in_array($cerere['mod'] ?? '', ['local', 'verificare'], true)) {
    $scrie(['ok' => false, 'refuz' => 'cererea nu se înțelege']);
    $incheie(1);
}
$refuza = static function (string $motiv) use ($scrie, $incheie): void {
    $scrie(['ok' => false, 'refuz' => $motiv]);
    $incheie(2);
};

// ── Sesiunea: un array în memorie, fără niciun fișier (în ambele coduri) ──
if (session_status() === PHP_SESSION_ACTIVE) {
    $refuza('PHP-ul a pornit singur o sesiune (session.auto_start)');
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new class implements SessionHandlerInterface {
        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            return '';
        }

        public function write(string $id, string $data): bool
        {
            return true;
        }

        public function destroy(string $id): bool
        {
            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            return 0;
        }
    }, true);
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    @session_start();
}
$_SESSION = [];

// ── Codul: bootstrap-ul și conexiunea lui (din .env-ul codului sau, local, din mediu) ──
chdir($cod);
if ($cerere['mod'] === 'local' && is_file($cod . '/.env')) {
    $refuza('codul are un .env (local, baza vine doar din mediul uneltelor de dezvoltare)');
}
require $cod . '/bootstrap.php';
$config = require $cod . '/config/app.php';
$db = \App\Support\Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    $refuza('codul nu se leagă la bază');
}

// ── Garda: baza la care l-a legat codul e copia așteptată ──
$baza = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if ($baza === '' || !hash_equals((string) ($cerere['baza'] ?? ''), $baza)) {
    $refuza('codul e legat la altă bază decât copia comparației');
}
if ($cerere['mod'] === 'local') {
    if (preg_match('/^cms_[a-z0-9_]+$/', $baza) !== 1 || trim((string) getenv('CMS_DEV_DIR')) === '') {
        $refuza('local, doar o bază cms_* a uneltelor de dezvoltare');
    }
} else {
    $exista = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn();
    $marcaj = $exista > 0 ? $db->query('SELECT sursa, anonimizat, incarcat_la FROM _verificare LIMIT 1')->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($marcaj) || (int) $marcaj['anonimizat'] !== 1 || (string) $marcaj['sursa'] !== (string) ($cerere['sursa'] ?? '')
        || (string) $marcaj['incarcat_la'] === '' || (string) $marcaj['incarcat_la'] !== (string) ($cerere['incarcat_la'] ?? '')) {
        $refuza('baza nu e copia de verificare încheiată și anonimizată pe care o compară compara-calcule.php');
    }
}

$settings = \App\Support\Settings::all($db);

if ($cerere['faza'] === 'verifica') {
    // Pluginurile codului comun (codul vechi n-are pluginuri): cele ale site-ului trebuie să fie pornite, altfel
    // comparația ar număra ca diferențe ce oprește licența (de exemplu cupoanele).
    $oprite = null;
    if (class_exists(\App\Support\Plugins::class)) {
        $oprite = [];
        foreach ((array) (\App\Support\Plugins::catalog()['licente_dev'][(string) ($cerere['profil'] ?? '')] ?? []) as $cheie) {
            if (\App\Support\Plugins::stare((string) $cheie) !== 'pornit') {
                $oprite[] = (string) $cheie;
            }
        }
        if ((\App\Support\Plugins::catalog()['licente_dev'][(string) ($cerere['profil'] ?? '')] ?? []) === []) {
            $refuza('profilul nu are licența lui în catalogul pluginurilor (licente_dev)');
        }
    }
    // Amprenta setărilor din care se fac calculele (doar numele cheilor și sha1 pe valoare; informativ).
    $amprente = [];
    foreach ($settings as $cheie => $valoare) {
        $cheie = (string) $cheie;
        if (preg_match('/^(shipping_|loyalty_points_)/', $cheie) === 1 || in_array($cheie, ['fan_awb_single', 'fan_default_weight_kg', 'fan_kg_max_colet',
            'fan_live_tariff_enabled', 'fan_parcel_count', 'fan_shipment_type', 'erp_enabled', 'erp_stock_enabled', 'default_vat_percent'], true)) {
            $amprente[$cheie] = sha1(is_scalar($valoare) || $valoare === null ? (string) $valoare : (string) json_encode($valoare));
        }
    }
    ksort($amprente);
    $scrie(['ok' => true, 'php' => PHP_VERSION, 'pluginuri_oprite' => $oprite, 'setari' => $amprente]);
    $incheie(0);
}

// ── Cazurile ──
$r2 = static fn (mixed $v): float => round((float) $v, 2);
/** JSON canonic (cheile sortate, la orice adâncime; listele rămân în ordinea lor), pentru sha1. */
$canonic = static function (mixed $v) use (&$canonic): mixed {
    if (!is_array($v)) {
        return $v;
    }
    $lista = array_keys($v) === range(0, count($v) - 1);
    $rezultat = [];
    foreach ($v as $k => $x) {
        $rezultat[$k] = $canonic($x);
    }
    if (!$lista) {
        ksort($rezultat, SORT_STRING);
    }
    return $rezultat;
};
$sha = static fn (mixed $v): string => sha1((string) json_encode($canonic($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
$comanda = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$numar = 0;
foreach ((array) ($cerere['cazuri'] ?? []) as $caz) {
    $numar++;
    $i = (int) ($caz['i'] ?? 0);
    try {
        $_SESSION = [];
        \App\Support\Cart::clear();
        foreach ((array) ($caz['linii'] ?? []) as $linie) {
            $produs = (int) ($linie[0] ?? 0);
            if ($produs > 0) {
                $bbd = trim((string) ($linie[2] ?? ''));
                \App\Support\Cart::add($produs, max(1, (int) ($linie[1] ?? 1)), $bbd !== '' ? $bbd : null);
            }
        }
        $cupon = trim((string) ($caz['cupon'] ?? ''));
        if ($cupon !== '') {
            \App\Support\Cart::applyCoupon($cupon);
        }
        \App\Support\CustomerAuth::logout();
        if ((int) ($caz['client'] ?? 0) > 0) {
            \App\Support\CustomerAuth::login((int) $caz['client']);
        }
        if ((int) ($caz['puncte'] ?? 0) > 0) {
            \App\Support\Cart::requestPoints((int) $caz['puncte']);
        }
        \App\Support\Cart::setCounty((string) ($caz['judet'] ?? ''));
        \App\Support\CheckoutCalculator::setLocalitate((string) ($caz['oras'] ?? ''));
        \App\Support\CheckoutCalculator::alegeFanbox((int) ($caz['fanbox'] ?? 0), (bool) ($caz['la_fanbox'] ?? false));

        $s = \App\Support\CheckoutCalculator::buildSummary($db, $settings);
        $linii = [];
        foreach ((array) ($s['lines'] ?? []) as $l) {
            $linii[] = [
                'price' => $r2($l['price'] ?? 0), 'line_total' => $r2($l['line_total'] ?? 0), 'vat_value' => $r2($l['vat_value'] ?? 0),
                'coupon_discount' => $r2($l['coupon_discount'] ?? 0), 'line_total_after_coupon' => $r2($l['line_total_after_coupon'] ?? 0),
                'bbd_key' => (string) ($l['bbd_key'] ?? ''),
            ];
        }
        $rezumat = [
            'subtotal' => $r2($s['subtotal'] ?? 0), 'subtotal_without_vat' => $r2($s['subtotal_without_vat'] ?? 0),
            'discount' => $r2($s['discount'] ?? 0), 'points_discount' => $r2($s['points_discount'] ?? 0), 'vat' => $r2($s['vat'] ?? 0),
            'vat_additional' => $r2($s['vat_additional'] ?? 0), 'shipping' => $r2($s['shipping'] ?? 0), 'total' => $r2($s['total'] ?? 0),
            'cupon_ok' => ($s['coupon_error'] ?? null) === null,
            'puncte_aplicate' => (int) (is_array($s['points'] ?? null) ? ($s['points']['applied'] ?? 0) : 0),
            'linii' => $linii,
        ];

        // Transportul recalculat din admin, pe comanda salvată (reducerea = cupon + puncte + reducerea manuală).
        $reducere = round((float) ($caz['discount_total'] ?? 0), 2) + round((float) ($caz['puncte_discount'] ?? 0), 2)
            + round((float) ($caz['manual'] ?? 0), 2);
        $judet = (string) ($caz['judet'] ?? '');
        // Comanda are un produs cu transport gratuit (products.free_shipping, WP-20 C14a)? Ca adminul (comandaAreTransportGratuit),
        // din rândurile comenzii; fără coloană, nu. Un cod fără argumentul al șaptelea (codul vechi) îl ignoră.
        $gratuitDinProdus = false;
        try {
            $gratuit = $db->prepare('SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = :id AND p.free_shipping = 1 LIMIT 1');
            $gratuit->execute(['id' => (int) ($caz['id'] ?? 0)]);
            $gratuitDinProdus = (bool) $gratuit->fetchColumn();
        } catch (Throwable $e) {
            $gratuitDinProdus = false;
        }
        $transportAdmin = \App\Support\CheckoutCalculator::adminRecalcShipping($settings, $judet, (float) ($caz['subtotal'] ?? 0), $reducere,
            (float) ($caz['transport'] ?? 0), (bool) ($caz['transport_manual'] ?? false), $gratuitDinProdus);
        $transportAdmin0 = \App\Support\CheckoutCalculator::adminRecalcShipping($settings, $judet, (float) ($caz['subtotal'] ?? 0), $reducere, 0.0,
            false, $gratuitDinProdus);
        // Codul Biovitality (WP-11b) are transportul gratuit al produsului în admin, nu în funcție: după adminRecalcShipping, fiecare
        // recalcul pune 0 dacă comanda are un astfel de produs (AdminController::comandaAreTransportGratuit, la 779e0d8 :5324, :5465,
        // :5628). Codul comun îl are în funcție (al șaptelea argument); un cod fără acel argument și cu regula în admin primește aici
        // exact ce face adminul lui.
        if ($gratuitDinProdus && ($cerere['profil'] ?? '') === 'biovitality'
            && (new ReflectionMethod(\App\Support\CheckoutCalculator::class, 'adminRecalcShipping'))->getNumberOfParameters() < 7) {
            $transportAdmin = 0.0;
            $transportAdmin0 = 0.0;
        }

        // Punctele promise („Primești X puncte”): din sumar, ca în coș, și din comanda salvată.
        $eligibil = max(0.0, (float) ($s['subtotal'] ?? 0) - (float) ($s['discount'] ?? 0) - (float) ($s['points_discount'] ?? 0));
        $eligibilSalvat = max(0.0, (float) ($caz['subtotal'] ?? 0) - (float) ($caz['discount_total'] ?? 0) - (float) ($caz['puncte_discount'] ?? 0));

        // Ce pleacă în ERP pentru comanda salvată.
        $comanda->execute(['id' => (int) ($caz['id'] ?? 0)]);
        $rand = $comanda->fetch(PDO::FETCH_ASSOC);
        $erp = null;
        if (is_array($rand)) {
            $payload = \App\Support\ErpSync::buildPayload($db, $rand);
            $chei = [];
            foreach ($payload as $cheie => $valoare) {
                $chei[(string) $cheie] = $sha($valoare);
            }
            ksort($chei, SORT_STRING);
            $erp = ['sha1' => $sha($payload), 'chei' => $chei];
        }

        $scrie([
            'i' => $i,
            'rezumat' => $rezumat,
            'transport_admin' => $r2($transportAdmin),
            'transport_admin_0' => $r2($transportAdmin0),
            'puncte_estimate' => \App\Support\LoyaltyService::estimateEarnPoints($settings, $eligibil),
            'puncte_estimate_salvat' => \App\Support\LoyaltyService::estimateEarnPoints($settings, $eligibilSalvat),
            'erp' => $erp,
        ]);
    } catch (Throwable $e) {
        $scrie(['i' => $i, 'eroare' => get_class($e) . ' în ' . $loc($e->getFile(), $e->getLine())]);
    }
}
$_SESSION = [];
ksort($avertismente);
$scrie(['gata' => true, 'cazuri' => $numar, 'avertismente' => $avertismente]);
$incheie(0);
