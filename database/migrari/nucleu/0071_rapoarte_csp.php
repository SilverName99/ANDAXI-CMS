<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Rapoartele CSP ale cadrului capturat (adoptarea WordPress, pasul 9a; INSTRUCTIUNI-BUILDER-C2 §9a, lecția CB-05): numărătorile pe
 * zi ale încălcărilor politicii, primite la `/_andaxi/raport` (App\Support\RaportCsp). Un rând = o zi și cinci câmpuri tăiate la
 * 200 de caractere (documentul, directiva, adresa blocată, fișierul sursă, linia), cu numărul de rapoarte. Fără IP, fără agent,
 * fără cookie: nimic despre vizitator. Le arată Acasă → sănătatea și `diagnostic.php --capturat`.
 *
 * Pornește gol și rămâne gol pe site-urile fără cadrul capturat; nu se vede nimic pe site.
 */
return [
    'descriere' => 'Rapoartele CSP ale cadrului capturat: numărătorile pe zi, fără nimic despre vizitator',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('rapoarte_csp', [
            'zi' => 'DATE NOT NULL',
            'cheie' => 'CHAR(64) NOT NULL',
            'document' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'directiva' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'blocat' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'sursa' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'linie' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'numar' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'ultimul_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (zi, cheie)',
        ]);
    },
];
