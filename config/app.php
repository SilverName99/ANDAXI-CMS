<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'Bioscem Shop'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => Env::get('APP_URL', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Bucharest'),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', ''),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'admin' => [
        'default_email' => Env::get('ADMIN_DEFAULT_EMAIL', 'admin@example.com'),
        // Fără parolă de rezervă în cod: instalarea o cere în .env și refuză altfel.
        'default_password' => Env::get('ADMIN_DEFAULT_PASSWORD', ''),
    ],
    // Licența cu pluginurile site-ului (vezi App\Support\Licenta și config/plugins.php).
    'licenta' => [
        // Cheia publică Ed25519 a CRM-ului (base64); mai multe, separate prin virgulă, la schimbarea cheii.
        'cheie_publica' => Env::get('LICENTA_CHEIE_PUBLICA', ''),
        // Identificatorul site-ului în CRM; licența trebuie să fie emisă exact pentru el.
        'site' => Env::get('LICENTA_SITE', ''),
        // Unde stau licenta.json și licenta-buna.json; gol = storage/licenta.
        'dosar' => Env::get('LICENTA_DOSAR', ''),
    ],
];
