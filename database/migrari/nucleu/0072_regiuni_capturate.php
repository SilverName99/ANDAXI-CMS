<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Regiunile dinamice ale documentelor capturate (pista A a mutării unui site WooCommerce pe drumul capturat; docs/MAGAZIN-WC.md,
 * docs/DEPLOY.md, „Regiunile dinamice”): un rând = o regiune a unui document (`document` = `pagini_capturate.cheie`; `regiune` =
 * id-ul randatorului din lista închisă App\Support\CadruCapturat\Regiuni::CATALOG; `n` = a câta regiune cu acel randator în document),
 * cu felul ei (`fel`: `interior` sau `atribut`, cu `atribut`), octeții capturați (`octeti`, cu `sha256`: dovada randatorului și ce
 * pune cadrul la P1) și starea pe care o arată captura (`stare`, JSON: de exemplu coșul cu un produs). Le scrie importul
 * (App\Support\ImportWordPress\ScriereCapturata, rescrise întregi la fiecare import), le citește cadrul (App\Support\CadruCapturat).
 *
 * Tot din pista A, `pagini_capturate.tip` primește valorile `produs`, `sablon_articol`, `sablon_categorie` și `sablon_cautare`
 * (Divi4Taiere::TIPURI): coloana e un VARCHAR(20) (nucleu/0070), deci valorile nu cer nicio schimbare de schemă; le scrie și le
 * citește doar codul.
 *
 * Pornește gol și rămâne gol pe site-urile fără regiuni (toate cele de azi); nu se vede nimic pe site.
 */
return [
    'descriere' => 'Regiunile dinamice ale documentelor capturate: octeții capturați ai fiecărei regiuni, cu starea capturii',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('regiuni_capturate', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'document' => 'VARCHAR(190) NOT NULL',
            'regiune' => 'VARCHAR(60) NOT NULL',
            'n' => 'SMALLINT UNSIGNED NOT NULL',
            'fel' => 'VARCHAR(20) NOT NULL',
            'atribut' => 'VARCHAR(60) NULL',
            'octeti' => 'MEDIUMBLOB NOT NULL',
            'sha256' => 'CHAR(64) NOT NULL',
            'stare' => 'TEXT NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_regiuni_capturate (document, regiune, n)',
        ]);
    },
];
