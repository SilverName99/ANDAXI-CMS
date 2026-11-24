<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Răspunsul unei rute înregistrate dinainte, a cărei pistă nu e încă construită (mutarea unui site WooCommerce, pista A;
 * docs/MAGAZIN-WC.md): rutele, proprietarii lor din config/plugins.php și cheile de pe `/` (CompatWp\Interogari) sunt scrise o
 * singură dată, de pista A, iar fiecare pistă își umple doar metodele ei. Până atunci răspunsul e un 404, ca o adresă care
 * nu există: pe o pagină, 404-ul site-ului (cel capturat, pe cadrul capturat); sub `/api/` și pe cererile cu răspuns JSON,
 * același 404 scurt în JSON ca garda pluginurilor (App\Http\GardaPluginuri). Nimic nu se citește din cerere, nimic nu se scrie.
 */
trait PistaNeconstruita
{
    /** 404 pentru o pagină: pagina negăsită a site-ului (pe cadrul capturat, documentul 404 capturat). */
    protected function paginaNeconstruita(): void
    {
        (new SiteController())->paginaNegasita();
    }

    /** 404 pentru o cerere a scripturilor din pagină: JSON-ul scurt al gărzii. */
    protected function jsonNeconstruit(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['ok' => false, 'message' => 'Pagina nu a fost găsită.', 'error' => 'Pagina nu a fost găsită.'], JSON_UNESCAPED_UNICODE);
    }
}
