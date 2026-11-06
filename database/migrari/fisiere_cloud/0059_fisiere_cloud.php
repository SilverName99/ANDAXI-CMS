<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Fișierele din cloud (pluginul `fisiere_cloud`, din Biovitality f5486ee … f9b4d78; docs/PLUGINURI.md, „Fișiere în cloud”):
 *
 *  - `admin_app_tokens`: cheile aplicației Windows a administratorilor (doar amprenta sha256 a cheii, App\Support\AppTokens);
 *  - `file_locks`: fișierele preluate pentru editare (cine, de când, până când; App\Support\FileLocks);
 *  - `file_versions`: copiile de dinaintea fiecărei suprascrieri, din folderul `_versiuni` al zonei (App\Support\FileVersions);
 *  - `bunny_protected_folders`: folderele cu parolă (hash-ul parolei, password_hash) și `bunny_folder_unlocks`: cine le-a
 *    deschis, până când, și încercările greșite (App\Support\FolderPasswords).
 *
 * Adoptivă: definițiile sunt exact cele pe care le face codul Biovitality la d95598e (`ensureSchema` din cele patru clase;
 * tools/dev/migrari/vechi/bv.sql), deci pe baza biovitality.ro tabelele există cu tot cu rânduri și migrarea le găsește
 * („detectata”) fără să le atingă: nicio coloană schimbată, niciun rând scris. Pe celelalte site-uri (decizia OD-02) tabelele
 * apar goale și nu le folosește nimic cât pluginul nu e în licență. Fără date în migrare.
 */
return [
    'descriere' => 'Fișierele din cloud: cheile aplicației, lacătele, versiunile, folderele cu parolă (fisiere_cloud)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('admin_app_tokens', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'admin_id' => 'INT UNSIGNED NOT NULL',
            'name' => 'VARCHAR(120) NOT NULL',
            'token_hash' => 'CHAR(64) NOT NULL',
            'last_used_at' => 'DATETIME DEFAULT NULL',
            'last_used_ip' => 'VARCHAR(45) DEFAULT NULL',
            'revoked_at' => 'DATETIME DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_token (token_hash)',
            'KEY idx_admin (admin_id)',
        ]);
        $s->tabelComplet('file_locks', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'storage' => "VARCHAR(20) NOT NULL DEFAULT 'bunny'",
            'file_path' => 'VARCHAR(700) NOT NULL',
            'path_hash' => 'CHAR(64) NOT NULL',
            'admin_id' => 'INT UNSIGNED DEFAULT NULL',
            'admin_email' => 'VARCHAR(190) DEFAULT NULL',
            'note' => 'VARCHAR(255) DEFAULT NULL',
            'origin' => "VARCHAR(20) NOT NULL DEFAULT 'web'",
            'locked_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'expires_at' => 'DATETIME DEFAULT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_path_hash (path_hash)',
            'KEY idx_admin (admin_id)',
        ]);
        $s->tabelComplet('file_versions', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'storage' => "VARCHAR(20) NOT NULL DEFAULT 'bunny'",
            'file_path' => 'VARCHAR(700) NOT NULL',
            'path_hash' => 'CHAR(64) NOT NULL',
            'version_path' => 'VARCHAR(760) NOT NULL',
            'size_bytes' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'admin_id' => 'INT UNSIGNED DEFAULT NULL',
            'admin_email' => 'VARCHAR(190) DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_path (path_hash, id)',
        ]);
        $s->tabelComplet('bunny_protected_folders', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'folder_path' => 'VARCHAR(700) NOT NULL',
            'path_hash' => 'CHAR(64) NOT NULL',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'hint' => 'VARCHAR(190) DEFAULT NULL',
            'created_by' => 'INT UNSIGNED DEFAULT NULL',
            'created_by_email' => 'VARCHAR(190) DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_folder (path_hash)',
        ]);
        $s->tabelComplet('bunny_folder_unlocks', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'who' => 'VARCHAR(80) NOT NULL',
            'folder_path' => 'VARCHAR(700) NOT NULL',
            'path_hash' => 'CHAR(64) NOT NULL',
            'unlocked_until' => 'DATETIME DEFAULT NULL',
            'greseli' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'blocked_until' => 'DATETIME DEFAULT NULL',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_who_path (who, path_hash)',
        ]);
    },
];
