<?php

declare(strict_types=1);

/**
 * Geamănul de dezvoltare al pasului înghețării de la adoptare (planul Etapei 1, WP-14 și pachetul Biovitality; de-bioscem
 * §7.2): pune în baza de dezvoltare setările înghețate ale unui pachet de site, ca baza să arate ca a acelui site după
 * adoptare. Citește înghețarea cu același parser ca runner-ul migrărilor (App\Support\Migrari\Inghetare), deci dezvoltarea
 * și serverul nu pot înțelege fișierul diferit.
 *
 *   tools/dev/php.sh tools/dev/pachet-site.php arata site-uri/biovitality     # ce ar scrie (cheile și valorile), nimic schimbat
 *   tools/dev/php.sh tools/dev/pachet-site.php emuleaza site-uri/biovitality  # le scrie în baza de dezvoltare
 *   tools/dev/php.sh tools/dev/pachet-site.php garda site-uri/biovitality     # doar garda adreselor de mai jos, pe bază
 *
 * `emuleaza` scrie **fiecare** intrare din `setari` peste ce e în bază (o instalare nouă are deja toate implicitele
 * nucleului, deci regula „doar unde lipsește” a pasului adevărat n-ar scrie nimic), fiecare cu valoarea din fișierul din
 * git. Pentru o valoare privată (adresele, telefonul și reprezentantul de pe acordul GDPR; OD-49) asta e înlocuitorul
 * inventat al fișierului („adresa1@exemplu.ro”, „0700 000 001”, „Valoare demo”), niciodată valoarea adevărată din
 * `privat/`: baza de dezvoltare are doar date inventate (planul, §2.4). Pasul adevărat, cu modurile lui și cu valorile
 * private, e al runner-ului: `scripts/migreaza.php --aplica --site=<site>` (docs/MIGRARI.md, „Înghețarea setărilor”).
 *
 * Gărzi: doar pe o bază `cms_*` cu `APP_URL` local (ca tools/dev/date-demo.php), niciodată în baza comună `cms_dev` a
 * porților (o face instaleaza.sh --site=<site>, pe baza ei); refuză un pachet a cărui înghețare are o
 * valoare publică cu date ale unui client sau o valoare privată fără înlocuitorul ei; după scriere, nicio setare de
 * destinatar sau de expeditor (`email`, `recipient`, `notify`, `from_address` în nume) nu are voie să aibă o adresă din afara
 * @exemplu.ro (cod 1, cu cheile, fără valori). Cod de ieșire: 0 făcut, 1 garda după scriere, 2 refuzat (nimic scris).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$comanda = (string) ($argv[1] ?? '');
$dosar = (string) ($argv[2] ?? '');
$refuza = static function (string $mesaj): never {
    fwrite(STDERR, 'REFUZAT: ' . $mesaj . "\n");
    exit(2);
};
if (!in_array($comanda, ['arata', 'emuleaza', 'garda'], true) || $dosar === '' || count($argv) !== 3) {
    $refuza('folosire: tools/dev/php.sh tools/dev/pachet-site.php arata|emuleaza|garda <dosarul pachetului, de exemplu site-uri/biovitality>');
}
$dosar = rtrim(str_starts_with($dosar, '/') ? $dosar : $radacina . '/' . $dosar, '/');
$fisier = $dosar . '/setari-inghetate.json';
if (!is_file($fisier)) {
    $refuza('pachetul ' . basename($dosar) . ' n-are setari-inghetate.json');
}

require_once $radacina . '/bootstrap.php';

use App\Support\Database;
use App\Support\Migrari\Inghetare;

try {
    $inghetare = Inghetare::dinFisier($fisier);
} catch (Throwable $e) {
    $refuza('înghețarea nu se citește: ' . $e->getMessage());
}

/** @var array<string, string> $deScris */
$deScris = [];
foreach ($inghetare->setari() as $cheie => $intrare) {
    $valoare = (string) $intrare['valoare'];
    if ($intrare['privat'] && !Inghetare::eInlocuitor($valoare)) {
        $refuza('valoarea privată ' . $cheie . ' din fișierul din git nu e înlocuitorul ei inventat (OD-49)');
    }
    if (!$intrare['privat'] && Inghetare::arePrivat($valoare)) {
        $refuza('valoarea publică ' . $cheie . ' are o adresă de email din afara @exemplu.ro sau un telefon (ar trebui să fie privată)');
    }
    $deScris[(string) $cheie] = $valoare;
}
ksort($deScris, SORT_STRING);

if ($comanda === 'arata') {
    echo 'Înghețarea ' . $inghetare->site() . ' (' . $inghetare->sursa() . '): ' . count($deScris) . ' setări (valorile private: înlocuitorii din git)' . "\n";
    foreach ($deScris as $cheie => $valoare) {
        echo '  ' . str_pad($cheie, 44) . Inghetare::scurt($valoare) . "\n";
    }
    exit(0);
}

$config = require $radacina . '/config/app.php';
$numeBaza = (string) ($config['db']['database'] ?? '');
if (preg_match('/^cms_[a-z0-9_]+$/', $numeBaza) !== 1) {
    $refuza('baza „' . $numeBaza . '” nu începe cu cms_; pachet-site.php emuleaza scrie doar în baze de dezvoltare');
}
if ($comanda === 'emuleaza' && $numeBaza === 'cms_dev') {
    // Baza comună a porților (comparația cu Bioscem, pluginurile, SEO): înghețarea altui site ar strica-o pe tăcute.
    $refuza('nu scriu înghețarea unui site în baza comună cms_dev; fă-i baza ei: tools/dev/instaleaza.sh --site=' . $inghetare->site());
}
if (!str_starts_with((string) ($config['url'] ?? ''), 'http://127.0.0.1:')) {
    $refuza('APP_URL trebuie să fie http://127.0.0.1:<port> (tools/dev/comun.sh)');
}
$db = Database::connection((array) $config['db']);
if (!$db instanceof PDO) {
    $refuza('nu mă pot lega la baza ' . $numeBaza);
}
if ($comanda === 'emuleaza') {
    $scrie = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $db->beginTransaction();
    foreach ($deScris as $cheie => $valoare) {
        $scrie->execute(['k' => $cheie, 'v' => $valoare]);
    }
    $db->commit();
    echo 'Înghețarea ' . $inghetare->site() . ': ' . count($deScris) . ' setări scrise în ' . $numeBaza . ' (valorile private: înlocuitorii inventați din git)' . "\n";
}

// Garda: nicio adresă reală într-o setare de destinatar sau de expeditor (și după ce au scris alții înainte).
$rele = [];
foreach ($db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $cheie => $valoare) {
    if (preg_match('/(email|recipient|notify|from_address)/', (string) $cheie) === 1 && Inghetare::emailuriReale((string) $valoare) !== []) {
        $rele[] = (string) $cheie;
    }
}
if ($rele !== []) {
    fwrite(STDERR, 'EROARE: setări cu o adresă din afara @exemplu.ro în baza de dezvoltare: ' . implode(', ', $rele) . "\n");
    exit(1);
}
echo 'Garda adreselor: toate setările de destinatar și de expeditor sunt @exemplu.ro (sau goale)' . "\n";
exit(0);
