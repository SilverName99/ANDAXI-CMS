<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Pasul site-ului Biovitality (portul BV `0e54737`; aplicat la adoptarea lui, cu `migreaza.php --aplica --site=biovitality`,
 * după înghețarea setărilor): confirmarea adresei la înregistrare.
 *
 * Codul BV are bifa „Confirmare email la înregistrare” (Utilizatori → Setări), cheia `customer_email_confirmation_enabled`,
 * pornită când lipsește sau are orice altă valoare decât „0”. Codul comun are aceeași regulă pe cheia lui,
 * `customer_email_confirmation_required` (WP-25), pe care înghețarea Biovitality o scrie „1” când lipsește (decizia din
 * 30 oct). Dacă pe site bifa BV e oprită („0” scris din admin), codul comun o ține tot oprită: cheia lui devine „0”. Altfel
 * nimic (înghețarea a pus deja „1”). Cheia BV rămâne în bază, necitită.
 */
return [
    'descriere' => 'Confirmarea adresei la înregistrare: bifa oprită pe site-ul vechi (customer_email_confirmation_enabled = 0) rămâne oprită',
    'tip' => 'site',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $randuri = $s->citeste("SELECT `value` FROM settings WHERE `key` = 'customer_email_confirmation_enabled'");
        if ($randuri !== [] && (string) ($randuri[0]['value'] ?? '') === '0') {
            $s->date('Confirmarea la înregistrare oprită, ca pe site-ul vechi (customer_email_confirmation_required = 0)',
                "INSERT INTO settings (`key`, `value`) VALUES ('customer_email_confirmation_required', '0') ON DUPLICATE KEY UPDATE `value` = '0'");
        }
    },
];
