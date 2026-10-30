<?php

declare(strict_types=1);

// Generat de tools/dev/migrari/genereaza-fixture.php --asteptat; nu se editează de mână.
// Garda de la fiecare cerere (App\Support\Migrari\Garda, docs/MIGRARI.md) îl citește în locul migrărilor:
// ultima_ord și amprenta din _platforma_stare față de ord și amprenta de aici; când amprenta diferă (alt cod
// decât la ultima rulare a runner-ului), fiecare id de mai jos trebuie să fie terminat în _platforma_migrari.

return [
    'ord' => 37,
    'amprenta' => '8629bb7a8ce2ad6c1198599154391800144d6e1e503206e41e2fe16fd3619228',
    'migrari' => [
        'nucleu/0001_baza_nucleu',
        'catalog/0002_baza_catalog',
        'conturi_clienti/0003_baza_conturi',
        'magazin/0004_baza_magazin',
        'livrare_fan/0005_baza_fan',
        'plata_stripe/0006_baza_stripe',
        'plata_euplatesc/0007_baza_euplatesc',
        'plata_bt/0008_baza_bt',
        'link_plata/0009_baza_link_plata',
        'erp/0010_baza_erp',
        'cupoane/0011_baza_cupoane',
        'fidelitate/0012_baza_fidelitate',
        'recenzii/0013_baza_recenzii',
        'precomenzi/0014_baza_precomenzi',
        'oferte_bbd/0015_baza_bbd',
        'produse_promo/0016_baza_promo',
        'blog/0017_baza_blog',
        'newsletter/0018_baza_newsletter',
        'acorduri_gdpr/0019_baza_gdpr',
        'nucleu/0020_roluri_admini',
        'catalog/0021_coloane_vechi_catalog',
        'cupoane/0022_coloane_vechi_cupoane',
        'recenzii/0023_recenzii_vechi',
        'blog/0024_legaturi_categorii',
        'catalog/0026_vizibilitate_ordine',
        'catalog/0027_campuri_doar_sablon',
        'magazin/0028_transport_gratuit',
        'recenzii/0030_recenzii_spam',
        'produse_promo/0031_promo_dat_la',
        'magazin/0032_avertismente_produse',
        'blog/0033_blog_avansat',
        'nucleu/0034_acces_membri',
        'plata_stripe/0035_evenimente_stripe',
        'conturi_clienti/0036_confirmare_email',
        'cereri_oferta/0037_cereri_oferta',
    ],
];
