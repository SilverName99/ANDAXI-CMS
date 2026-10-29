<?php

declare(strict_types=1);

/**
 * O bază de dezvoltare făcută „ca înainte de migrări” (WP-05): cum arată baza unui site care a mers
 * pe codul de dinainte, adică exact ce primesc la adoptare cele patru site-uri. Pe baza de test
 * goală din mediul lui tools/dev/php.sh (CMS_DEV_DB, doar cms_*):
 *
 *  1. `scripts/install.php` de la commit-ul dinaintea migrărilor (cel din capul fixture-ului
 *     tools/dev/migrari/vechi/cms.sql, „-- sursa: ANDAXI-CMS@<commit>”), cu `database/schema.sql` de
 *     acolo: schema.sql, ALTER-urile lui, setările implicite, adminul, lista implicită de newsletter;
 *     setările implicite sunt cele ale codului de atunci (Settings::DEFAULTS de la commit, WP-16), nu ale
 *     codului de acum;
 *  2. fiecare funcție ensure* și asigura* a codului de acum, o trecere, ca la primele pagini ale unui
 *     site folosit (pe o bază fără _platforma_stare, garda le lasă să lucreze ca înainte): tabelele
 *     și coloanele făcute din mers, categoriile de blog ale codului vechi, pagina /acorduri-gdpr,
 *     rolurile adminilor aduse la zi.
 *
 * Apoi `tools/dev/instaleaza.sh --pe-vechi` pune seed-ul, datele demo și licența și rulează
 * baseline-ul (`scripts/migreaza.php --aplica`): pe baza asta se dovedește că Bioscem rămâne la fel
 * și pe o bază veche trecută prin migrări (comparația cu Bioscem, testele pluginurilor, SEO, fum).
 * Funcțiile codului de acum, nu ale commit-ului vechi: corpurile lor nu s-au schimbat (doar garda din
 * capul lor), iar `genereaza-fixture.php --verifica` cere ca schema făcută așa să fie aceeași cu a
 * codului vechi.
 *
 *   tools/dev/php.sh tools/dev/migrari/pe-vechi.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 3);
require_once $radacina . '/bootstrap.php';

use App\Http\Controllers\AdminController;
use App\Http\Controllers\SiteController;
use App\Support\AdminActivityLog;
use App\Support\BtIpayPayments;
use App\Support\CheckoutCalculator;
use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\ErpSync;
use App\Support\FanLockers;
use App\Support\FanNomenclator;
use App\Support\LoyaltyService;
use App\Support\Migrari\Garda;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\PaymentLink;
use App\Support\Precomanda;
use App\Support\ProductCategories;

$opreste = static function (string $mesaj): never {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

$config = require $radacina . '/config/app.php';
$numeBaza = (string) ($config['db']['database'] ?? '');
if (preg_match('/^cms_[a-z0-9_]+$/', $numeBaza) !== 1) {
    $opreste("baza '" . $numeBaza . "' nu începe cu cms_; pe-vechi.php rulează doar pe baze de test.");
}
$db = Database::connection((array) $config['db']);
if (!$db instanceof PDO) {
    $opreste('nu mă pot conecta la baza ' . $numeBaza . '.');
}
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() > 0) {
    $opreste('baza ' . $numeBaza . ' nu e goală (tools/dev/instaleaza.sh --pe-vechi o face din nou).');
}

// Commit-ul dinaintea migrărilor: sursa fixture-ului codului comun pe vechi (cms.sql, WP-04).
$cap = (string) @file_get_contents($radacina . '/tools/dev/migrari/vechi/cms.sql', false, null, 0, 2000);
if (preg_match('/^-- sursa: ANDAXI-CMS@([0-9a-f]{7,40})$/m', $cap, $m) !== 1) {
    $opreste('nu găsesc „-- sursa: ANDAXI-CMS@<commit>” în capul lui tools/dev/migrari/vechi/cms.sql.');
}
$commit = $m[1];
$dosar = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/') . '/pe-vechi-' . $commit;
@mkdir($dosar, 0770, true);
foreach (['scripts/install.php' => 'install.php', 'database/schema.sql' => 'schema.sql'] as $din => $in) {
    exec('git -C ' . escapeshellarg($radacina) . ' show ' . escapeshellarg($commit . ':' . $din) . ' > ' . escapeshellarg($dosar . '/' . $in)
        . ' 2>/dev/null', $_, $cod);
    if ($cod !== 0 || filesize($dosar . '/' . $in) < 100) {
        $opreste('git show ' . $commit . ':' . $din . ' n-a mers (repo-ul are commit-ul?).');
    }
}

// 1. install.php de atunci, cu bootstrap-ul și configurarea de acum (baza din mediul de dezvoltare).
$cod = (string) file_get_contents($dosar . '/install.php');
$inlocuiri = [
    "require_once __DIR__ . '/../bootstrap.php';" => '',
    "require __DIR__ . '/../config/app.php'" => 'require ' . var_export($radacina . '/config/app.php', true),
    "__DIR__ . '/../database/schema.sql'" => var_export($dosar . '/schema.sql', true),
];
foreach ($inlocuiri as $din => $in) {
    if (!str_contains($cod, $din)) {
        $opreste('install.php de la ' . $commit . ' nu mai are forma știută (lipsește ' . $din . ').');
    }
    $cod = str_replace($din, $in, $cod);
}
$cod = (string) preg_replace('/^<\?php/', '', $cod);
$cod = (string) preg_replace('/declare\(strict_types=1\);/', '', $cod);
echo '== install.php de la ', $commit, " (înainte de migrări)\n";
ob_start();
try {
    // Într-o funcție: variabilele lui install.php ($db, $config …) nu le ating pe cele de aici.
    (static function (string $__cod): void {
        eval($__cod);
    })($cod);
} catch (Throwable $e) {
    ob_end_flush();
    $opreste('install.php de la ' . $commit . ': ' . get_class($e) . ': ' . $e->getMessage());
}
$iesire = (string) ob_get_clean();
echo $iesire;
if (!str_contains($iesire, 'Install finalizat')) {
    $opreste('install.php de la ' . $commit . ' nu a spus „Install finalizat”.');
}

// 1b. Setările implicite ale codului de atunci. install.php de atunci scria `Settings::all()`, iar aici rulează cu clasele
//     de acum: din WP-16 implicitele codului comun nu mai sunt ale codului vechi (fereastra de bun venit, destinatarii
//     formularului de contact, numele expeditorului …), deci baza ar arăta ca a unui site nou, nu ca a unui site vechi.
//     Rândurile se fac ca ale codului de atunci: Settings::DEFAULTS de la commit, citit de tools/inghetare/lib/citeste-cod.php
//     (proces separat), scris peste ce a scris install.php; cheile pe care codul de atunci nu le avea se scot.
@mkdir($dosar . '/cod/src/Support', 0770, true);
exec('git -C ' . escapeshellarg($radacina) . ' show ' . escapeshellarg($commit . ':src/Support/Settings.php') . ' > '
    . escapeshellarg($dosar . '/cod/src/Support/Settings.php') . ' 2>/dev/null', $_, $codSetari);
$citire = $codSetari === 0 ? json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($radacina . '/tools/inghetare/lib/citeste-cod.php') . ' ' . escapeshellarg($dosar . '/cod') . ' 2>&1'), true) : null;
if (!is_array($citire['implicite'] ?? null) || count($citire['implicite']) < 100) {
    $opreste('nu pot citi Settings::DEFAULTS de la ' . $commit . ' (tools/inghetare/lib/citeste-cod.php).');
}
$impliciteVechi = (array) $citire['implicite'];
$scrieSetare = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
foreach ($impliciteVechi as $cheie => $valoare) {
    $scrieSetare->execute([(string) $cheie, (string) $valoare]);
}
$scoateSetare = $db->prepare('DELETE FROM settings WHERE `key` = ?');
$scoase = 0;
foreach (array_keys((new ReflectionClassConstant(\App\Support\Settings::class, 'DEFAULTS'))->getValue()) as $cheie) {
    if (!array_key_exists($cheie, $impliciteVechi)) {
        $scoateSetare->execute([$cheie]);
        $scoase += $scoateSetare->rowCount();
    }
}
echo '== Setările implicite de la ', $commit, ': ', count($impliciteVechi), ' scrise, ', $scoase, " chei noi scoase\n";

// 2. Funcțiile ensure*/asigura* ale codului de acum, o trecere (pe o bază fără registru lucrează ca înainte).
if (Garda::stare($db) !== Garda::FARA_REGISTRU) {
    $opreste('baza are _platforma_stare (garda: ' . Garda::stare($db) . '); funcțiile ensure* n-ar mai face nimic.');
}
/** O metodă privată a unui controller (doar aici, în unealta de test). */
$cheamaPrivat = static function (object $obiect, string $metoda, PDO $db): void {
    $m = new ReflectionMethod($obiect, $metoda);
    $m->setAccessible(true);
    $m->invoke($obiect, $db);
};
echo "== Funcțiile ensure*/asigura* (tabelele și coloanele făcute din mers)\n";
$admin = new AdminController();
$site = new SiteController();
foreach (['ensureOptionalSchema', 'ensurePromoSchema', 'ensureCouponsSchema', 'ensureFormSecurityLogsSchema',
          'ensureFanLocalitiesSchema', 'ensureFanStreetsSchema', 'ensureFanLocalitiesExtraKmSchema'] as $metoda) {
    $cheamaPrivat($admin, $metoda, $db);
}
foreach (['ensureStripeSchema', 'ensureOptionalPageSchema', 'ensureContactFormsSchema', 'ensureGdprAgreementsSchema',
          'ensureCheckoutAntiBotSchema', 'ensureRegisterAntiBotSchema', 'ensureFanLocalitiesSchema',
          'ensureCustomerSchema', 'ensureProductCustomSchema'] as $metoda) {
    $cheamaPrivat($site, $metoda, $db);
}
AdminActivityLog::ensureSchema($db);
BtIpayPayments::ensureSchema($db);
CheckoutCalculator::ensureOrderShippingSchema($db);
CheckoutCalculator::ensureProductVatSchema($db);
CheckoutCalculator::ensureCouponsSchema($db);
EmailAutomation::ensureSchema($db);
ErpSync::ensureSchema($db);
FanLockers::ensureSchema($db);
FanNomenclator::asiguraSchema($db);
LoyaltyService::ensureSchema($db);
NewsletterService::ensureSchema($db);
OrderMailer::ensureEmailSendHistorySchema($db);
PaymentLink::ensureSchema($db);
Precomanda::ensureSchema($db);
ProductCategories::ensureSchema($db);

$tabele = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
$coloane = (int) $db->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
echo 'pe-vechi: baza ', $numeBaza, ' făcută ca înainte de migrări (install.php de la ', $commit, ' + funcțiile ensure*): ',
    $tabele, ' tabele, ', $coloane, " coloane; garda: fara_registru\n";
