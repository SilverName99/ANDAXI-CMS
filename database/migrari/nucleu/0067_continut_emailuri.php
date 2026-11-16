<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Conținutul emailurilor trimise (16 nov 2026, cererea proprietarului Biovitality: „Emailuri trimise” → „Vezi”;
 * App\Support\EmailContinut, docs/PLUGINURI.md, „Emailurile trimise și conținutul lor”):
 *
 *  - `email_send_bodies`: un rând pentru fiecare email din `email_send_history` scris după această migrare, în afară de
 *    trimiterile unei campanii de newsletter (`newsletter_campaign`, mii de destinatari: acelea se arată din campanie).
 *    `history_id` (cheia, id-ul rândului din istoric), `html_gz` (HTML-ul exact trimis, comprimat cu gzcompress; NULL după
 *    ștergere), `html_bytes` (mărimea necomprimată), `cleared_reason` (de ce nu mai e: `retentie` după 90 de zile, `gdpr`
 *    la ștergerea contului sau a abonatului, `prea_mare` peste 4 MB), `cleared_at`, `created_at`. Rândul rămâne după
 *    ștergerea conținutului, ca adminul să vadă de ce lipsește (și ca un email de dinainte să se deosebească de unul șters).
 *  - indexul `idx_email_send_bodies_curatare` (`cleared_at`, `created_at`): păstrarea limitată găsește conținutul vechi fără
 *    să citească tot tabelul.
 *
 * Pornește gol; emailurile de dinainte rămân fără conținut („nu a fost păstrat”). Nicio schimbare pe `email_send_history`.
 */
return [
    'descriere' => 'Conținutul emailurilor trimise (HTML comprimat, păstrare limitată), pentru „Vezi” din istoric',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('email_send_bodies', [
            'history_id' => 'INT UNSIGNED NOT NULL',
            'html_gz' => 'MEDIUMBLOB NULL',
            'html_bytes' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'cleared_reason' => 'VARCHAR(20) NULL',
            'cleared_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (history_id)',
            'KEY idx_email_send_bodies_curatare (cleared_at, created_at)',
        ]);
    },
];
