<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Paginile capturate ale unui site mutat de pe WordPress (adoptarea, drumul capturat, pasul 5a; DECIZIE §2.7 [design] 3, I-C §5):
 * documentele capturate tăiate în cele cinci bucăți, fragmentele lor comune, răspunsurile înghețate, formularele și galeriile.
 * Le scrie importul (App\Support\ImportWordPress\ScriereCapturata), le randează cadrul (App\Support\CadruCapturat).
 *
 *  - `pagini_capturate`: un document capturat (`cheie`: `pagina:<wp_id>`, `404`, `embed:<wp_id>`, `arhiva:<…>`; `tip`; `page_id`
 *    pentru o pagină, al cărei conținut e `pages.html_content`; `cale` pentru embed și arhive, cu `cale_hash` unic): bucățile
 *    (`html_atribute`, `cap_html`, `body_atribute`, `inainte`, `continut` la cele fără pagină, `dupa`), fragmentele alese
 *    (`antet`, `subsol`, `banner`), datele comportamentului (`date_pagina`, `date_reteta`), elementele de meniu proprii paginii
 *    (`meniu`), valorile de la import (`valori_import`), numele secțiunilor, starea head-ului SEO (`seo`, `seo_motiv`), antetele
 *    HTTP, corpusul căutării, amprentele (`sha_captura`, `sha_randare`, `hash_import`) și datele (`editat_la`, `importat_la`);
 *  - `fragmente_capturate`: antetele, subsolurile și bannerele, câte o variantă pe octeții normalizați (`cheie`: `<fel>:<sha>`);
 *  - `raspunsuri_inghetate`: robots, sitemap-urile, XSL-ul, feed-urile, oEmbed (metoda, calea, query-ul, statusul, antetele,
 *    corpul, sha256), cu `cheie` unică;
 *  - `formulare_compat`: formularele Divi (configurația lor: câmpurile, mesajele, destinatarul din baza WordPress);
 *  - `galerii_capturate` și `galerii_capturate_elemente`: galeriile din conținut (setările modulului, fișierele cu categoriile).
 *
 * Pornesc goale și rămân goale pe site-urile care nu vin din WordPress; nu se vede nimic pe site.
 */
return [
    'descriere' => 'Paginile capturate ale unui site mutat de pe WordPress: bucățile, fragmentele, răspunsurile înghețate, formularele, galeriile',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('pagini_capturate', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'cheie' => 'VARCHAR(190) NOT NULL',
            'tip' => 'VARCHAR(20) NOT NULL',
            'page_id' => 'INT UNSIGNED NULL',
            'cale' => 'VARCHAR(760) NULL',
            'cale_hash' => 'CHAR(64) NULL',
            'wp_id' => 'BIGINT UNSIGNED NULL',
            'dialect' => 'VARCHAR(20) NOT NULL',
            'limba' => "VARCHAR(10) NOT NULL DEFAULT ''",
            'status' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 200',
            'html_atribute' => 'TEXT NOT NULL',
            'cap_html' => 'MEDIUMTEXT NOT NULL',
            'body_atribute' => 'TEXT NOT NULL',
            'inainte' => 'MEDIUMTEXT NOT NULL',
            'continut' => 'MEDIUMTEXT NULL',
            'dupa' => 'MEDIUMTEXT NOT NULL',
            'antet' => 'VARCHAR(190) NULL',
            'subsol' => 'VARCHAR(190) NULL',
            'banner' => 'VARCHAR(190) NULL',
            'date_pagina' => 'MEDIUMTEXT NULL',
            'date_reteta' => 'TEXT NULL',
            'meniu' => 'TEXT NULL',
            'valori_import' => 'TEXT NULL',
            'sectiuni' => 'TEXT NULL',
            'seo' => "VARCHAR(20) NOT NULL DEFAULT 'inghetat'",
            'seo_motiv' => 'VARCHAR(500) NULL',
            'antete_http' => 'TEXT NULL',
            'corpus_cautare' => 'MEDIUMTEXT NULL',
            'sha_captura' => 'CHAR(64) NOT NULL',
            'sha_randare' => 'CHAR(64) NOT NULL',
            'hash_import' => 'CHAR(64) NOT NULL',
            'editat_la' => 'DATETIME NULL',
            'wp_modificat' => 'DATETIME NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_pagini_capturate_cheie (cheie)',
            'UNIQUE KEY uniq_pagini_capturate_page (page_id)',
            'UNIQUE KEY uniq_pagini_capturate_cale (cale_hash)',
        ]);
        $s->tabelComplet('fragmente_capturate', [
            'cheie' => 'VARCHAR(190) NOT NULL',
            'fel' => 'VARCHAR(20) NOT NULL',
            'layout' => "VARCHAR(40) NOT NULL DEFAULT ''",
            'limba' => "VARCHAR(10) NOT NULL DEFAULT ''",
            'html' => 'MEDIUMTEXT NOT NULL',
            'date' => 'MEDIUMTEXT NULL',
            'meniu' => 'TEXT NULL',
            'sha' => 'CHAR(64) NOT NULL',
            'hash_import' => 'CHAR(64) NOT NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (cheie)',
        ]);
        $s->tabelComplet('raspunsuri_inghetate', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'cheie' => 'CHAR(64) NOT NULL',
            'metoda' => 'VARCHAR(10) NOT NULL',
            'cale' => 'VARCHAR(760) NOT NULL',
            'query' => "VARCHAR(760) NOT NULL DEFAULT ''",
            'status' => 'SMALLINT UNSIGNED NOT NULL',
            'antete' => 'TEXT NOT NULL',
            'corp' => 'LONGBLOB NOT NULL',
            'sha256' => 'CHAR(64) NOT NULL',
            'sursa' => 'VARCHAR(40) NOT NULL',
            'hash_import' => 'CHAR(64) NOT NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_raspunsuri_inghetate_cheie (cheie)',
        ]);
        $s->tabelComplet('formulare_compat', [
            'cheie' => 'VARCHAR(190) NOT NULL',
            'pagina_capturata' => 'VARCHAR(190) NULL',
            'fragment' => 'VARCHAR(190) NULL',
            'tip' => 'VARCHAR(20) NOT NULL',
            'indice' => 'SMALLINT UNSIGNED NOT NULL',
            'limba' => "VARCHAR(10) NOT NULL DEFAULT ''",
            'config' => 'MEDIUMTEXT NOT NULL',
            'hash_import' => 'CHAR(64) NOT NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (cheie)',
        ]);
        $s->tabelComplet('galerii_capturate', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'cheie' => 'VARCHAR(190) NOT NULL',
            'pagina_capturata' => 'VARCHAR(190) NOT NULL',
            'modul' => 'VARCHAR(100) NOT NULL',
            'setari' => 'TEXT NULL',
            'hash_import' => 'CHAR(64) NOT NULL',
            'importat_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_galerii_capturate_cheie (cheie)',
        ]);
        $s->tabelComplet('galerii_capturate_elemente', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'galerie_id' => 'INT UNSIGNED NOT NULL',
            'ordine' => 'INT UNSIGNED NOT NULL',
            'fisier' => 'VARCHAR(760) NOT NULL',
            'categorii' => 'TEXT NULL',
            'titlu' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'alt' => "VARCHAR(500) NOT NULL DEFAULT ''",
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_galerii_capturate_elemente (galerie_id, ordine)',
        ]);
    },
];
