<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Etichetele abonaților newsletterului (App\Support\NewsletterEtichete; docs/PLUGINURI.md, „Automatizări newsletter”) și
 * refuzul urmăririi pe abonat:
 *
 *  - `newsletter_etichete`: o etichetă (numele e unic, fără deosebire între litere mari și mici, după colația tabelului);
 *  - `newsletter_abonati_etichete`: ce etichete are fiecare abonat și de când;
 *  - `newsletter_subscribers.fara_urmarire`: 1 = abonatul a refuzat urmărirea; emailurile automatizărilor îi pleacă fără
 *    pixelul de deschidere și fără linkurile de clic rescrise (0 = ca până acum).
 *
 * Numărul 0082: primul liber după rezervele planului GT (0072–0081; 0077 e `newsletter/0077_mailpoet`). Nu depinde de nicio
 * migrare cu număr mai mare. Tabele goale și o coloană cu implicitul 0 pe toate site-urile (decizia OD-02): cât nu pune nimeni
 * o etichetă, newsletterul se poartă exact ca înainte.
 */
return [
    'descriere' => 'Etichetele abonaților newsletterului (newsletter_etichete, newsletter_abonati_etichete) și refuzul urmăririi pe abonat',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('newsletter_etichete', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'nume' => 'VARCHAR(80) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_newsletter_etichete_nume (nume)',
        ]);
        $s->tabelComplet('newsletter_abonati_etichete', [
            'subscriber_id' => 'INT UNSIGNED NOT NULL',
            'eticheta_id' => 'INT UNSIGNED NOT NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (subscriber_id, eticheta_id)',
            'KEY idx_newsletter_abonati_etichete_eticheta (eticheta_id)',
        ]);
        $s->coloana('newsletter_subscribers', 'fara_urmarire', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
