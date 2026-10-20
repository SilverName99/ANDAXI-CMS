<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Livrarea FAN: FANbox, localitățile, kilometrii în afara localității, străzile, jurnalul sincronizării,
 * coloanele AWB pe comenzi.
 *
 * Coloanele de pe comenzi: AWB-ul, urmărirea coletului, FANbox-ul, emailul de comandă finalizată. Surse:
 * FanLockers::ensureSchema, FanNomenclator::asiguraSchema*, AdminController::ensureOptionalSchema,
 * CheckoutCalculator::ensureOrderShippingSchema, ErpSync::ensureSchema (lista AWB-urilor),
 * scripts/fan-tracking-sync.php.
 *
 * Ține lacătele sincronizării nomenclatorului (FanNomenclator::NUME_LACAT) cât atinge tabelele FAN:
 * sincronizarea le înlocuiește cu copiile ei (*__sync), iar o coloană adăugată între timp s-ar pierde.
 * Pasul vechi al străzilor (FanNomenclator::asiguraSchemaStrazi): pe o bază de dinainte de row_key,
 * coloana se adaugă cu '' (cum o adăuga codul), se completează pe bucăți cu formula lui, indexul unic
 * vechi uniq_fan_streets se scoate și abia apoi vine uniq_fan_streets_row.
 */
