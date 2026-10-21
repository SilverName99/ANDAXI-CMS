<?php

declare(strict_types=1);

/**
 * Diagnostic pentru rulările din cron.
 *
 * Cand un script de cron spune ca o setare lipseste, desi in admin ea se vede
 * completata, intrebarea reala e: cronul citeste aceeasi baza de date ca
 * site-ul? Scriptul asta raspunde — arata ce fisier .env a gasit, la ce baza
 * s-a conectat si ce chei are efectiv in tabelul `settings`.
 *
 * NU afiseaza valori sensibile: pentru parole si chei API scrie doar daca sunt
 * completate si cate caractere au.
 *
 * Arată și licența: dacă `licenta.json` / `licenta-buna.json` se verifică cu
 * cheia publică și site-ul din .env și ce stări dau pluginurilor. Înainte de a
 * urca o versiune nouă de cod pe un site, rulează `--licenta`: iese cu 1 dacă
 * nicio licență nu se verifică (atunci site-ul ar merge doar pe ultima licență
 * aplicată sau, pe un site nou, doar cu nucleul).
 *
 * Arată și versiunea de PHP a liniei de comandă față de minimul cerut de cod
 * (`php_min` din config/app.php) și iese cu 1 sub el. Versiunea site-ului (web) poate
 * fi alta: pe Hostinger se vede în hPanel → PHP Configuration.
 *
 * Rulare:
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php --licenta    # doar licența și PHP-ul (verificarea dinaintea unui deploy)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// .env-ul site-ului (App\Support\Paths: azi în rădăcina codului, în Etapa 2 în dosarul site-ului), văzut înainte
// ca bootstrap.php să-l citească.
require_once __DIR__ . '/../src/Support/Paths.php';
$envPath = App\Support\Paths::env();
$envExista = is_file($envPath);
$envCitibil = $envExista && is_readable($envPath);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\Licenta;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\Settings;

$config = require __DIR__ . '/../config/app.php';

// PHP-ul minim pe care trebuie să meargă codul (config/app.php → php_min).
$phpMin = (string) ($config['php_min'] ?? '8.3');
$phpPreaVechi = version_compare(PHP_VERSION, $phpMin, '<');
$arataPhp = static function () use ($phpMin, $phpPreaVechi): void {
    echo 'versiune PHP (CLI)  : ' . PHP_VERSION . ', minim ' . $phpMin
        . ($phpPreaVechi ? ' -> PREA VECHE: codul nu e făcut pentru ea (config/app.php php_min)' : ' -> ok') . "\n";
};

/** Licența: se verifică acum? Întoarce true dacă da. */
$arataLicenta = static function (): bool {
    echo "=== Licență ===\n";
    echo 'dosar        : ' . Licenta::dosar() . "\n";
    echo 'site (.env)  : ' . (Licenta::site() !== '' ? Licenta::site() : 'LIPSESTE (LICENTA_SITE)') . "\n";
    $rezultat = Licenta::incarca();
    $licenta = $rezultat['licenta'];
    if ($licenta !== null) {
        echo 'licenta      : ok, ' . $licenta['site'] . ' v' . $licenta['versiune'] . ' din ' . $licenta['emisa_la']
            . ' (' . (string) $rezultat['sursa'] . ")\n";
    } else {
        echo "licenta      : NU SE VERIFICA\n";
    }
    foreach ($rezultat['probleme'] as $problema) {
        echo '  problema   : ' . $problema . "\n";
    }
    if (Plugins::licentaNeverificata()) {
        echo "  site-ul merge pe ultima licenta aplicata (stare-aplicata.json)\n";
    } elseif (Plugins::licentaLipseste()) {
        echo "  site-ul merge DOAR CU NUCLEUL (magazinul, platile si cronurile lor sunt oprite)\n";
    }
    $pornite = [];
    foreach (Plugins::stari() as $cheie => $stare) {
        if ($stare !== 'oprit') {
            $pornite[] = $cheie . ($stare === 'se_opreste' ? ' (se opreste)' : '');
        }
    }
    echo 'pluginuri    : ' . ($pornite === [] ? 'doar nucleul' : implode(', ', $pornite)) . "\n";
    return $licenta !== null;
};

if (in_array('--licenta', $argv, true)) {
    $licentaOk = $arataLicenta();
    $arataPhp();
    exit($licentaOk && !$phpPreaVechi ? 0 : 1);
}

echo "=== Mediu ===\n";
echo 'radacina aplicatiei : ' . Paths::radacina() . "\n";
echo 'fisier .env         : ' . ($envExista ? $envPath : 'LIPSESTE') . "\n";
echo 'citibil             : ' . ($envCitibil ? 'da' : 'NU (cronul ruleaza cu alt utilizator?)') . "\n";
echo 'dosarul storage     : ' . Paths::stocare() . "\n";
echo 'jurnal erori PHP    : ' . ((string) ini_get('error_log') !== '' ? (string) ini_get('error_log') : 'al serverului (fara fisier ales)') . "\n";
echo 'utilizator sistem   : ' . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'necunoscut') . "\n";
$arataPhp();

echo "\n=== Baza de date ===\n";
echo 'host      : ' . (string) ($config['db']['host'] ?? '') . "\n";
echo 'baza      : ' . ((string) ($config['db']['database'] ?? '') !== '' ? (string) $config['db']['database'] : 'GOALA (.env necitit)') . "\n";
echo 'utilizator: ' . (string) ($config['db']['username'] ?? '') . "\n";

$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    echo "conectare : ESUATA\n";
    exit(1);
}
echo "conectare : ok\n";

try {
    $numeReal = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    echo 'baza activa: ' . $numeReal . "\n";
} catch (Throwable $e) {
    echo 'baza activa: eroare (' . $e->getMessage() . ")\n";
}

echo "\n=== Setari ===\n";
try {
    $total = (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    echo 'randuri in tabelul settings: ' . $total . "\n";
} catch (Throwable $e) {
    echo 'tabelul settings: eroare (' . $e->getMessage() . ")\n";
    exit(1);
}

$settings = Settings::all($db);

// Cheile pe care se sprijina cronurile. Valorile sensibile nu se afiseaza.
$deVerificat = [
    'fan_client_id' => 'public',
    'fan_api_username' => 'public',
    'fan_api_password' => 'secret',
    'email_delivery_method' => 'public',
    'smtp_host' => 'public',
    'smtp_password' => 'secret',
    'sendgrid_api_key' => 'secret',
    'erp_api_key' => 'secret',
];

foreach ($deVerificat as $cheie => $fel) {
    $valoare = trim((string) ($settings[$cheie] ?? ''));
    if ($valoare === '') {
        printf("  %-24s NECOMPLETAT\n", $cheie);
        continue;
    }
    printf(
        "  %-24s %s\n",
        $cheie,
        $fel === 'secret' ? 'completat (' . strlen($valoare) . ' caractere)' : $valoare
    );
}

echo "\n=== Comenzi cu AWB ===\n";
try {
    $cuAwb = (int) $db->query(
        'SELECT COUNT(*) FROM orders WHERE fan_awb IS NOT NULL AND fan_awb <> "" AND deleted_at IS NULL'
    )->fetchColumn();
    echo 'comenzi cu AWB: ' . $cuAwb . "\n";
} catch (Throwable $e) {
    echo 'comenzi: eroare (' . $e->getMessage() . ")\n";
}

echo "\n";
$licentaOk = $arataLicenta();

echo "\nGata.\n";
exit($licentaOk && !$phpPreaVechi ? 0 : 1);
