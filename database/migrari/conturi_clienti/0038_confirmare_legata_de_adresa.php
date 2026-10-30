<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Revizuirea WP-25 (regula comenzilor fără cont, G-M4):
 *
 *  - `customer_email_confirmations.email_hash`: sha256 al adresei (litere mici) pentru care s-a trimis linkul. Linkul
 *    confirmă doar dacă adresa contului e tot aceea (altfel cine își schimbă adresa după ce a cerut linkul ar primi
 *    comenzile fără cont ale altei adrese). Rândurile de dinainte (NULL) nu mai confirmă nimic;
 *  - `users.session_version`: crește la resetarea parolei și când o logare cu Google anulează parola unui cont
 *    neconfirmat; o sesiune de client făcută înainte (alt număr) nu mai e logată (App\Support\CustomerAuth).
 *
 * Coloane noi la coada tabelelor, cu valori care nu schimbă nimic pentru codul de dinainte.
 */
return [
    'descriere' => 'Linkul de confirmare legat de adresă, versiunea sesiunii clientului',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('customer_email_confirmations', 'email_hash', 'CHAR(64) NULL');
        $s->coloana('users', 'session_version', 'INT UNSIGNED NOT NULL DEFAULT 0');
    },
];
