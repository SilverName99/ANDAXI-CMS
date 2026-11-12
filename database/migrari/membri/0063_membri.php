<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Membrii (pluginul `membri`, din vertij d3439a5 … ffa3d9c, 50ef812, af8ab87, 7d7d8cc; docs/PLUGINURI.md, „Membri”):
 *
 *  - `professions`: categoriile de membru (tipurile de cont), cu cotizația și perioada ei;
 *  - `profession_fields`: câmpurile cerute la înscriere pentru fiecare categorie (text, dată, listă, bifă, document);
 *  - `account_verifications`: cererile de înscriere (răspunsurile, acordul GDPR, hash-ul sha256 al linkului de confirmare a
 *    adresei, decizia administratorului) și `account_verification_documents`: documentele lor, ținute în afara docroot-ului
 *    (`storage/account-documents`);
 *  - `account_activations`: plățile cotizației (sesiunea Stripe Checkout, perioada plătită, chitanța);
 *  - pe `users`: categoria, starea cererii, contul activ până la, codul de membru (unic), poza de profil și clientul Stripe.
 *
 * Adoptivă: definițiile sunt exact cele pe care le face codul vertij la 9ab7999 (`ProfessionAccounts::ensureSchema`;
 * tools/dev/migrari/vechi/vt.sql), deci pe baza vertij-tinitus.ro totul există cu tot cu rânduri și migrarea găsește totul
 * („detectata”) fără să atingă ceva (indexul unic al codului de membru e acolo de două ori, sub două nume: oricare îl
 * dovedește). Pe celelalte site-uri tabelele apar goale, iar coloanele de pe `users` sunt NULL sau „none” (implicitul de pe
 * vertij): conturile se citesc și se scriu exact ca înainte. Coloanele `requires_active_account` ale paginilor și articolelor
 * le face nucleul (nucleu/0034). Fără date în migrare.
 */
return [
    'descriere' => 'Membrii: categoriile, câmpurile, cererile cu documentele, plățile cotizației, coloanele contului (membri)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('professions', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'description' => 'TEXT DEFAULT NULL',
            'activation_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'activation_months' => 'INT UNSIGNED NOT NULL DEFAULT 12',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);
        $s->tabelComplet('profession_fields', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'profession_id' => 'INT UNSIGNED NOT NULL',
            'field_key' => 'VARCHAR(100) NOT NULL',
            'label' => 'VARCHAR(190) NOT NULL',
            'field_type' => "VARCHAR(20) NOT NULL DEFAULT 'text'",
            'options_json' => 'LONGTEXT DEFAULT NULL',
            'help_text' => 'VARCHAR(255) DEFAULT NULL',
            'is_required' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_profession_field (profession_id, field_key)',
            'KEY idx_profession_fields_profession (profession_id, sort_order)',
        ]);
        $s->tabelComplet('account_verifications', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'profession_id' => 'INT UNSIGNED DEFAULT NULL',
            'profession_name' => 'VARCHAR(190) DEFAULT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'awaiting_email'",
            'answers_json' => 'LONGTEXT DEFAULT NULL',
            'gdpr_accepted' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'gdpr_accepted_at' => 'DATETIME DEFAULT NULL',
            'submitted_ip' => 'VARCHAR(64) DEFAULT NULL',
            'submitted_user_agent' => 'VARCHAR(255) DEFAULT NULL',
            'confirm_token_hash' => 'VARCHAR(128) DEFAULT NULL',
            'confirmed_at' => 'DATETIME DEFAULT NULL',
            'reviewed_by' => 'INT UNSIGNED DEFAULT NULL',
            'reviewed_at' => 'DATETIME DEFAULT NULL',
            'review_note' => 'TEXT DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_account_verifications_user (user_id)',
            'KEY idx_account_verifications_status (status, created_at)',
            'KEY idx_account_verifications_token (confirm_token_hash)',
        ]);
        $s->tabelComplet('account_verification_documents', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'verification_id' => 'INT UNSIGNED NOT NULL',
            'field_key' => 'VARCHAR(100) DEFAULT NULL',
            'field_label' => 'VARCHAR(190) DEFAULT NULL',
            'original_name' => 'VARCHAR(255) NOT NULL',
            'stored_name' => 'VARCHAR(255) NOT NULL',
            'mime_type' => 'VARCHAR(100) DEFAULT NULL',
            'file_size' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_account_documents_verification (verification_id)',
        ]);
        $s->tabelComplet('account_activations', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'profession_id' => 'INT UNSIGNED DEFAULT NULL',
            'profession_name' => 'VARCHAR(190) DEFAULT NULL',
            'amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'currency' => "VARCHAR(10) NOT NULL DEFAULT 'ron'",
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'months' => 'INT UNSIGNED NOT NULL DEFAULT 12',
            'stripe_session_id' => 'VARCHAR(255) DEFAULT NULL',
            'stripe_payment_intent_id' => 'VARCHAR(255) DEFAULT NULL',
            'period_start' => 'DATETIME DEFAULT NULL',
            'period_end' => 'DATETIME DEFAULT NULL',
            'paid_at' => 'DATETIME DEFAULT NULL',
            'payment_error' => 'TEXT DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'receipt_url' => 'VARCHAR(500) DEFAULT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_account_activations_user (user_id, status)',
            'KEY idx_account_activations_session (stripe_session_id)',
        ]);

        $s->coloana('users', 'profession_id', 'INT UNSIGNED DEFAULT NULL');
        $s->coloana('users', 'account_status', "VARCHAR(20) NOT NULL DEFAULT 'none'");
        $s->coloana('users', 'account_active_until', 'DATETIME DEFAULT NULL');
        $s->coloana('users', 'member_code', 'VARCHAR(32) DEFAULT NULL');
        $s->coloana('users', 'avatar_url', 'VARCHAR(255) DEFAULT NULL');
        $s->coloana('users', 'stripe_customer_id', 'VARCHAR(255) DEFAULT NULL');
        $s->unic('users', 'uniq_users_member_code', ['member_code']);
    },
];
