<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Acordurile GDPR semnate.
 *
 * Surse: AdminController::ensureOptionalSchema, SiteController::ensureGdprAgreementsSchema. Fără pagina
 * /acorduri-gdpr: o face pasul de activare al pluginului (WP-05), nu o migrare.
 */
return [
    'descriere' => 'Acordurile GDPR semnate',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('gdpr_agreements', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'subiect_nume_complet' => 'VARCHAR(190) NOT NULL',
            'ci_serie' => 'VARCHAR(30) NOT NULL',
            'ci_numar' => 'VARCHAR(40) NOT NULL',
            'ci_emitent' => 'VARCHAR(190) NOT NULL',
            'ci_data_eliberare' => 'DATE NULL',
            'nume' => 'VARCHAR(120) NOT NULL',
            'prenume' => 'VARCHAR(120) NOT NULL',
            'cnp' => 'VARCHAR(20) NOT NULL',
            'cuim' => 'VARCHAR(50) NOT NULL',
            'telefon' => 'VARCHAR(60) NOT NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'adresa_corespondenta' => 'VARCHAR(255) NOT NULL',
            'institutie_medicala' => 'VARCHAR(255) NOT NULL',
            'institutie_activitate' => 'VARCHAR(255) NOT NULL',
            'institutie_adresa' => 'VARCHAR(255) NOT NULL',
            'institutie_activitate_adresa' => 'VARCHAR(255) NOT NULL',
            'tip_medic' => 'VARCHAR(120) NOT NULL',
            'specializare' => 'VARCHAR(190) NOT NULL',
            'data_semnare' => 'DATE NULL',
            'nume_semnatura' => 'VARCHAR(190) NOT NULL',
            'signature_data_url' => 'LONGTEXT NOT NULL',
            'source_url' => 'VARCHAR(500) NULL',
            'ip_address' => 'VARCHAR(64) NULL',
            'user_agent' => 'VARCHAR(255) NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_gdpr_agreements_created (created_at)',
            'KEY idx_gdpr_agreements_email (email)',
            'KEY idx_gdpr_agreements_name (nume, prenume)',
        ]);
    },
];
