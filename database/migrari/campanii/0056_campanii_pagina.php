<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Pagina campaniei și codul `{{campanie_produse}}` (pluginul `campanii`, App\Support\Campanii; docs/PLUGINURI.md, „Campanii
 * pe site”), cerute pentru Biovitality (Black Friday, 5 nov 2026):
 *
 *  - `fara_antet_subsol`: „Pagina campaniei fără antetul și subsolul site-ului” (doar la /campanie/<slug>). În bază 0, ca o
 *    campanie de dinainte să rămână cum era; o campanie nouă o are bifată din editor (Campanii::noua);
 *  - `bara_inchisa`: bara de sus a paginii campaniei („← Înapoi în site-ul principal”) neagră, pentru o pagină închisă;
 *  - `carduri_inchise`: cardurile grilei `{{campanie_produse}}` închise (#161616 pe negru, text alb);
 *  - `text_sub_pret`: rândul opțional de sub preț pe cardurile grilei, cu `{pret}` / `{pret*1.5}`.
 *
 * Coloane la coadă, cu valori implicite: codul de dinainte merge pe schema nouă (citește `SELECT *`, scrie doar coloanele lui).
 */
return [
    'descriere' => 'Campaniile: pagina fără antet și subsol, bara închisă, cardurile produselor (campanii)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('campanii', 'fara_antet_subsol', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('campanii', 'bara_inchisa', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('campanii', 'carduri_inchise', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('campanii', 'text_sub_pret', 'VARCHAR(160) NULL');
    },
];
