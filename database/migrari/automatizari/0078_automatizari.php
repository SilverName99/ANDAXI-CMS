<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Automatizările newsletterului (pluginul `automatizari`, App\Support\Automatizari\*; docs/PLUGINURI.md, „Automatizări
 * newsletter”). Numărul 0078 e cel rezervat în planul GT (§4, „automatizari/0078_automatizari”); pe linia comună ultima
 * migrare publicată e 0067, deci nu se ciocnește cu nimic.
 *
 *  - `automatizari`: un flux: numele, starea (`ciorna`, `activa`, `oprita`), declanșatorul și setările lui (JSON), dacă un
 *    abonat poate intra din nou după ce a ieșit (`permite_reintrarea`), șablonul din care a pornit;
 *  - `automatizari_pasi`: pașii fluxului (graful): cheia pasului în flux, tipul, setările (JSON), pasul următor și, la
 *    „Dacă / altfel”, primul pas al ramurii „Da” și al ramurii „Nu”;
 *  - `automatizari_emailuri`: conținutul emailurilor (aceleași blocuri ca builderul newsletterului);
 *  - `automatizari_rulari`: trecerea unui om prin flux; cheia unică (automatizare + subiect, sau + eveniment cu reintrarea
 *    permisă) face intrarea idempotentă; `revendicata_*` e lacătul unei treceri a cronului (o rulare oprită la jumătate se
 *    reia, fără să trimită de două ori);
 *  - `automatizari_trimiteri`: fiecare email trimis de un pas (rezervat „pending” înaintea trimiterii: unic pe rulare și
 *    pas, deci niciodată de două ori), cu deschiderile și clicurile;
 *  - `automatizari_jurnal`: ce s-a întâmplat (intrări, pași, ieșiri, erori), fără adrese de email; numărătorile pe pas.
 *
 * Tabele noi pe toate site-urile (decizia OD-02), goale; cât pluginul nu e pornit, nimic nu le citește.
 */
return [
    'descriere' => 'Automatizările newsletterului (automatizari, automatizari_pasi, automatizari_emailuri, automatizari_rulari, automatizari_trimiteri, automatizari_jurnal)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('automatizari', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'nume' => 'VARCHAR(190) NOT NULL',
            'stare' => "VARCHAR(20) NOT NULL DEFAULT 'ciorna'",
            'declansator' => 'VARCHAR(40) NOT NULL',
            'setari_json' => 'LONGTEXT NULL',
            'permite_reintrarea' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sablon' => 'VARCHAR(40) NULL',
            'pornita_la' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_automatizari_stare (stare, declansator)',
        ]);
        $s->tabelComplet('automatizari_pasi', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'automatizare_id' => 'INT UNSIGNED NOT NULL',
            'cheie' => 'VARCHAR(20) NOT NULL',
            'tip' => 'VARCHAR(30) NOT NULL',
            'setari_json' => 'LONGTEXT NULL',
            'urmator' => 'VARCHAR(20) NULL',
            'da' => 'VARCHAR(20) NULL',
            'nu' => 'VARCHAR(20) NULL',
            'ordine' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_automatizari_pasi_cheie (automatizare_id, cheie)',
        ]);
        $s->tabelComplet('automatizari_emailuri', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'automatizare_id' => 'INT UNSIGNED NOT NULL',
            'nume' => 'VARCHAR(190) NOT NULL',
            'subiect' => 'VARCHAR(255) NOT NULL',
            'blocks_json' => 'LONGTEXT NULL',
            'html_content' => 'LONGTEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_automatizari_emailuri_automatizare (automatizare_id)',
        ]);
        $s->tabelComplet('automatizari_rulari', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'automatizare_id' => 'INT UNSIGNED NOT NULL',
            'cheie_unica' => 'CHAR(64) NOT NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'abonat_id' => 'INT UNSIGNED NULL',
            'utilizator_id' => 'INT UNSIGNED NULL',
            'comanda_id' => 'INT UNSIGNED NULL',
            'context_json' => 'LONGTEXT NULL',
            'stare' => "VARCHAR(20) NOT NULL DEFAULT 'activa'",
            'pas_curent' => 'VARCHAR(20) NULL',
            'pas_inceput_la' => 'DATETIME NULL',
            'urmatoarea_la' => 'DATETIME NOT NULL',
            'revendicata_la' => 'DATETIME NULL',
            'revendicata_de' => 'CHAR(32) NULL',
            'incercari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'motiv_iesire' => 'VARCHAR(40) NULL',
            'intrata_la' => 'DATETIME NOT NULL',
            'terminata_la' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_automatizari_rulari_cheie (automatizare_id, cheie_unica)',
            'KEY idx_automatizari_rulari_scadente (stare, urmatoarea_la)',
            'KEY idx_automatizari_rulari_pas (automatizare_id, stare, pas_curent)',
            'KEY idx_automatizari_rulari_email (email)',
        ]);
        $s->tabelComplet('automatizari_trimiteri', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'rulare_id' => 'INT UNSIGNED NOT NULL',
            'automatizare_id' => 'INT UNSIGNED NOT NULL',
            'pas_cheie' => 'VARCHAR(20) NOT NULL',
            'email_id' => 'INT UNSIGNED NULL',
            'abonat_id' => 'INT UNSIGNED NULL',
            'stare' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'incercari' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'eroare' => 'TEXT NULL',
            'trimis_la' => 'DATETIME NULL',
            'deschis_la' => 'DATETIME NULL',
            'deschideri' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'clic_la' => 'DATETIME NULL',
            'clicuri' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_automatizari_trimiteri_pas (rulare_id, pas_cheie)',
            'KEY idx_automatizari_trimiteri_pas (automatizare_id, pas_cheie, stare)',
            'KEY idx_automatizari_trimiteri_trimis (trimis_la)',
        ]);
        $s->tabelComplet('automatizari_jurnal', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'automatizare_id' => 'INT UNSIGNED NOT NULL',
            'rulare_id' => 'INT UNSIGNED NULL',
            'pas_cheie' => 'VARCHAR(20) NULL',
            'eveniment' => 'VARCHAR(30) NOT NULL',
            'detalii' => 'VARCHAR(255) NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_automatizari_jurnal_pas (automatizare_id, pas_cheie, eveniment)',
            'KEY idx_automatizari_jurnal_rulare (rulare_id)',
            'KEY idx_automatizari_jurnal_creat (automatizare_id, created_at)',
        ]);
    },
];
