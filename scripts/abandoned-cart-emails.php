<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\Settings;

// Cronul e al unui plugin (config/plugins.php). Dacă licența site-ului nu-l
// are (sau, la cronurile care pornesc ceva nou, dacă pluginul se oprește),
// nu face nimic.
if (!\App\Support\Plugins::cronPermis(__FILE__)) {
    // Ieșire 0 când licența oprește pluginul; 2 când site-ul n-are licență (de văzut în monitorizare).
    [$mesajCron, $codCron] = \App\Support\Plugins::cronOprit(__FILE__);
    echo $mesajCron . "\n";
    exit($codCron);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$limit = 100;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--limit=')) {
            $candidate = (int) substr((string) $argument, 8);
            if ($candidate > 0 && $candidate <= 1000) {
                $limit = $candidate;
            }
        }
    }
}

EmailAutomation::ensureSchema($db);
$settings = Settings::all($db);
// WP-21 (C10, OD-15): adresele IP ale recenziilor mai vechi de recenzii_ip_zile se golesc și aici, la fiecare rulare a
// cronului magazinului, nu doar la o recenzie nouă sau la lista din admin (un site fără recenzii noi și fără admin
// deschis le-ar păstra altfel peste termen). Fără ieșire; tace pe o bază fără coloana ip_address.
if (\App\Support\Plugins::vizibil('recenzii')) {
    \App\Support\ReviewGuard::curataAdreseVechi($db, $settings);
}
$result = EmailAutomation::sendDueAbandonedCartEmails($db, $settings, $limit);

echo 'Abandoned cart email sync: ';
echo 'total=' . (int) ($result['total'] ?? 0) . ', ';
echo 'sent=' . (int) ($result['sent'] ?? 0) . ', ';
echo 'failed=' . (int) ($result['failed'] ?? 0) . "\n";
