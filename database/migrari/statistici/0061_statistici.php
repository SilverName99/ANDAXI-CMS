<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Statisticile site-ului (pluginul `statistici`, App\Support\Statistici; docs/PLUGINURI.md, „Statistici site”): analiza proprie a
 * vizitelor, fără cookie-uri și fără nimic ținut în browser.
 *
 *  - `statistici_evenimente`: evenimentele brute (o pagină văzută, plecarea de pe ea cu timpul activ, adăugarea în coș, începutul
 *    comenzii, comanda, o căutare pe site), cu vizitatorul ca un hash zilnic (16 octeți; sarea zilei se șterge a doua zi) și
 *    cheia limitei (8 octeți: sarea zilei + adresa, IPv6 pe /64), fără adresă IP, fără User-Agent, fără id de comandă (doar familia browserului, a sistemului și felul dispozitivului). Se șterg după
 *    `statistici_zile_brute` zile (implicit 90);
 *  - `statistici_vizite`, `statistici_pagini`, `statistici_produse`, `statistici_cautari`, `statistici_comenzi`: agregatele unei
 *    zile (cronul la oră și deschiderea paginii din admin), păstrate. În vizite, vizitatorul e doar un număr de ordine al zilei;
 *  - `statistici_zile`: ce zi s-a agregat și dacă e încheiată;
 *  - `orders.statistici_vizitator`: hash-ul zilnic al vizitatorului care a plasat comanda, ca venitul să se lege de sursa vizitei;
 *    se golește când ziua comenzii e încheiată (atribuirea rămâne în `statistici_comenzi`).
 *
 * Doar adăugări; coloana de pe `orders` e NULL (comenzile se scriu exact ca înainte, un UPDATE separat o completează). Fără date.
 */
return [
    'descriere' => 'Statisticile site-ului: evenimentele, agregatele zilnice și legătura comenzii cu vizita (statistici)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('statistici_evenimente', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'creat_la' => 'DATETIME NOT NULL',
            'vizitator' => 'BINARY(16) NOT NULL',
            'ip_cheie' => 'BINARY(8) NOT NULL',
            'tip' => 'TINYINT UNSIGNED NOT NULL',
            'cale' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'tip_pagina' => "VARCHAR(12) NOT NULL DEFAULT ''",
            'produs_id' => 'INT UNSIGNED NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'detaliu' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_sursa' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_mediu' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_campanie' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_continut' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_termen' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'dispozitiv' => "VARCHAR(8) NOT NULL DEFAULT ''",
            'browser' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'os' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'latime' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'tara' => "CHAR(2) NOT NULL DEFAULT ''",
            'durata' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'cautare' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'rezultate' => 'INT UNSIGNED NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_st_ev_timp (creat_la)',
            'KEY idx_st_ev_vizitator (vizitator, creat_la)',
            'KEY idx_st_ev_ip (ip_cheie, creat_la)',
        ]);
        $s->tabelComplet('statistici_vizite', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'zi' => 'DATE NOT NULL',
            'inceput' => 'DATETIME NOT NULL',
            'sfarsit' => 'DATETIME NOT NULL',
            'vizitator_nr' => 'INT UNSIGNED NOT NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'detaliu' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_sursa' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_mediu' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_campanie' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_continut' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_termen' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'pagina_intrare' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'pagini' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'durata' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'angajata' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'dispozitiv' => "VARCHAR(8) NOT NULL DEFAULT ''",
            'browser' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'os' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'tara' => "CHAR(2) NOT NULL DEFAULT ''",
            'adaugari_cos' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'checkout' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'comenzi' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_st_viz_zi (zi)',
            'KEY idx_st_viz_inceput (inceput)',
            'KEY idx_st_viz_sursa (sursa, zi)',
        ]);
        $s->tabelComplet('statistici_pagini', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'zi' => 'DATE NOT NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'cale' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'tip_pagina' => "VARCHAR(12) NOT NULL DEFAULT ''",
            'vizualizari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'vizitatori' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'intrari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_st_pag_zi (zi, sursa)',
        ]);
        $s->tabelComplet('statistici_produse', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'zi' => 'DATE NOT NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'produs_id' => 'INT UNSIGNED NOT NULL',
            'vizualizari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'vizitatori' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'adaugari_cos' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_st_prod_zi (zi, sursa)',
        ]);
        $s->tabelComplet('statistici_cautari', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'zi' => 'DATE NOT NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'cautare' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'cautari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'vizitatori' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'fara_rezultat' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_st_caut_zi (zi, sursa)',
        ]);
        $s->tabelComplet('statistici_comenzi', [
            'comanda_id' => 'INT UNSIGNED NOT NULL',
            'zi' => 'DATE NOT NULL',
            'creat_la' => 'DATETIME NOT NULL',
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'detaliu' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'utm_campanie' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'dispozitiv' => "VARCHAR(8) NOT NULL DEFAULT ''",
        ], [
            'PRIMARY KEY (comanda_id)',
            'KEY idx_st_com_zi (zi)',
            'KEY idx_st_com_creat (creat_la)',
        ]);
        $s->tabelComplet('statistici_zile', [
            'zi' => 'DATE NOT NULL',
            'agregat_la' => 'DATETIME NOT NULL',
            'incheiata' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (zi)',
        ]);
        $s->coloana('orders', 'statistici_vizitator', 'BINARY(16) NULL');
    },
];
