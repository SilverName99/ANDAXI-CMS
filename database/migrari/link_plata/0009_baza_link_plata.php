<?php

declare(strict_types=1);

use App\Support\Migrari\ParametruLipsa;
use App\Support\Migrari\Schema;

/**
 * Linkurile de plată: tabelul lor și procesatorul fiecărui link; versiunea schemei în setări.
 *
 * Sursa: PaymentLink::ensureSchema.
 *
 * Pe un site cu linkuri vechi fără coloana procesator (codul Biovitality, grafoanaytis), procesatorul lor
 * îl știe doar site-ul: parametrul link_plata.procesator_linkuri_existente din migrari.json al site-ului
 * (lipsă → codul 15, înainte de orice schimbare). Coloana se adaugă cu el ca valoare implicită, deci
 * fiecare link existent îl primește în același ALTER (nicio clipă în care un link vechi să aibă alt
 * procesator, oricând s-ar opri rularea), iar apoi valoarea implicită devine 'euplatesc', ca pe orice site
 * (schimbaImplicit; și la o reluare după o oprire între cele două).
 *
 * La final scrie payment_links_schema_version = '2', dacă nu e deja (ca BtIpayPayments, 0008).
 */
return [
    'descriere' => 'Linkurile de plată: tabelul lor și procesatorul fiecărui link; versiunea schemei în setări',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        // Linkuri vechi fără procesator: cel al site-ului, ca valoare implicită a coloanei noi (deci al
        // fiecărui link existent, în același ALTER); apoi implicit 'euplatesc', ca peste tot.
        if ($s->existaTabel('order_payment_links') && !$s->existaColoana('order_payment_links', 'procesator')) {
            $procesator = $s->parametruSite('link_plata.procesator_linkuri_existente');
            if (!in_array($procesator, ['euplatesc', 'btipay', 'stripe'], true)) {
                throw new ParametruLipsa('link_plata/0009_baza_link_plata: parametrul site-ului „link_plata.procesator_linkuri_existente” '
                    . 'trebuie să fie euplatesc, btipay sau stripe (migrari.json din dosarul site-ului)');
            }
            $s->coloana('order_payment_links', 'procesator', "VARCHAR(20) NOT NULL DEFAULT '" . $procesator . "'");
        }
        if ($s->existaColoana('order_payment_links', 'procesator')) {
            $s->schimbaImplicit('order_payment_links', 'procesator', "'euplatesc'");
        }

        $s->tabelComplet('order_payment_links', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NOT NULL',
            'referinta' => 'VARCHAR(60) NOT NULL',
            'token' => 'VARCHAR(64) NOT NULL',
            'amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'procesator' => "VARCHAR(20) NOT NULL DEFAULT 'euplatesc'",
            'ep_id' => 'VARCHAR(64) NULL',
            'approval' => 'VARCHAR(64) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'expires_at' => 'DATETIME NULL',
            'paid_at' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_order_payment_links_order (order_id, status)',
            'UNIQUE KEY uq_order_payment_links_referinta (referinta)',
            'UNIQUE KEY uq_order_payment_links_token (token)',
        ]);

        // Versiunea schemei linkurilor (PaymentLink::VERSIUNE_SCHEMA), ca la BT (0008).
        if (($s->citeste("SELECT `value` FROM settings WHERE `key` = 'payment_links_schema_version'")[0]['value'] ?? null) !== '2') {
            $s->date('Versiunea schemei linkurilor de plată în setări (payment_links_schema_version = 2)',
                "INSERT INTO settings (`key`, `value`) VALUES ('payment_links_schema_version', '2') ON DUPLICATE KEY UPDATE `value` = '2'");
        }
    },
];