return [
    'descriere' => 'Livrarea FAN: FANbox, localitățile, kilometrii, străzile, sincronizarea; AWB-ul pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        // Sincronizarea nomenclatorului înlocuiește tabelele localităților și ale străzilor cu copiile ei:
        // cât timp le atinge migrarea, n-are voie (și invers; codul 3 după 60 s de așteptare).
        $s->lacatFan('localitati');
        $s->lacatFan('strazi');

        $s->tabelComplet('fan_lockers', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'code' => 'VARCHAR(60) NOT NULL',
            'fan_id' => "VARCHAR(60) NOT NULL DEFAULT ''",
            'name' => 'VARCHAR(190) NOT NULL',
            'county' => 'VARCHAR(120) NOT NULL',
            'locality' => 'VARCHAR(190) NOT NULL',
            'address' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'postcode' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'lat' => 'DECIMAL(10,7) NULL',
            'lng' => 'DECIMAL(10,7) NULL',
            'county_norm' => 'VARCHAR(120) NOT NULL',
            'locality_norm' => 'VARCHAR(190) NOT NULL',
            'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'DATETIME NOT NULL',
            'updated_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_fan_lockers_county (county_norm)',
            'KEY idx_fan_lockers_locality (county_norm, locality_norm)',
            'UNIQUE KEY uniq_fan_lockers_code (code)',
        ]);

        $s->tabelComplet('fan_localities', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'county' => 'VARCHAR(120) NOT NULL',
            'locality' => 'VARCHAR(190) NOT NULL',
            'county_norm' => 'VARCHAR(120) NOT NULL',
            'locality_norm' => 'VARCHAR(190) NOT NULL',
            'created_at' => 'DATETIME NOT NULL',
            'updated_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_fan_localities_county (county_norm)',
            'KEY idx_fan_localities_locality (locality_norm)',
            'UNIQUE KEY uniq_fan_localities (county_norm, locality_norm)',
        ]);

        $s->tabelComplet('fan_localities_extra_km', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'county' => 'VARCHAR(120) NOT NULL',
            'locality' => 'VARCHAR(190) NOT NULL',
            'county_norm' => 'VARCHAR(120) NOT NULL',
            'locality_norm' => 'VARCHAR(190) NOT NULL',
            'exterior_km' => 'DECIMAL(8,2) NULL',
            'created_at' => 'DATETIME NOT NULL',
            'updated_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_fan_localities_extra_km_county (county_norm)',
            'KEY idx_fan_localities_extra_km_locality (locality_norm)',
            'UNIQUE KEY uniq_fan_localities_extra_km (county_norm, locality_norm)',
        ]);

        // Străzile. Pe o bază de dinainte de row_key (pasul vechi din FanNomenclator::asiguraSchemaStrazi):
        // coloana vine cu '' (ca acolo), rândurile o primesc pe bucăți, cu formula de acolo (cu id-ul rândului,
        // deci unică), indexul unic vechi uniq_fan_streets iese, iar uniq_fan_streets_row vine abia la urmă (pe
        // '' s-ar repeta). O reluare după o oprire vede rândurile rămase cu ''.
        $coloaneStrazi = [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'county' => 'VARCHAR(120) NOT NULL',
            'locality' => 'VARCHAR(190) NOT NULL',
            'street' => 'VARCHAR(255) NOT NULL',
            'street_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'range_from' => "VARCHAR(40) NOT NULL DEFAULT ''",
            'range_to' => "VARCHAR(40) NOT NULL DEFAULT ''",
            'parity' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'postal_code' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'street_type' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'agency' => "VARCHAR(160) NOT NULL DEFAULT ''",
            'county_norm' => 'VARCHAR(120) NOT NULL',
            'locality_norm' => 'VARCHAR(190) NOT NULL',
            'street_norm' => 'VARCHAR(255) NOT NULL',
            'row_key' => 'CHAR(40) NOT NULL',
            'created_at' => 'DATETIME NOT NULL',
            'updated_at' => 'DATETIME NOT NULL',
        ];
        $cheiStrazi = [
            'PRIMARY KEY (id)',
            'KEY idx_fan_streets_county (county_norm)',
            'KEY idx_fan_streets_locality (locality_norm)',
            'KEY idx_fan_streets_street (street_norm)',
        ];
        if (!$s->existaTabel('fan_streets')) {
            $s->tabelComplet('fan_streets', $coloaneStrazi, array_merge($cheiStrazi, ['UNIQUE KEY uniq_fan_streets_row (row_key)']));
        } else {
            $aveaCheie = $s->existaColoana('fan_streets', 'row_key');
            if (!$aveaCheie) {
                $s->coloana('fan_streets', 'row_key', "CHAR(40) NOT NULL DEFAULT ''");
            }
            $s->tabelComplet('fan_streets', $coloaneStrazi, $cheiStrazi);
            $deCompletat = !$aveaCheie
                || (int) ($s->citeste("SELECT COUNT(*) AS n FROM fan_streets WHERE row_key = ''")[0]['n'] ?? 0) > 0;
            if ($deCompletat) {
                $s->peBucati('fan_streets', 'id', 1000, static function (PDO $db, int $de, int $pana): int {
                    $stmt = $db->prepare("UPDATE fan_streets SET row_key = SHA1(CONCAT_WS('|', county_norm, locality_norm, street_norm,
                            COALESCE(street_id, ''), COALESCE(range_from, ''), COALESCE(range_to, ''), COALESCE(parity, ''),
                            COALESCE(postal_code, ''), COALESCE(street_type, ''), COALESCE(agency, ''), CAST(id AS CHAR)))
                        WHERE id > ? AND id <= ? AND row_key = ''");
                    $stmt->execute([$de, $pana]);
                    return $stmt->rowCount();
                }, 'fan_streets.row_key');
            }
            $s->stergeIndex('fan_streets', 'uniq_fan_streets');
            if ($deCompletat && $s->simulare()) {
                // Simularea nu vede cheile completate: cu '' pe fiecare rând, indexul unic ar părea imposibil.
                $s->avertisment('fan_streets: uniq_fan_streets_row se adaugă după ce fiecare rând își primește row_key');
            } else {
                $s->unic('fan_streets', 'uniq_fan_streets_row', ['row_key']);
            }
        }

        $s->tabelComplet('fan_nomenclator_sync', [
            'lista' => 'VARCHAR(20) NOT NULL',
            'stare' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'cursor_json' => 'MEDIUMTEXT NULL',
            'pas' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'pasi_total' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'randuri' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'pornit_la' => 'DATETIME NULL',
            'activ_la' => 'DATETIME NULL',
            'reusit_la' => 'DATETIME NULL',
            'reusit_sursa' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'reusit_randuri' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'reusit_detalii' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'eroare' => 'TEXT NULL',
            'eroare_la' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (lista)',
        ]);

        foreach ([
            'fan_locker_id' => 'INT UNSIGNED NULL',
            'fan_locker_name' => 'VARCHAR(190) NULL',
            'fan_locker_address' => 'VARCHAR(255) NULL',
            'fan_locker_city' => 'VARCHAR(190) NULL',
            'fan_locker_county' => 'VARCHAR(190) NULL',
            'fan_locker_postcode' => 'VARCHAR(20) NULL',
            'fan_awb' => 'VARCHAR(100) NULL',
            'fan_tracking_url' => 'TEXT NULL',
            'fan_tracking_status' => 'VARCHAR(190) NULL',
            'fan_tracking_last_event_at' => 'DATETIME NULL',
            'fan_tracking_synced_at' => 'DATETIME NULL',
            'completed_awb_email_sent_at' => 'DATETIME NULL',
            'completed_awb_email_error' => 'TEXT NULL',
            'fan_awb_list' => 'TEXT NULL',
            'fan_awb_inlocuite' => 'TEXT NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }
    },
];
