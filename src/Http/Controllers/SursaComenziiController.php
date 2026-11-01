<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SursaComenzii;
use App\Support\View;
use PDO;

/**
 * Rapoarte → Sursa comenzilor (WP-40, SEO partea B, B9; pluginul magazin, sub /admin/orders, deci și rolul magazinului):
 * comenzile pe săptămâni și pe surse (Google, reclamă Google, email, campanie, alt site, direct, necunoscută), numărul și
 * valoarea. Doar numere: nicio comandă, niciun client. Logica: App\Support\SursaComenzii.
 */
final class SursaComenziiController
{
    use Comun;

    public function raport(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $saptamani = max(4, min(52, (int) ($_GET['saptamani'] ?? 12)));
        View::render('admin/sursa-comenzilor', [
            'title' => 'Sursa comenzilor',
            'raport' => $db instanceof PDO ? SursaComenzii::raport($db, $saptamani) : null,
            'saptamani' => $saptamani,
            'pornita' => $db instanceof PDO ? SursaComenzii::pornita(\App\Support\Settings::all($db)) : false,
        ], 'admin/layout');
    }
}
