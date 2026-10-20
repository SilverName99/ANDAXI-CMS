<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Recenziile din tabelul vechi reviews, copiate o singură dată în product_reviews; sursa recenziilor
 * fără sursă.
 *
 * Înlocuiește ce fac AdminController::ensureOptionalSchema și SiteController::ensureProductCustomSchema
 * la fiecare pagină de admin și la fiecare pagină de produs: copierea (doar recenziile care lipsesc
 * din product_reviews, aceeași potrivire: produs, nume, notă, text, data) și source = 'product_page'
 * unde e gol (după copiere, ca recenziile copiate să ajungă la fel ca pe site azi). O dată, nu la
 * fiecare cerere: o recenzie copiată pe care clientul o șterge nu mai revine. reviews rămâne pe site-urile
 * care îl au, până la faza „contract”; o instalare nouă nu îl are.
 */
return [
    'descriere' => 'Recenziile din tabelul vechi reviews, copiate o dată; sursa recenziilor fără sursă',
    'tip' => 'date',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->dacaTabel('reviews', static function (Schema $s): void {
            $copiate = $s->date('Recenziile din tabelul vechi reviews care lipsesc din product_reviews',
                'INSERT INTO product_reviews (product_id, user_name, rating, review_text, is_approved, created_at)
                 SELECT r.product_id, r.user_name, r.rating, r.review_text, r.is_approved, r.created_at
                 FROM reviews r
                 LEFT JOIN product_reviews pr
                    ON pr.product_id = r.product_id
                    AND pr.user_name = r.user_name
                    AND pr.rating = r.rating
                    AND ((pr.review_text IS NULL AND r.review_text IS NULL) OR pr.review_text = r.review_text)
                    AND pr.created_at = r.created_at
                 WHERE pr.id IS NULL');
            if ($copiate > 0) {
                $s->avertisment($copiate . ' recenzii copiate din tabelul vechi reviews în product_reviews (o singură dată; '
                    . 'reviews rămâne până la faza „contract”)');
            }
        });
        $s->date('Sursa recenziilor fără sursă = pagina produsului', "UPDATE product_reviews SET source = 'product_page' WHERE source IS NULL OR source = ''");
    },
];
