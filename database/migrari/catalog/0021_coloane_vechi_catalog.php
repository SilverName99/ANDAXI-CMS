<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Datele din coloanele vechi ale catalogului, copiate o singură dată în coloanele de azi.
 *
 * Înlocuiește UPDATE-urile pe care AdminController::ensureOptionalSchema și
 * SiteController::ensureProductCustomSchema le trimit la fiecare pagină de admin și la fiecare
 * listare de produse (cu aceleași condiții, deci rezultatul e cel pe care l-ar fi lăsat ele): galeria
 * (gallery_json, apoi image_gallery_json → gallery_images_json, doar unde e goală), insignele
 * (label_* → badge_*, doar unde nu sunt puse), câmpurile extra (label → name, input_type →
 * field_type), șabloanele (*_template → *_content) și valorile câmpurilor (value_text → value).
 * Fiecare pas rulează doar dacă baza are coloana veche (o instalare nouă nu o are); tabelele mari
 * (produsele, valorile câmpurilor) pe bucăți de câte 500 de rânduri, reluate de unde au rămas.
 */
return [
    'descriere' => 'Coloanele vechi ale catalogului (galerie, insigne, câmpuri extra, șabloane), copiate o dată',
    'tip' => 'date',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach (['gallery_json', 'image_gallery_json'] as $veche) {
            $s->dacaColoana('products', $veche, static function (Schema $s) use ($veche): void {
                $s->peBucati('products', 'id', 500, static function (PDO $db, int $de, int $pana) use ($veche): int {
                    $stmt = $db->prepare('UPDATE products SET gallery_images_json = `' . $veche . '`
                        WHERE id > ? AND id <= ? AND (gallery_images_json IS NULL OR gallery_images_json = \'\') AND `' . $veche . '` IS NOT NULL');
                    $stmt->execute([$de, $pana]);
                    return $stmt->rowCount();
                }, 'products.' . $veche);
            });
        }
        foreach (['popular', 'best_seller', 'seasonal'] as $insigna) {
            $s->dacaColoana('products', 'label_' . $insigna, static function (Schema $s) use ($insigna): void {
                $s->peBucati('products', 'id', 500, static function (PDO $db, int $de, int $pana) use ($insigna): int {
                    $stmt = $db->prepare('UPDATE products SET badge_' . $insigna . ' = label_' . $insigna . '
                        WHERE id > ? AND id <= ? AND badge_' . $insigna . ' = 0 AND label_' . $insigna . ' = 1');
                    $stmt->execute([$de, $pana]);
                    return $stmt->rowCount();
                }, 'products.label_' . $insigna);
            });
        }

        $s->dacaColoana('product_extra_fields', 'label', static function (Schema $s): void {
            $s->date('Numele câmpurilor extra din coloana veche label',
                "UPDATE product_extra_fields SET name = label WHERE (name IS NULL OR name = '') AND label IS NOT NULL");
        });
        $s->dacaColoana('product_extra_fields', 'input_type', static function (Schema $s): void {
            $s->date('Felul câmpurilor extra din coloana veche input_type',
                "UPDATE product_extra_fields SET field_type = input_type WHERE (field_type IS NULL OR field_type = '') AND input_type IS NOT NULL");
        });

        $s->dacaColoana('product_templates', 'html_template', static function (Schema $s): void {
            $s->date('HTML-ul șabloanelor de produs din coloana veche html_template',
                "UPDATE product_templates SET html_content = html_template WHERE (html_content IS NULL OR html_content = '') AND html_template IS NOT NULL");
        });
        foreach (['css', 'js'] as $parte) {
            $s->dacaColoana('product_templates', $parte . '_template', static function (Schema $s) use ($parte): void {
                $s->date('Partea ' . strtoupper($parte) . ' a șabloanelor de produs din coloana veche ' . $parte . '_template',
                    'UPDATE product_templates SET ' . $parte . '_content = ' . $parte . '_template WHERE ' . $parte . '_content IS NULL AND '
                    . $parte . '_template IS NOT NULL');
            });
        }

        $s->dacaColoana('product_extra_field_values', 'value_text', static function (Schema $s): void {
            $s->peBucati('product_extra_field_values', 'id', 500, static function (PDO $db, int $de, int $pana): int {
                $stmt = $db->prepare("UPDATE product_extra_field_values SET `value` = value_text
                    WHERE id > ? AND id <= ? AND (`value` IS NULL OR `value` = '') AND value_text IS NOT NULL");
                $stmt->execute([$de, $pana]);
                return $stmt->rowCount();
            }, 'product_extra_field_values.value_text');
        });
    },
];
