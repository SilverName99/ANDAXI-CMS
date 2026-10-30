<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Newsletterul fără trimiteri duble, cu dubla confirmare și lista „nu trimite” (codul vechi e2e2d77 … 739f58c, portat în
 * WP-R din 30 oct 2026; numărul 0040, după rezervele WP-R 0038–0039 folosite de revizuirea pasului 5):
 *
 *  - `newsletter_campaign_sends.attempts`: de câte ori s-a încercat o adresă respinsă (cel mult 3, nu la nesfârșit);
 *  - `newsletter_campaigns.last_error`: de ce s-a oprit o campanie („Oprit”), arătat în admin;
 *  - `newsletter_subscribers.pending_at` / `confirmed_at`: când a cerut omul abonarea din formular și când a confirmat-o
 *    din email (dubla confirmare);
 *  - `newsletter_suprimate`: lista „nu trimite”, doar amprenta SHA-256 a adresei unui abonat șters (GDPR: atât cât e
 *    nevoie ca să-i respectăm opoziția);
 *  - `newsletter_cereri_confirmare`: jurnalul emailurilor de confirmare și al celor „linkul tău de dezabonare”, pentru
 *    limitele pe adresă, pe IP și pe tot site-ul (se curăță după 2 zile).
 *
 * Adoptivă: codul vechi le face din mers (NewsletterService::ensureSchema și database/schema.sql), deci pe un site care îl
 * rulează deja migrarea le găsește coloană cu coloană și nu face nimic; definițiile sunt exact cele din codul vechi.
 *
 * La final scrie pragul cheii vechi de semnare (`newsletter_cheie_limita`: cea mai mare campanie și cel mai mare abonat de
 * acum), dacă nu e deja: doar ei pot avea linkuri semnate cu cheia veche, ghicibilă (NewsletterService::limitaCheieVeche).
 * Codul vechi îl scrie la prima rulare după actualizare (ensureSchema); în codul comun ensureSchema nu mai rulează pe o
 * bază la zi, iar migrarea e chiar „prima rulare după actualizare”, deci pragul se fixează înaintea oricărei cereri. Pe o
 * bază nouă e 0 și 0: un site nou n-are emailuri vechi. Rândul nu iese prin Settings::all (ca cheia).
 */
return [
    'descriere' => 'Newsletterul: încercările și motivul opririi, dubla confirmare, lista „nu trimite”, limitele cererilor; pragul cheii vechi de semnare',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('newsletter_campaign_sends', 'attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $s->coloana('newsletter_subscribers', 'pending_at', 'DATETIME NULL');
        $s->coloana('newsletter_subscribers', 'confirmed_at', 'DATETIME NULL');

        $s->tabelComplet('newsletter_suprimate', [
            'email_hash' => 'CHAR(64) NOT NULL',
            'motiv' => "VARCHAR(40) NOT NULL DEFAULT 'stergere_admin'",
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (email_hash)',
        ]);

        $s->tabelComplet('newsletter_cereri_confirmare', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'email_hash' => 'CHAR(64) NOT NULL',
            'ip' => 'VARCHAR(64) NULL',
            'created_at' => 'DATETIME NOT NULL',
            'tip' => "VARCHAR(20) NOT NULL DEFAULT 'abonare'",
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_email_creat (email_hash, created_at)',
            'KEY idx_ip_creat (ip, created_at)',
            'KEY idx_creat (created_at)',
        ]);

        $s->coloana('newsletter_campaigns', 'last_error', 'TEXT NULL');

        // Pragul cheii vechi, o singură dată (ca INSERT IGNORE-ul codului vechi): același JSON ca json_encode.
        if ($s->citeste("SELECT `value` FROM settings WHERE `key` = 'newsletter_cheie_limita'") === []) {
            $campanie = (int) ($s->citeste('SELECT COALESCE(MAX(id), 0) AS m FROM newsletter_campaigns')[0]['m'] ?? 0);
            $abonat = (int) ($s->citeste('SELECT COALESCE(MAX(id), 0) AS m FROM newsletter_subscribers')[0]['m'] ?? 0);
            $s->date('Pragul cheii vechi de semnare a linkurilor din newslettere (newsletter_cheie_limita)',
                'INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)',
                ['k' => 'newsletter_cheie_limita', 'v' => json_encode(['campanie' => $campanie, 'abonat' => $abonat])]);
        }
    },
];
