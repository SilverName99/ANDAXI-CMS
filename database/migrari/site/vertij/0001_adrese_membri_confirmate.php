<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Pasul site-ului vertij (aplicat la adoptare, cu `migreaza.php --aplica --site=vertij`; decizia proprietarului din 9 noiembrie
 * 2026): adresa membrilor care și-au confirmat-o pe codul vertij devine dovedită și pentru nucleu.
 *
 * Codul vertij confirma adresa unui cont profesional cu linkul cererii (`account_verifications`: din „awaiting_email” în
 * „pending”, apoi aprobată sau respinsă), fără `users.email_confirmed_at` (coloana nucleului, WP-25). Fără pasul acesta, un
 * membru vechi ar vedea donațiile și comenzile făcute fără cont cu adresa lui abia după o nouă confirmare. Contul cu o cerere
 * trecută de „awaiting_email” (oricare: `confirmed_at` completat sau starea alta) primește `email_confirmed_at` = momentul
 * confirmării pe codul vertij (ultima cerere confirmată), altfel momentul adoptării. Idempotent: doar unde e încă NULL.
 * Conturile cu cererea încă neconfirmată rămân neconfirmate (MembriController::logareBlocata le lasă să intre ca pe codul
 * vertij: cererile de dinainte de adoptare nu blochează logarea).
 */
return [
    'descriere' => 'Membrii vertij cu adresa confirmată pe codul vechi: users.email_confirmed_at (o singură dată, doar unde lipsește)',
    'tip' => 'site',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        if (!$s->existaTabel('account_verifications') || !$s->existaColoana('users', 'email_confirmed_at')) {
            return;
        }
        $s->date('Adresa confirmată pe codul vertij (cererea de membru trecută de „awaiting_email”) devine users.email_confirmed_at',
            "UPDATE users u
             SET u.email_confirmed_at = COALESCE(
                 (SELECT MAX(v.confirmed_at) FROM account_verifications v WHERE v.user_id = u.id),
                 NOW())
             WHERE u.email_confirmed_at IS NULL
               AND EXISTS (SELECT 1 FROM account_verifications v2 WHERE v2.user_id = u.id
                           AND (v2.status <> 'awaiting_email' OR v2.confirmed_at IS NOT NULL))");
    },
];
