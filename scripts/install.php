<?php

declare(strict_types=1);

/**
 * Instalarea unui site nou, pe o bază goală (docs/MIGRARI.md, „Instalarea”):
 *  1. migrările, exact ca `scripts/migreaza.php --aplica` (aceeași conexiune, același runner):
 *     schema completă, pentru orice pluginuri;
 *  2. seed-ul neutru: categoriile de blog „Noutăți” și „Evenimente” (NextEvent caută slug-ul
 *     „evenimente”), doar într-un tabel gol; nimic din conținutul Bioscem;
 *  3. toate setările implicite scrise în bază (Settings::DEFAULTS);
 *  4. pașii de activare ai pluginurilor pornite de licență (config/activari.php: pagina
 *     /acorduri-gdpr, pragul ERP);
 *  5. la urmă primul administrator (ADMIN_DEFAULT_EMAIL, parola din ADMIN_DEFAULT_PASSWORD).
 *
 * Doar pe o bază fără administratori. Pe una care are deja (un site în lucru, un site vechi care
 * trece pe codul comun) refuză și iese cu 1, fără să scrie nimic: acolo schema se aduce la zi cu
 * `scripts/migreaza.php --aplica`, iar setările rămân ale site-ului (scrierea setărilor implicite
 * peste ele, înaintea înghețării lor, ar schimba ce vede clientul; DB-23). Administratorul se scrie
 * ultimul, deci o instalare oprită la jumătate (de exemplu la capătul bugetului de timp al
 * migrărilor) se rulează din nou și continuă.
 *
 * Coduri de ieșire: 0 instalat; 1 orice problemă, cu mesajul pe stderr (fără parolă, PHP prea
 * vechi, baza nu răspunde, baza există deja, o migrare n-a mers, un fișier lipsă).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Activari;
use App\Support\Auth;
use App\Support\Migrari\Consola;
use App\Support\Migrari\Eroare;
use App\Support\Migrari\Garda;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Runner;
use App\Support\Plugins;
use App\Support\Settings;

$radacina = dirname(__DIR__);
$config = require $radacina . '/config/app.php';

$esec = static function (string $mesaj): never {
    fwrite(STDERR, rtrim($mesaj) . "\n");
    exit(1);
};

// Parola primului administrator vine doar din .env. Înainte exista o parolă de
// rezervă scrisă în cod, aceeași pe toate site-urile; acum instalarea refuză
// să pornească fără parola proprie, înainte să atingă baza de date.
$parolaAdmin = (string) ($config['admin']['default_password'] ?? '');
if (trim($parolaAdmin) === '') {
    $esec('Lipsește ADMIN_DEFAULT_PASSWORD în .env. Instalarea nu pornește fără parola primului administrator.');
}
if (strlen($parolaAdmin) < 10) {
    $esec('ADMIN_DEFAULT_PASSWORD e prea scurtă (minim 10 caractere). Instalarea nu pornește.');
}
$phpMin = (string) ($config['php_min'] ?? '8.3');
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    $esec('PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . ', config/app.php php_min). Instalarea nu pornește.');
}

try {
    $site = Consola::site(null);
    [$db, $server] = Consola::conecteaza((array) ($config['db'] ?? []));
} catch (Eroare $e) {
    $esec($e->getMessage() . ' Instalarea nu a pornit.');
}

// O bază care are deja administratori nu se instalează din nou (DB-23).
$areAdmini = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins'")->fetchColumn() > 0
    && (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
if ($areAdmini) {
    $esec('Baza există (are administratori); nu o instalez din nou și n-am scris nimic. '
        . 'Schema se aduce la zi cu: php scripts/migreaza.php --aplica (docs/MIGRARI.md).');
}

// 1. Migrările.
$registru = new Registru(Consola::dosarMigrari($radacina), array_merge(['nucleu'], Plugins::chei()));
$runner = new Runner($db, $server, $registru, Consola::optiuniDinMediu($radacina, ['site' => $site]));
$raport = $runner->aplica();
if ((int) $raport['cod'] !== 0) {
    $esec(Consola::text($raport) . ((int) $raport['cod'] === Eroare::BUGET
        ? 'Instalarea nu e terminată: rulează din nou php scripts/install.php (continuă de unde a rămas).'
        : 'Instalarea s-a oprit la migrări (raportul de mai sus).'));
}
echo 'Migrări: ', $raport['mesaj'], "\n";
// Baza e acum la zi: garda nu are voie să-și amintească o stare citită înainte de migrări (pașii de
// activare de la pasul 4 rulează doar pe o bază la zi).
Garda::uita();

// 2. Seed-ul neutru: doar într-un tabel gol (o categorie ștearsă nu reapare la o rulare din nou).
if ((int) $db->query('SELECT COUNT(*) FROM blog_categories')->fetchColumn() === 0) {
    $categorie = $db->prepare('INSERT IGNORE INTO blog_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)');
    foreach ([['Noutăți', 'noutati', 1], ['Evenimente', 'evenimente', 2]] as [$nume, $slug, $ordine]) {
        $categorie->execute(['name' => $nume, 'slug' => $slug, 'sort_order' => $ordine]);
    }
}

// 3. Setările implicite, scrise în bază (cele scrise deja de o rulare întreruptă rămân).
Settings::save($db, Settings::all($db));

// 4. Pașii de activare ai pluginurilor pornite de licența de acum.
foreach (Activari::ruleaza($db) as $pas) {
    echo 'Activare ', $pas['plugin'], ': ', $pas['rezultat'], "\n";
}

// 5. Primul administrator, la urmă: rolul scris deja în forma de azi (role + roles_json), cum îl
// aducea codul vechi la prima pagină de admin și cum îl scrie Setări → Administratori.
$email = strtolower(trim((string) $config['admin']['default_email']));
$roluri = json_encode([Auth::ROLE_GENERAL], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$insert = $db->prepare('INSERT INTO admins (email, password_hash, role, roles_json) VALUES (:email, :password_hash, :role, :roles_json)');
$insert->execute([
    'email' => $email,
    'password_hash' => password_hash($parolaAdmin, PASSWORD_BCRYPT),
    'role' => Auth::ROLE_GENERAL,
    'roles_json' => $roluri,
]);

echo "Install finalizat cu succes.\n";
echo "Admin: {$email}\n";
