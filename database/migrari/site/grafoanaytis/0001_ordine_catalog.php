<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Pasul site-ului grafoanaytis (WP-19, GR-08; aplicat la adoptarea lui, în Etapa 4, cu `migreaza.php --aplica
 * --site=grafoanaytis`): ordinea catalogului aleasă de client trece din coloana veche `products.sort_order` (codul
 * grafoanaytis 1ff9f88: un număr de ordine, 0 = fără, pași de 10) în `products.sort_position` a codului comun
 * (catalog/0026), ca rang dens: cel mai mic sort_order > 0 devine 1, următorul 2 și așa mai departe (aceleași numere,
 * același rang); 0 rămâne NULL (fără poziție, la coada catalogului, alfabetic). Cu înghețarea lui (catalog_sortare_implicita
 * = catalog), /produse arată produsele în aceeași ordine ca pe site-ul vechi; proprietarul o confirmă pe copia de
 * verificare (migrari.md §7.8).
 *
 * Scrie doar unde sort_position e încă NULL (pasul se poate relua; o poziție pusă între timp din admin rămâne). Fără
 * coloana veche (o bază care n-a avut codul grafoanaytis), nimic.
 */
return [
    'descriere' => 'Ordinea catalogului grafoanaytis: sort_order (vechi) → sort_position, ca rang dens',
    'tip' => 'site',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->dacaColoana('products', 'sort_order', static function (Schema $s): void {
            $s->date('Poziția în catalog din ordinea veche (rangul dens al sort_order > 0)',
                'UPDATE products p
                 INNER JOIN (
                     SELECT id, DENSE_RANK() OVER (ORDER BY sort_order ASC) AS rang
                     FROM products
                     WHERE sort_order > 0
                 ) r ON r.id = p.id
                 SET p.sort_position = r.rang
                 WHERE p.sort_position IS NULL');
        });
    },
];
