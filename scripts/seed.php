<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

// Fără bază sau fără fișier: mesajul pe stderr și codul 1, ca un script automat să nu creadă că
// a mers (înainte ieșea cu 0; „Erori moștenite” 4 din docs/DEZVOLTARE.md).
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibilă.\n");
    exit(1);
}

$seedPath = __DIR__ . '/../database/seed.sql';
$seedSql = is_readable($seedPath) ? file_get_contents($seedPath) : false;

if ($seedSql === false) {
    fwrite(STDERR, "Nu am putut citi fișierul seed.sql.\n");
    exit(1);
}

$db->exec($seedSql);
echo "Seed finalizat cu succes.\n";
