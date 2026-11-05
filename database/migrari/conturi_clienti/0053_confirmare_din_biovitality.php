<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Confirmarea adresei pe schema făcută de codul Biovitality (BV `0e54737`, 30 oct 2026; adoptivă, docs/de-portat.md).
 *
 * Codul BV își face singur coloana și tabelul confirmării (`ensureCustomerSchema`), altfel decât `conturi_clienti/0036`
 * și `0038`, care pe baza lui le găsesc și doar adaugă ce lipsește (`scop`, `email_hash`):
 *
 *  - `users.email_confirmed_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP`: la BV orice cont scris fără coloană (importul,
 *    Google) e confirmat, doar înregistrarea cu parolă scrie NULL. Codul comun nu scrie coloana la înregistrare, deci cu
 *    implicitul BV fiecare cont nou ar fi confirmat pe loc și ar vedea comenzile fără cont ale adresei (exact ce repară
 *    `0e54737`). Implicitul devine NULL (instant, rândurile rămân: conturile confirmate la BV rămân confirmate);
 *  - `customer_email_confirmations.email` și `expires_at` (ale BV, NOT NULL fără implicit): codul comun nu le scrie, deci
 *    primesc un implicit (`''`, o dată trecută), ca un link nou să se poată scrie și pe un server strict; pentru codul BV
 *    (o întoarcere la el) un link scris de codul comun e expirat;
 *  - `customer_email_confirmations.email_hash` al linkurilor trimise de BV (adresa la care a plecat linkul, scrisă de BV
 *    cu litere mici, fără spații, ca `ConfirmareEmail::hashAdresa`): fără el, `0038` le lasă fără efect, iar un client
 *    care s-a înregistrat în ultimele 7 zile dinaintea trecerii n-ar mai putea folosi linkul primit (adresa veche,
 *    `/contul-meu/confirmare/{token}`, duce la `/cont/confirmare/{token}`).
 *
 * Pe o bază fără codul BV (o instalare nouă, oricare alt site) nu face nimic: implicitul e deja NULL, iar
 * coloanele BV lipsesc. Codul BV merge mai departe pe schema de după (coloanele lui rămân, cu implicite).
 */
return [
    'descriere' => 'Confirmarea adresei pe schema codului Biovitality: implicitul NULL, linkurile trimise de el',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->schimbaImplicit('users', 'email_confirmed_at', 'NULL');
        $s->dacaColoana('customer_email_confirmations', 'email', static function (Schema $s): void {
            $s->schimbaImplicit('customer_email_confirmations', 'email', "''");
            $s->date('Adresa linkurilor trimise de codul Biovitality (email_hash din coloana lui email)',
                "UPDATE customer_email_confirmations SET email_hash = SHA2(email, 256) WHERE email_hash IS NULL AND email <> ''");
        });
        $s->dacaColoana('customer_email_confirmations', 'expires_at', static function (Schema $s): void {
            $s->schimbaImplicit('customer_email_confirmations', 'expires_at', "'2000-01-01 00:00:00'");
        });
    },
];
