<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\CautareAdmin;
use App\Support\View;

/**
 * Căutarea în tot adminul (faza D, WP-36): `GET /admin/cauta?q=`, a nucleului, pentru orice admin logat (fiecare rol vede doar
 * sursele la care are voie; App\Support\CautareAdmin). Cu `Accept: application/json` (fereastra de la Ctrl+K,
 * `public/assets/js/admin/cautare.js`) răspunde cu rezultatele în JSON; altfel cu pagina lor (fără JavaScript sau „Vezi toate
 * rezultatele”). Doar citește.
 */
final class CautareAdminController
{
    use Comun;

    public function cauta(): void
    {
        $json = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if (!Auth::check()) {
            if ($json) {
                header('Cache-Control: no-store');
                $this->jsonResponse(['eroare' => 'Sesiunea a expirat. Intră din nou în admin.'], 401);
                return;
            }
            header('Location: /admin/login');
            return;
        }
        $q = is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : '';
        $rezultat = CautareAdmin::cauta($this->db(), $q);
        if ($json) {
            header('Cache-Control: no-store');
            $this->jsonResponse($rezultat);
            return;
        }
        View::render('admin/cauta', [
            'title' => $rezultat['q'] !== '' ? 'Caută: ' . $rezultat['q'] : 'Caută în admin',
            'cautare' => $rezultat,
            'surse' => CautareAdmin::surse(),
        ], 'admin/layout');
    }
}
