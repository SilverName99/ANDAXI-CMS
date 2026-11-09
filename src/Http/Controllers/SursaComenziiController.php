<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminCsrf;
use App\Support\Auth;
use App\Support\Flash;
use App\Support\Settings;
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
            'poateComuta' => Auth::isGeneralAdmin(),
        ], 'admin/layout');
    }

    /** Pornește / oprește notarea sursei pe comenzile noi (setarea `sursa_comenzii`); doar administratorul general. */
    public function comutator(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!Auth::isGeneralAdmin()) {
            http_response_code(403);
            Flash::set('error', 'Doar administratorul general poate porni sau opri sursa comenzilor.');
            header('Location: /admin/orders/sursa-comenzilor');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/orders/sursa-comenzilor');
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/orders/sursa-comenzilor');
            return;
        }
        $porneste = (string) ($_POST['pornita'] ?? '') === '1';
        Settings::save($db, ['sursa_comenzii' => $porneste ? '1' : '0']);
        Flash::set('success', $porneste
            ? 'Sursa comenzilor e pornită: comenzile noi primesc sursa (de la vizitatorii care au acceptat cookie-urile de analiză).'
            : 'Sursa comenzilor e oprită: comenzile noi nu mai primesc sursa.');
        header('Location: /admin/orders/sursa-comenzilor');
    }
}
