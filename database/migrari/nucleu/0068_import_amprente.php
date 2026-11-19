<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Amprentele importului unui site WordPress (corectura 1.9, App\Support\ImportWordPress\Amprente): pentru fiecare
 * rând scris de import (o pagină, rândul ei SEO, o setare) hash-ul conținutului de la import. La reimport, un rând al
 * cărui hash de acum diferă (editat de om după import) e refuzat fără `--suprascrie`, cu lista lui în plan.
 *
 *  - `import_amprente`: `fel` (pagina, seo, setare), `cheie` (id-ul paginii, al paginii rândului SEO, cheia setării),
 *    `hash` (sha256 al valorilor scrise), `wp_id` (postarea WordPress din care vine, unde e una), `scris_la`.
 *
 * Pornește gol și rămâne gol pe site-urile care nu vin din WordPress; nu se vede nimic pe site.
 */
return [
    'descriere' => 'Amprentele importului WordPress: hash-ul fiecărui rând scris, ca reimportul să nu scrie peste munca oamenilor',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('import_amprente', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'fel' => 'VARCHAR(20) NOT NULL',
            'cheie' => 'VARCHAR(190) NOT NULL',
            'hash' => 'CHAR(64) NOT NULL',
            'wp_id' => 'BIGINT UNSIGNED NULL',
            'scris_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_import_amprente (fel, cheie)',
        ]);
    },
];
