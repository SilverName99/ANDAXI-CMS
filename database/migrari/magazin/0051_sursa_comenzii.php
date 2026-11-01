<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Sursa fiecărei comenzi (WP-40, SEO partea B, B9; App\Support\SursaComenzii): `orders.sursa` — de unde a venit clientul
 * care a comandat: `google_ads` (reclamă Google: gclid/gbraid/wbraid sau o campanie plătită de la Google), `google`
 * (căutarea gratuită: venit din Google fără reclamă), `email` (newsletter, un email: utm_medium email/newsletter), `campanie`
 * (alt link cu utm_), `alt_site` (venit de pe alt site), `direct`. Doar felul, fără nicio adresă, cookie sau id.
 *
 * `orders.ad_source` (Google Ads cu click id, scris din cookie-ul bv_gclid, citit de export) rămâne cum e: o coloană
 * nouă, ca un rând vechi să nu se citească altfel. Comenzile de dinainte au sursa goală („necunoscută” în raport).
 */
return [
    'descriere' => 'Sursa comenzii (Google, reclamă Google, email, alt site, direct), pentru raportul din admin',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('orders', 'sursa', 'VARCHAR(20) NULL');
    },
];
